"""Ingestion orchestration for vector + graph pipelines."""
from __future__ import annotations

import logging
import os
import time
from pathlib import Path
from typing import Any, Dict, List

from fastapi import HTTPException  # type: ignore[reportMissingImports]

from graph.neo4j_graph import Neo4jGraph
from pipeline.observability import pipeline_log
from pipeline.ingest.chunking import prepare_documents
from pipeline.ingest.deletion import delete_document_entries
from pipeline.ingest.graph_ingest import (
    append_graph_failures,
    build_triplets_by_doc,
    graph_failure_log_path,
)
from pipeline.ingest.request import apply_provider_overrides, infer_job_id
from pipeline.ingest.summary import (
    build_graph_preview,
    build_summary,
    write_graph_preview,
    write_ingest_summary,
)
from pipeline.ingest.vector_ingest import build_points, record_embedding_failures
from vectorstore.qdrant_http import QdrantHTTP

logger = logging.getLogger(__name__)


def _env_bool(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name, "")
    if not raw.strip():
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")


def ingest_documents(
    body: Any,
    *,
    rag_service: Any,
    get_provider,
    public_dir: Path,
    graph_debug: bool | None = None,
) -> Dict[str, Any]:
    resolved_graph_debug = graph_debug
    if resolved_graph_debug is None:
        resolved_graph_debug = _env_bool("GRAPH_DEBUG")

    dry_run = bool(body.dry_run)
    docs = list(getattr(body, "docs", []) or [])
    run_job_id = infer_job_id(body, docs)
    pipeline_log(
        logger,
        logging.INFO,
        stage="ingest",
        status="started",
        job_id=run_job_id,
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

    qdrant = QdrantHTTP()
    if body.collection:
        qdrant.collection = body.collection

    chunk_records, doc_stats = prepare_documents(
        docs,
        chunk_chars=body.chunk_chars,
        chunk_overlap=body.chunk_overlap,
        default_job_id=run_job_id,
        graph_debug=resolved_graph_debug,
    )
    total_chunks = len(chunk_records)

    if total_chunks == 0:
        pipeline_log(
            logger,
            logging.ERROR,
            stage="ingest",
            status="failed",
            job_id=run_job_id,
            error_message="No valid content to ingest",
            validation_failures=doc_stats.get("validation_failures", []),
            skipped_docs=doc_stats["skipped_docs"],
        )
        raise HTTPException(400, detail="No valid content to ingest")
    logger.info("ingest:prepared docs=%s chunks=%s", doc_stats["processed_docs"], total_chunks)

    batch_size = max(1, int(body.batch_size or 64))

    if dry_run:
        summary = _build_summary_payload(
            doc_stats,
            total_chunks=total_chunks,
            batch_size=batch_size,
            collection=qdrant.collection,
            graph_enabled=bool(body.graph),
            estimate_only=True,
        )
        if body.graph and getattr(body, "dry_include_graph", False):
            provider = get_provider(body.provider)
            apply_provider_overrides(provider, body)
            triplets_by_doc, failures = build_triplets_by_doc(
                chunk_records,
                body.graph_engine,
                rag_service,
                provider,
                neo4j_database=getattr(body, "neo4j_database", None),
                public_dir=public_dir,
            )
            graph_preview = build_graph_preview(doc_stats, chunk_records, triplets_by_doc)
            summary["graph_preview"] = graph_preview
            preview_path = write_graph_preview(graph_preview, public_dir)
            if preview_path:
                summary["graph_preview_file"] = str(preview_path)
            if failures:
                failure_path = graph_failure_log_path(public_dir)
                append_graph_failures(failure_path, failures)
                summary["graph_failures"] = len(failures)
                summary["graph_failures_file"] = str(failure_path)
                for failure in failures:
                    pipeline_log(
                        logger,
                        logging.WARNING,
                        stage="ingest",
                        status="partial",
                        job_id=run_job_id,
                        doc_id=failure.get("doc_id"),
                        pipeline_stage="graph_extract",
                        error_message=failure.get("error"),
                        file_path=failure.get("file_path"),
                    )
        pipeline_log(
            logger,
            logging.INFO,
            stage="ingest",
            status="success",
            job_id=run_job_id,
            processed_docs=doc_stats["processed_docs"],
            skipped_docs=doc_stats["skipped_docs"],
            total_chunks=total_chunks,
            dry_run=True,
        )
        return {"ok": True, "dry_run": True, "summary": summary}

    provider = None
    points: List[Dict[str, Any]] = []
    vector_size: int | None = None
    qdrant_ms = None
    qdrant_write_start = None
    start = time.perf_counter()

    if body.graph or not getattr(body, "graph_only", False):
        provider = get_provider(body.provider)
        apply_provider_overrides(provider, body)

    if not getattr(body, "graph_only", False):
        logger.info("ingest:provider=%s embed_model=%s batch_size=%s", body.provider, getattr(provider, "embed_model", None), batch_size)
        points, vector_size, embedding_failures = build_points(chunk_records, provider)
        if embedding_failures:
            record_embedding_failures(doc_stats, points, embedding_failures)
            pipeline_log(
                logger,
                logging.WARNING,
                stage="ingest",
                status="partial",
                job_id=run_job_id,
                pipeline_stage="embedding",
                points=len(points),
                failed_chunks=len(embedding_failures),
                failed_docs=doc_stats.get("embedding_failed_docs", 0),
            )
        if not points:
            pipeline_log(
                logger,
                logging.ERROR,
                stage="ingest",
                status="failed",
                job_id=run_job_id,
                pipeline_stage="embedding",
                error_message="Embedding failed for every prepared chunk.",
                embedding_failures=embedding_failures,
            )
            raise HTTPException(status_code=500, detail="Embedding failed for every prepared chunk")
        pipeline_log(
            logger,
            logging.INFO,
            stage="ingest",
            status="success",
            job_id=run_job_id,
            pipeline_stage="embedding",
            points=len(points),
            vector_size=vector_size,
        )
        logger.info("ingest:qdrant points=%s vector_size=%s", len(points), vector_size)
        qdrant.ensure_collection(vector_size or 1024, distance=body.distance)
        qdrant_write_start = time.perf_counter()
        qdrant.upsert_points(points, batch_size=batch_size)
        qdrant_ms = (time.perf_counter() - qdrant_write_start) * 1000
        pipeline_log(
            logger,
            logging.INFO,
            stage="ingest",
            status="success",
            job_id=run_job_id,
            pipeline_stage="index_vector",
            points=len(points),
            elapsed_ms=round(qdrant_ms, 2),
            collection=qdrant.collection,
        )
        logger.info("ingest:qdrant upserted=%s ms=%.2f", len(points), qdrant_ms)

    if qdrant_write_start is not None:
        qdrant_ms = (time.perf_counter() - qdrant_write_start) * 1000

    neo4j_ms = None
    graph_preview = None
    if body.graph:
        graph = Neo4jGraph(database=getattr(body, "neo4j_database", None))
        try:
            graph_write_start = time.perf_counter()
            triplet_start = time.perf_counter()
            triplets_by_doc, failures = _extract_triplets_from_chunks(
                chunk_records=chunk_records,
                graph_engine=body.graph_engine,
                rag_service=rag_service,
                provider=provider,
                graph_debug=resolved_graph_debug,
                neo4j_database=getattr(body, "neo4j_database", None),
                public_dir=public_dir,
                graph=graph,
            )
            triplet_ms = (time.perf_counter() - triplet_start) * 1000
            total_triplets = sum(len(v) for v in triplets_by_doc.values())
            logger.info(
                "graph:extract done engine=%s triplets=%s docs=%s ms=%.2f",
                body.graph_engine,
                total_triplets,
                len(triplets_by_doc),
                triplet_ms,
            )
            graph_preview = build_graph_preview(doc_stats, chunk_records, triplets_by_doc)
            neo4j_ms = (time.perf_counter() - graph_write_start) * 1000
            logger.info("graph:neo4j upsert docs=%s triplets=%s ms=%.2f", len(triplets_by_doc), total_triplets, neo4j_ms)
            if failures:
                failure_path = graph_failure_log_path(public_dir)
                append_graph_failures(failure_path, failures)
                for failure in failures:
                    pipeline_log(
                        logger,
                        logging.WARNING,
                        stage="ingest",
                        status="partial",
                        job_id=run_job_id,
                        doc_id=failure.get("doc_id"),
                        pipeline_stage="graph_extract",
                        error_message=failure.get("error"),
                        file_path=failure.get("file_path"),
                    )
                logger.info("graph:failures count=%s file=%s", len(failures), failure_path)
        finally:
            try:
                graph.close()
            except Exception:
                pass

    total_ms = (time.perf_counter() - start) * 1000

    summary = _build_summary_payload(
        doc_stats,
        total_chunks=total_chunks,
        batch_size=batch_size,
        collection=qdrant.collection,
        graph_enabled=bool(body.graph),
        qdrant_ms=qdrant_ms,
        neo4j_ms=neo4j_ms,
        total_ms=total_ms,
    )
    if body.graph and graph_preview:
        summary["graph_preview"] = graph_preview
        preview_path = write_graph_preview(graph_preview, public_dir)
        if preview_path:
            summary["graph_preview_file"] = str(preview_path)

    try:
        summary_path = write_ingest_summary(summary, public_dir)
        summary["summary_file"] = str(summary_path)
    except Exception as exc:
        summary["summary_file_error"] = str(exc)

    pipeline_log(
        logger,
        logging.INFO,
        stage="ingest",
        status="success",
        job_id=run_job_id,
        processed_docs=doc_stats["processed_docs"],
        skipped_docs=doc_stats["skipped_docs"],
        points=len(points),
        total_chunks=total_chunks,
        elapsed_ms=round(total_ms, 2),
    )
    logger.info("ingest:done points=%s total_ms=%.2f", len(points), total_ms)
    return {"ok": True, "points": len(points), "summary": summary, "graph_only": bool(getattr(body, "graph_only", False))}


def _extract_triplets_from_chunks(
    chunk_records: List[Dict[str, Any]],
    *,
    graph_engine: str,
    rag_service: Any,
    provider: Any | None,
    neo4j_database: str | None,
    public_dir: Path,
    graph_debug: bool,
    graph: Any | None = None,
) -> tuple[Dict[str, List[tuple[str, str, str]]], List[Dict[str, Any]]]:
    return build_triplets_by_doc(
        chunk_records,
        graph_engine,
        rag_service,
        provider,
        graph_debug=graph_debug,
        graph=graph,
        neo4j_database=neo4j_database,
        public_dir=public_dir,
    )


def _build_summary_payload(
    doc_stats: Dict[str, Any],
    *,
    total_chunks: int,
    batch_size: int,
    collection: str,
    graph_enabled: bool,
    estimate_only: bool = False,
    qdrant_ms: float | None = None,
    neo4j_ms: float | None = None,
    total_ms: float | None = None,
) -> Dict[str, Any]:
    return build_summary(
        doc_stats,
        total_chunks=total_chunks,
        batch_size=batch_size,
        collection=collection,
        graph_enabled=graph_enabled,
        estimate_only=estimate_only,
        qdrant_ms=qdrant_ms,
        neo4j_ms=neo4j_ms,
        total_ms=total_ms,
    )


def delete_document(doc_id: str) -> Dict[str, Any]:
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    return delete_document_entries(doc_id)
