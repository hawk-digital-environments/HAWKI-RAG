"""Vector embedding and Qdrant commit phase for ingestion."""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Any

from fastapi import HTTPException

from application.workflows.ingest.vector_ingest import build_points, record_embedding_failures
from application.workflows.observability import pipeline_log


@dataclass(slots=True)
class VectorCommitResult:
    """Result of embedding prepared chunks and writing them to Qdrant."""

    points: list[dict[str, Any]]
    vector_size: int | None
    qdrant_ms: float


def commit_vector_points(
    *,
    body: Any,
    chunk_records: list[dict[str, Any]],
    doc_stats: dict[str, Any],
    provider: Any,
    qdrant: Any,
    batch_size: int,
    job_id: str | None,
    operation_id: str | None,
    replace_doc_ids: set[str] | None = None,
    logger_obj: logging.Logger,
) -> VectorCommitResult:
    """Embed prepared chunks and commit them to the configured Qdrant collection."""

    logger_obj.info(
        "ingest:provider=%s embed_model=%s batch_size=%s",
        body.provider,
        getattr(provider, "embed_model", None),
        batch_size,
    )
    points, vector_size, embedding_failures = build_points(
        chunk_records,
        provider,
        idempotency_key=operation_id,
    )
    if embedding_failures:
        record_embedding_failures(doc_stats, points, embedding_failures)
        pipeline_log(
            logger_obj,
            logging.WARNING,
            stage="ingest",
            status="partial",
            job_id=job_id,
            idempotency_key=operation_id,
            pipeline_stage="embedding",
            points=len(points),
            failed_chunks=len(embedding_failures),
            failed_docs=doc_stats.get("embedding_failed_docs", 0),
        )
    if not points:
        pipeline_log(
            logger_obj,
            logging.ERROR,
            stage="ingest",
            status="failed",
            job_id=job_id,
            idempotency_key=operation_id,
            pipeline_stage="embedding",
            error_message="Embedding failed for every prepared chunk.",
            embedding_failures=embedding_failures,
        )
        raise HTTPException(status_code=500, detail="Embedding failed for every prepared chunk")

    pipeline_log(
        logger_obj,
        logging.INFO,
        stage="ingest",
        status="success",
        job_id=job_id,
        idempotency_key=operation_id,
        pipeline_stage="embedding",
        points=len(points),
        vector_size=vector_size,
    )
    logger_obj.info("ingest:qdrant points=%s vector_size=%s", len(points), vector_size)
    qdrant.ensure_collection(vector_size or 1024, distance=body.distance)
    qdrant_write_start = time.perf_counter()
    for doc_id in sorted(replace_doc_ids or set()):
        if not doc_id:
            continue
        if hasattr(qdrant, "delete_by_doc_id"):
            qdrant.delete_by_doc_id(
                doc_id,
                idempotency_key=f"{operation_id}:replace:{doc_id}" if operation_id else None,
            )
        else:
            logger_obj.warning("ingest:qdrant replace skipped; client has no delete_by_doc_id doc=%s", doc_id)
    qdrant.upsert_points(
        points,
        batch_size=batch_size,
        idempotency_key=operation_id,
    )
    qdrant_ms = (time.perf_counter() - qdrant_write_start) * 1000
    pipeline_log(
        logger_obj,
        logging.INFO,
        stage="ingest",
        status="success",
        job_id=job_id,
        idempotency_key=operation_id,
        pipeline_stage="index_vector",
        points=len(points),
        replaced_docs=len(replace_doc_ids or set()),
        elapsed_ms=round(qdrant_ms, 2),
        collection=qdrant.collection,
    )
    logger_obj.info("ingest:qdrant upserted=%s replaced_docs=%s ms=%.2f", len(points), len(replace_doc_ids or set()), qdrant_ms)
    return VectorCommitResult(points=points, vector_size=vector_size, qdrant_ms=qdrant_ms)


__all__ = ["VectorCommitResult", "commit_vector_points"]
