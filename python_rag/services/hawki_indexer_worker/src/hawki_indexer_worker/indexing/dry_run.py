"""Dry-run response assembly for ingestion workflows."""

from __future__ import annotations

import logging
from collections.abc import Callable
from typing import Any

from hawki_indexer_worker.indexing.graph_prepare import build_triplets_by_doc
from hawki_indexer_worker.indexing.request import apply_provider_overrides
from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
from hawki_indexer_worker.indexing.summary import (
    build_graph_preview,
    build_summary,
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
    graph_preview = None
    graph_failures: list[dict[str, Any]] = []
    if bool(body.graph) and getattr(body, "dry_include_graph", False):
        graph_preview, graph_failures = _attach_graph_preview(
            summary,
            body=body,
            doc_stats=doc_stats,
            chunk_records=chunk_records,
            rag_service=rag_service,
            get_provider=get_provider,
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
    return {
        "ok": True,
        "dry_run": True,
        "summary": summary,
        "graph_preview": graph_preview,
        "graph_failures": graph_failures,
    }


def _attach_graph_preview(
    summary: dict[str, Any],
    *,
    body: Any,
    doc_stats: dict[str, Any],
    chunk_records: list[dict[str, Any]],
    rag_service: Any,
    get_provider: Callable[[str], Any],
    job_id: str | None,
    operation_id: str | None,
    graph_debug: bool,
    graph_settings: GraphIngestSettings,
    logger_obj: logging.Logger,
) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    provider = get_provider(body.provider)
    apply_provider_overrides(provider, body)
    triplets_by_doc, failures = build_triplets_by_doc(
        chunk_records,
        body.graph_engine,
        rag_service,
        provider,
        neo4j_database=getattr(body, "neo4j_database", None),
        graph_debug=graph_debug,
        graph_settings=graph_settings,
        request_id=operation_id,
    )
    graph_preview = build_graph_preview(doc_stats, chunk_records, triplets_by_doc)
    summary["graph_preview"] = graph_preview
    if failures:
        _record_graph_failures(
            summary,
            failures=failures,
            job_id=job_id,
            operation_id=operation_id,
            logger_obj=logger_obj,
        )
    return graph_preview, failures


def _record_graph_failures(
    summary: dict[str, Any],
    *,
    failures: list[dict[str, Any]],
    job_id: str | None,
    operation_id: str | None,
    logger_obj: logging.Logger,
) -> None:
    summary["graph_failures"] = len(failures)
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
