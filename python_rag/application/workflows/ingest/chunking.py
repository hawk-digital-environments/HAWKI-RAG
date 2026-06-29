"""Document normalization and chunk preparation for ingestion."""
from __future__ import annotations

import logging
from typing import Any, Dict, List, Optional, Tuple

from application.workflows.observability import pipeline_log
from application.workflows.validation import normalize_ingest_metadata, validate_ingest_document
from application.workflows.ingest.incremental import (
    content_hash_for_text,
    normalized_page_url,
    stable_document_id_from_payload,
)
from application.workflows.ingest.models import IngestChunkRecord, IngestDocumentStats
from common.converter_markdown import (
    should_strip_converter_markdown_noise,
    strip_leading_converter_markdown_noise,
)
from common.text_preprocessor import ensure_tags, split_text

logger = logging.getLogger(__name__)


def doc_job_id(default_job_id: str | None, doc: Any) -> str | None:
    payload = getattr(doc, "payload", None) or {}
    if isinstance(payload, dict):
        return str(payload.get("job_id") or payload.get("trace_id") or default_job_id or "") or None
    return default_job_id


def prepare_documents(
    docs: list[Any],
    *,
    chunk_chars: int,
    chunk_overlap: int,
    default_job_id: str | None,
    graph_debug: bool = False,
) -> tuple[list[IngestChunkRecord], IngestDocumentStats]:
    chunk_records: list[IngestChunkRecord] = []
    doc_stats: IngestDocumentStats = {
        "total_docs": len(docs),
        "processed_docs": 0,
        "skipped_docs": 0,
        "by_format": {},
        "doc_ids": [],
        "validation_failures": [],
        "validation_warnings": [],
    }

    for d in docs:
        source_doc_id = str(getattr(d, "id", ""))
        doc_id = source_doc_id
        current_doc_job_id = doc_job_id(default_job_id, d)
        errors, warnings = validate_ingest_document(d)
        if errors:
            message = "; ".join(errors)
            doc_stats["skipped_docs"] += 1
            doc_stats["validation_failures"].append({"doc_id": source_doc_id, "errors": errors})
            pipeline_log(
                logger,
                logging.WARNING,
                stage="ingest",
                status="skipped",
                job_id=current_doc_job_id,
                doc_id=source_doc_id,
                error_message=message,
                reason="validation_failed",
                errors=errors,
            )
            continue

        normalized_payload = normalize_ingest_metadata(d)
        if warnings:
            doc_stats["validation_warnings"].append({"doc_id": doc_id, "warnings": warnings})
            pipeline_log(
                logger,
                logging.WARNING,
                stage="ingest",
                status="partial",
                job_id=current_doc_job_id,
                doc_id=doc_id,
                warnings=warnings,
                title=normalized_payload.get("title"),
                source_url=normalized_payload.get("source_url") or normalized_payload.get("page_url"),
            )

        document_text = d.text
        if should_strip_converter_markdown_noise(normalized_payload):
            document_text = strip_leading_converter_markdown_noise(document_text)

        if not str(normalized_payload.get("content_hash") or "").strip():
            normalized_payload["content_hash"] = content_hash_for_text(document_text)
        stable_doc_id, source_identity = stable_document_id_from_payload(normalized_payload, source_doc_id)
        doc_id = stable_doc_id
        if source_doc_id and source_doc_id != doc_id:
            normalized_payload.setdefault("source_document_id", source_doc_id)
        if source_identity:
            normalized_payload["source_identity"] = source_identity
        canonical_url = normalized_page_url(normalized_payload)
        if canonical_url:
            normalized_payload.setdefault("canonical_url", canonical_url)
        if current_doc_job_id:
            normalized_payload.setdefault("job_id", current_doc_job_id)

        chunks = split_text(document_text, chunk_chars, chunk_overlap) or [document_text]
        logger.debug("ingest:doc %s chunks=%s", doc_id, len(chunks))
        if graph_debug:
            logger.debug("ingest:doc %s text_len=%s", doc_id, len(document_text or ""))
        doc_processed = False
        fmt: Optional[str] = None
        chunk_count = 0

        for idx, ch in enumerate(chunks):
            if not isinstance(ch, str) or not ch.strip():
                continue
            payload = dict(normalized_payload)
            payload.update({
                "content": ch,
                "chunk_index": idx,
                "source_format": payload.get("source_format", "text"),
            })
            payload["doc_id"] = doc_id
            payload.setdefault("component_type", "chunk")
            ensure_tags(payload, ch)
            chunk_records.append({
                "doc_id": doc_id,
                "content": ch,
                "payload": payload,
            })
            doc_processed = True
            chunk_count += 1
            if not fmt:
                fmt = payload.get("source_format") or "unknown"

        if doc_processed:
            doc_stats["processed_docs"] += 1
            doc_stats["doc_ids"].append(doc_id)
            fmt_key = fmt or "unknown"
            by_fmt = doc_stats["by_format"]
            by_fmt[fmt_key] = by_fmt.get(fmt_key, 0) + 1
            if chunk_count:
                chunks_map = doc_stats.setdefault("chunks_per_doc", {})
                chunks_map[doc_id] = chunk_count
            pipeline_log(
                logger,
                logging.INFO,
                stage="ingest",
                status="success",
                job_id=current_doc_job_id,
                doc_id=doc_id,
                chunks=chunk_count,
                source_format=fmt_key,
                title=normalized_payload.get("title"),
                source_url=normalized_payload.get("source_url") or normalized_payload.get("page_url"),
            )
        else:
            doc_stats["skipped_docs"] += 1
            pipeline_log(
                logger,
                logging.WARNING,
                stage="ingest",
                status="skipped",
                job_id=current_doc_job_id,
                doc_id=doc_id,
                error_message="No non-empty chunks generated.",
                reason="empty_chunks",
            )

    doc_stats["total_chunks"] = len(chunk_records)
    return chunk_records, doc_stats
