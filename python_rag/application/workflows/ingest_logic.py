"""Ingestion orchestration for vector + graph pipelines."""
from __future__ import annotations

import logging
import time
from pathlib import Path
from typing import Any

from fastapi import HTTPException

from application.workflows.observability import pipeline_log
from application.workflows.ingest.chunking import prepare_documents
from application.workflows.ingest.deletion import DocumentDeletionResult, delete_document_entries
from application.workflows.ingest.dependencies import IngestWorkflowDependencies
from application.workflows.ingest.dry_run import build_dry_run_ingest_response
from application.workflows.ingest.finalize import build_success_ingest_response
from application.workflows.ingest.graph_commit import (
    GraphScopeMismatchError,
    commit_graph_triplets,
    validate_and_stamp_chunk_scope,
)
from application.workflows.ingest.incremental import plan_incremental_ingest
from application.workflows.ingest.page_registry import build_page_registry_records
from application.workflows.ingest.request import apply_provider_overrides, infer_job_id, infer_operation_id
from application.workflows.ingest.vector_commit import commit_vector_points
from infrastructure.graph.neo4j_requests import normalize_graph_write_scope

logger = logging.getLogger(__name__)


def ingest_documents(
    body: Any,
    *,
    rag_service: Any,
    get_provider,
    public_dir: Path,
    idempotency_key: str | None = None,
    graph_debug: bool | None = None,
    dependencies: IngestWorkflowDependencies | None = None,
) -> dict[str, Any]:
    dependencies = dependencies or IngestWorkflowDependencies()
    graph_settings = dependencies.graph_settings_loader()
    resolved_graph_debug = graph_debug
    if resolved_graph_debug is None:
        resolved_graph_debug = graph_settings.graph_debug

    dry_run = bool(body.dry_run)
    docs = list(getattr(body, "docs", []) or [])
    run_job_id = infer_job_id(body, docs)
    operation_id = infer_operation_id(
        body,
        docs=docs,
        fallback=idempotency_key or run_job_id,
    )
    pipeline_log(
        logger,
        logging.INFO,
        stage="ingest",
        status="started",
        job_id=run_job_id,
        idempotency_key=operation_id,
        total_docs=len(docs),
        dry_run=dry_run,
        graph=bool(body.graph),
        graph_only=bool(getattr(body, "graph_only", False)),
        collection=body.collection,
        neo4j_database=getattr(body, "neo4j_database", None),
    )
    logger.info(
        "ingest:start docs=%s dry_run=%s graph=%s graph_only=%s collection=%s neo4j_database=%s",
        len(docs),
        dry_run,
        bool(body.graph),
        bool(getattr(body, "graph_only", False)),
        body.collection,
        getattr(body, "neo4j_database", None),
    )
    if resolved_graph_debug:
        logger.debug(
            "ingest:options provider=%s graph_engine=%s chunk_chars=%s chunk_overlap=%s batch_size=%s distance=%s",
            getattr(body, "provider", None),
            getattr(body, "graph_engine", None),
            getattr(body, "chunk_chars", None),
            getattr(body, "chunk_overlap", None),
            getattr(body, "batch_size", None),
            getattr(body, "distance", None),
        )

    qdrant = dependencies.qdrant_factory()
    if body.collection:
        if hasattr(qdrant, "set_collection"):
            qdrant.set_collection(body.collection)
        else:
            qdrant.collection = body.collection

    chunk_records, doc_stats = prepare_documents(
        docs,
        chunk_chars=body.chunk_chars,
        chunk_overlap=body.chunk_overlap,
        default_job_id=run_job_id,
        graph_debug=resolved_graph_debug,
    )
    if body.graph:
        write_scope = normalize_graph_write_scope(
            getattr(body, "dataset_id", None),
            getattr(body, "neo4j_namespace", None),
        )
        if write_scope is not None:
            try:
                validate_and_stamp_chunk_scope(
                    chunk_records,
                    dataset_id=write_scope[0],
                    neo4j_namespace=write_scope[1],
                )
            except GraphScopeMismatchError as exc:
                raise HTTPException(status_code=400, detail=str(exc)) from exc
    total_chunks = len(chunk_records)

    if total_chunks == 0:
        pipeline_log(
            logger,
            logging.ERROR,
            stage="ingest",
            status="failed",
            job_id=run_job_id,
            idempotency_key=operation_id,
            error_message="No valid content to ingest",
            validation_failures=doc_stats.get("validation_failures", []),
            skipped_docs=doc_stats["skipped_docs"],
        )
        raise HTTPException(400, detail="No valid content to ingest")
    logger.info("ingest:prepared docs=%s chunks=%s", doc_stats["processed_docs"], total_chunks)

    batch_size = max(1, int(body.batch_size or 64))

    if dry_run:
        return build_dry_run_ingest_response(
            body=body,
            doc_stats=doc_stats,
            chunk_records=chunk_records,
            total_chunks=total_chunks,
            batch_size=batch_size,
            collection=qdrant.collection,
            rag_service=rag_service,
            get_provider=get_provider,
            public_dir=public_dir,
            job_id=run_job_id,
            operation_id=operation_id,
            graph_debug=resolved_graph_debug,
            graph_settings=graph_settings,
            logger_obj=logger,
        )

    start = time.perf_counter()
    replace_doc_ids: set[str] = set()
    replace_doc_ids_by_doc: dict[str, set[str]] = {}
    unchanged_page_records: list[Any] = []
    page_registry = None

    try:
        page_registry = dependencies.page_registry_factory()
    except Exception as exc:
        logger.warning("ingest:page registry unavailable: %s", exc)

    if not getattr(body, "graph_only", False):
        incremental_plan = plan_incremental_ingest(
            chunk_records,
            doc_stats=doc_stats,
            qdrant=qdrant,
            collection=qdrant.collection,
            neo4j_database=getattr(body, "neo4j_namespace", None),
            page_registry=page_registry,
            operation_id=operation_id,
            logger_obj=logger,
        )
        chunk_records = incremental_plan.chunk_records
        replace_doc_ids = incremental_plan.replace_doc_ids
        replace_doc_ids_by_doc = incremental_plan.replace_doc_ids_by_doc
        unchanged_page_records = incremental_plan.unchanged_page_records
        total_chunks = len(chunk_records)
        if total_chunks == 0 and int(doc_stats.get("incremental_unchanged_docs") or 0) > 0:
            _mark_registry_seen(page_registry, unchanged_page_records, logger_obj=logger)
            pipeline_log(
                logger,
                logging.INFO,
                stage="ingest",
                status="success",
                job_id=run_job_id,
                idempotency_key=operation_id,
                pipeline_stage="incremental",
                skipped_docs=doc_stats.get("incremental_unchanged_docs"),
                skipped_chunks=doc_stats.get("incremental_unchanged_chunks"),
            )
            return build_success_ingest_response(
                body=body,
                doc_stats=doc_stats,
                total_chunks=total_chunks,
                batch_size=batch_size,
                collection=qdrant.collection,
                points_count=0,
                graph_preview=None,
                qdrant_ms=0.0,
                neo4j_ms=None,
                started_at=start,
                public_dir=public_dir,
                job_id=run_job_id,
                operation_id=operation_id,
                logger_obj=logger,
            )
        if total_chunks == 0:
            pipeline_log(
                logger,
                logging.ERROR,
                stage="ingest",
                status="failed",
                job_id=run_job_id,
                idempotency_key=operation_id,
                error_message="No valid content to ingest after incremental filtering",
                skipped_docs=doc_stats["skipped_docs"],
            )
            raise HTTPException(400, detail="No valid content to ingest")

    provider = None
    points: list[dict[str, Any]] = []
    qdrant_ms = None

    if body.graph or not getattr(body, "graph_only", False):
        provider = get_provider(body.provider)
        apply_provider_overrides(provider, body)

    if not getattr(body, "graph_only", False):
        vector_result = commit_vector_points(
            body=body,
            chunk_records=chunk_records,
            doc_stats=doc_stats,
            provider=provider,
            qdrant=qdrant,
            batch_size=batch_size,
            job_id=run_job_id,
            operation_id=operation_id,
            replace_doc_ids=replace_doc_ids,
            logger_obj=logger,
        )
        points = vector_result.points
        qdrant_ms = vector_result.qdrant_ms

    neo4j_ms = None
    graph_preview = None
    if body.graph:
        graph_result = commit_graph_triplets(
            body=body,
            chunk_records=chunk_records,
            doc_stats=doc_stats,
            rag_service=rag_service,
            provider=provider,
            graph_factory=dependencies.graph_factory,
            public_dir=public_dir,
            job_id=run_job_id,
            operation_id=operation_id,
            graph_debug=resolved_graph_debug,
            graph_settings=graph_settings,
            logger_obj=logger,
            replace_doc_ids_by_doc=replace_doc_ids_by_doc,
        )
        graph_preview = graph_result.graph_preview
        neo4j_ms = graph_result.neo4j_ms

    if not getattr(body, "graph_only", False):
        completed_page_records = build_page_registry_records(
            chunk_records,
            collection=qdrant.collection,
            neo4j_database=getattr(body, "neo4j_namespace", None),
        )
        _mark_registry_completed(page_registry, completed_page_records, logger_obj=logger)
        _mark_registry_seen(page_registry, unchanged_page_records, logger_obj=logger)

    return build_success_ingest_response(
        body=body,
        doc_stats=doc_stats,
        total_chunks=total_chunks,
        batch_size=batch_size,
        collection=qdrant.collection,
        points_count=len(points),
        graph_preview=graph_preview,
        qdrant_ms=qdrant_ms,
        neo4j_ms=neo4j_ms,
        started_at=start,
        public_dir=public_dir,
        job_id=run_job_id,
        operation_id=operation_id,
        logger_obj=logger,
    )


def delete_document(
    doc_id: str,
    *,
    idempotency_key: str | None = None,
    collection: str | None = None,
    neo4j_namespace: str | None = None,
) -> DocumentDeletionResult:
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    return delete_document_entries(
        doc_id,
        idempotency_key=idempotency_key,
        collection=collection,
        neo4j_namespace=neo4j_namespace,
    )


def _mark_registry_completed(
    page_registry: Any | None,
    records: list[Any],
    *,
    logger_obj: logging.Logger,
) -> None:
    if page_registry is None or not records:
        return
    try:
        page_registry.mark_completed(records)
    except Exception as exc:
        logger_obj.warning("ingest:page registry completed update failed records=%s error=%s", len(records), exc)


def _mark_registry_seen(
    page_registry: Any | None,
    records: list[Any],
    *,
    logger_obj: logging.Logger,
) -> None:
    if page_registry is None or not records:
        return
    try:
        page_registry.mark_seen(records)
    except Exception as exc:
        logger_obj.warning("ingest:page registry seen update failed records=%s error=%s", len(records), exc)
