from __future__ import annotations

import logging
import uuid
from typing import Any

from application.workflows.observability import pipeline_log

logger = logging.getLogger(__name__)
_POINT_NAMESPACE = uuid.NAMESPACE_URL


def build_points(
    chunk_records: list[dict[str, Any]],
    provider: Any,
    idempotency_key: str | None = None,
) -> tuple[list[dict[str, Any]], int | None, list[dict[str, Any]]]:
    points: list[dict[str, Any]] = []
    failures: list[dict[str, Any]] = []
    vector_size: int | None = None
    for rec in chunk_records:
        payload = dict(rec["payload"])
        if idempotency_key:
            payload["idempotency_key"] = idempotency_key
        doc_id = str(rec.get("doc_id") or payload.get("doc_id") or "")
        chunk_index = payload.get("chunk_index", 0)
        try:
            vec = provider.embed(rec["content"])
        except Exception as exc:
            failure = {
                "doc_id": doc_id,
                "chunk_index": chunk_index,
                "error": str(exc),
                "source_url": payload.get("source_url") or payload.get("page_url"),
                "title": payload.get("title"),
            }
            failures.append(failure)
            pipeline_log(
                logger,
                logging.ERROR,
                stage="ingest",
                status="skipped",
                job_id=payload.get("job_id") or payload.get("trace_id"),
                doc_id=doc_id,
                pipeline_stage="embedding",
                chunk_index=chunk_index,
                error_message=f"Embedding failed: {exc}",
                source_url=payload.get("source_url") or payload.get("page_url"),
                title=payload.get("title"),
            )
            continue
        vector_size = vector_size or len(vec)
        point_key = f"{rec['doc_id']}:{chunk_index}"
        # Qdrant point IDs must be UUID or integer; use deterministic UUID per chunk.
        point_id = str(uuid.uuid5(_POINT_NAMESPACE, point_key))
        points.append({
            "id": point_id,
            "vector": vec,
            "payload": payload,
        })
    logger.debug("ingest:points built=%s failures=%s", len(points), len(failures))
    return points, vector_size, failures


def record_embedding_failures(
    doc_stats: dict[str, Any],
    points: list[dict[str, Any]],
    failures: list[dict[str, Any]],
) -> None:
    if not failures:
        return

    successful_doc_ids = {
        str((point.get("payload") or {}).get("doc_id") or "")
        for point in points
        if (point.get("payload") or {}).get("doc_id")
    }
    failed_doc_ids = {
        str(failure.get("doc_id") or "")
        for failure in failures
        if failure.get("doc_id")
    }
    fully_failed_doc_ids = failed_doc_ids - successful_doc_ids

    doc_stats["embedding_failures"] = failures
    doc_stats["embedding_failed_chunks"] = len(failures)
    doc_stats["embedding_failed_docs"] = len(failed_doc_ids)
    doc_stats["embedding_skipped_docs"] = len(fully_failed_doc_ids)

    if fully_failed_doc_ids:
        doc_stats["skipped_docs"] = int(doc_stats.get("skipped_docs") or 0) + len(fully_failed_doc_ids)
        doc_stats["processed_docs"] = max(
            0,
            int(doc_stats.get("processed_docs") or 0) - len(fully_failed_doc_ids),
        )
        doc_ids = doc_stats.get("doc_ids")
        if isinstance(doc_ids, list):
            doc_stats["doc_ids"] = [
                doc_id for doc_id in doc_ids
                if str(doc_id) not in fully_failed_doc_ids
            ]
        chunks_per_doc = doc_stats.get("chunks_per_doc")
        if isinstance(chunks_per_doc, dict):
            for doc_id in fully_failed_doc_ids:
                chunks_per_doc.pop(doc_id, None)
