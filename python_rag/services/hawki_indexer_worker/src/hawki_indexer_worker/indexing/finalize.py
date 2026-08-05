"""Final response and summary persistence for ingestion workflows."""

from __future__ import annotations

import logging
import time
from pathlib import Path
from typing import Any

from hawki_indexer_worker.indexing.summary import (
    build_summary,
    write_graph_preview,
    write_ingest_summary,
)
from hawki_indexer_worker.indexing.observability import pipeline_log


def build_success_ingest_response(
    *,
    body: Any,
    doc_stats: dict[str, Any],
    total_chunks: int,
    batch_size: int,
    collection: str,
    points_count: int,
    graph_preview: dict[str, Any] | None,
    qdrant_ms: float | None,
    neo4j_ms: float | None,
    started_at: float,
    public_dir: Path,
    job_id: str | None,
    operation_id: str | None,
    logger_obj: logging.Logger,
) -> dict[str, Any]:
    """Build, persist, and return the successful ingestion response."""

    total_ms = (time.perf_counter() - started_at) * 1000
    summary = build_summary(
        doc_stats,
        total_chunks=total_chunks,
        batch_size=batch_size,
        collection=collection,
        graph_enabled=bool(body.graph),
        qdrant_ms=qdrant_ms,
        neo4j_ms=neo4j_ms,
        total_ms=total_ms,
    )
    if bool(body.graph) and graph_preview:
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
        logger_obj,
        logging.INFO,
        stage="ingest",
        status="success",
        job_id=job_id,
        idempotency_key=operation_id,
        processed_docs=doc_stats["processed_docs"],
        skipped_docs=doc_stats["skipped_docs"],
        points=points_count,
        total_chunks=total_chunks,
        elapsed_ms=round(total_ms, 2),
    )
    logger_obj.info("ingest:done points=%s total_ms=%.2f", points_count, total_ms)
    return {
        "ok": True,
        "points": points_count,
        "summary": summary,
        "graph_only": bool(getattr(body, "graph_only", False)),
    }


__all__ = ["build_success_ingest_response"]
