"""Dry-run response assembly for ingestion workflows."""

from __future__ import annotations

import logging
from collections.abc import Callable
from pathlib import Path
from typing import Any

from hawki_indexer_worker.indexing.graph_prepare import (
    append_graph_failures,
    build_triplets_by_doc,
    graph_failure_log_path,
)
from hawki_indexer_worker.indexing.request import apply_provider_overrides
from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
from hawki_indexer_worker.indexing.summary import (
    build_graph_preview,
    build_summary,
    write_graph_preview,
)
from hawki_indexer_worker.indexing.observability import pipeline_log


def build_dry_run_ingest_response(
    *,
    body: Any,
    doc_stats: dict[str, Any],
    chunk_records: list[dict[str, Any]],
    total_chunks: int,
    batch_size: int,
    collection: str,
    rag_service: Any,
    get_provider: Callable[[str], Any],
    public_dir: Path,
    job_id: str | None,
    operation_id: str | None,
    graph_debug: bool,
    graph_settings: GraphIngestSettings,
    logger_obj: logging.Logger,
) -> dict[str, Any]:
    """Build the full dry-run ingestion response without mutating vector/graph stores."""

    summary = build_summary(
        doc_stats,
        total_chunks=total_chunks,
        batch_size=batch_size,
        collection=collection,
        graph_enabled=bool(body.graph),
        estimate_only=True,
    )
    if bool(body.graph) and getattr(body, "dry_include_graph", False):
        _attach_graph_preview(
            summary,
            body=body,
            doc_stats=doc_stats,
            chunk_records=chunk_records,
            rag_service=rag_service,
            get_provider=get_provider,
            public_dir=public_dir,
            job_id=job_id,
            operation_id=operation_id,
            graph_debug=graph_debug,
            graph_settings=graph_settings,
            logger_obj=logger_obj,
        )

    pipeline_log(
        logger_obj,
        logging.INFO,
        stage="ingest",
        status="success",
        job_id=job_id,
        idempotency_key=operation_id,
        processed_docs=doc_stats["processed_docs"],
        skipped_docs=doc_stats["skipped_docs"],
        total_chunks=total_chunks,
        dry_run=True,
    )
    return {"ok": True, "dry_run": True, "summary": summary}


def _attach_graph_preview(
    summary: dict[str, Any],
    *,
    body: Any,
    doc_stats: dict[str, Any],
    chunk_records: list[dict[str, Any]],
    rag_service: Any,
    get_provider: Callable[[str], Any],
    public_dir: Path,
    job_id: str | None,
    operation_id: str | None,
    graph_debug: bool,
    graph_settings: GraphIngestSettings,
    logger_obj: logging.Logger,
) -> None:
    provider = get_provider(body.provider)
    apply_provider_overrides(provider, body)
    triplets_by_doc, failures = build_triplets_by_doc(
        chunk_records,
        body.graph_engine,
        rag_service,
        provider,
        neo4j_database=getattr(body, "neo4j_database", None),
        public_dir=public_dir,
        graph_debug=graph_debug,
        graph_settings=graph_settings,
        request_id=operation_id,
    )
    graph_preview = build_graph_preview(doc_stats, chunk_records, triplets_by_doc)
    summary["graph_preview"] = graph_preview
    preview_path = write_graph_preview(graph_preview, public_dir)
    if preview_path:
        summary["graph_preview_file"] = str(preview_path)
    if failures:
        _record_graph_failures(
            summary,
            public_dir=public_dir,
            failures=failures,
            job_id=job_id,
            operation_id=operation_id,
            graph_settings=graph_settings,
            logger_obj=logger_obj,
        )


def _record_graph_failures(
    summary: dict[str, Any],
    *,
    public_dir: Path,
    failures: list[dict[str, Any]],
    job_id: str | None,
    operation_id: str | None,
    graph_settings: GraphIngestSettings,
    logger_obj: logging.Logger,
) -> None:
    failure_path = graph_failure_log_path(
        public_dir, failure_log_path=graph_settings.graph_failure_log
    )
    append_graph_failures(failure_path, failures)
    summary["graph_failures"] = len(failures)
    summary["graph_failures_file"] = str(failure_path)
    for failure in failures:
        pipeline_log(
            logger_obj,
            logging.WARNING,
            stage="ingest",
            status="partial",
            job_id=job_id,
            idempotency_key=operation_id,
            doc_id=failure.get("doc_id"),
            pipeline_stage="graph_extract",
            error_message=failure.get("error"),
            file_path=failure.get("file_path"),
        )


__all__ = ["build_dry_run_ingest_response"]
