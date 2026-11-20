"""Whole-document ingestion semantics for retries and completion tracking."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from hawki_indexer_worker.indexing.artifact_identity import has_artifact_identity


def requires_complete_document(payload: Mapping[str, Any] | None) -> bool:
    """True when partial writes are unacceptable for this payload.

    Direct-text documents and source/path artifacts must always be retried
    and completed as a whole; matched URL pages may be updated partially.
    """

    if not isinstance(payload, Mapping):
        return False
    return payload.get("ingestion_mode") == "direct_text" or has_artifact_identity(
        payload
    )


def complete_document_ids(chunk_records: list[dict[str, Any]]) -> set[str]:
    """Doc ids whose documents require whole-document completion."""

    return {
        str(record.get("doc_id") or "")
        for record in chunk_records
        if requires_complete_document(record.get("payload") or {})
    }


def artifact_document_ids(chunk_records: list[dict[str, Any]]) -> set[str]:
    """Doc ids whose documents are source/path artifacts."""

    return {
        str(record.get("doc_id") or "")
        for record in chunk_records
        if has_artifact_identity(record.get("payload") or {})
    }


__all__ = [
    "artifact_document_ids",
    "complete_document_ids",
    "requires_complete_document",
]
