"""Qdrant-backed incremental page state.

Content points already carry the stable source identity and content hash. Keeping
that data with the indexed content avoids a second Python-owned SQL registry and
lets Laravel remain the only owner of PostgreSQL metadata.
"""

from __future__ import annotations

import hashlib
from dataclasses import dataclass
from typing import Any, Mapping


@dataclass(frozen=True, slots=True)
class IndexedPageRecord:
    collection: str
    source_identity: str
    source_identity_hash: str
    canonical_url: str | None
    source_url: str | None
    doc_id: str
    source_document_id: str | None
    content_hash: str
    source_id: str | None
    task_id: str | None
    job_id: str | None
    neo4j_database: str | None
    chunks_count: int
    metadata: dict[str, Any]


class QdrantPageState:
    """Read incremental state from the content collection itself."""

    def __init__(self, qdrant: Any) -> None:
        self._qdrant = qdrant

    def find_by_source_identity(
        self,
        *,
        collection: str,
        source_identity: str,
    ) -> dict[str, Any] | None:
        if not collection or not source_identity:
            return None
        if getattr(self._qdrant, "collection", collection) != collection:
            setter = getattr(self._qdrant, "set_collection", None)
            if callable(setter):
                setter(collection)
        finder = getattr(self._qdrant, "find_points_by_payload", None)
        if not callable(finder):
            return None
        points = finder({"source_identity": source_identity}, limit=1)
        if not points or not isinstance(points[0], Mapping):
            return None
        payload = points[0].get("payload")
        return dict(payload) if isinstance(payload, Mapping) else None

    def mark_completed(self, records: list[IndexedPageRecord]) -> None:
        """No-op: successful content upserts durably stored this state."""

    def mark_seen(self, records: list[IndexedPageRecord]) -> None:
        """No-op: unchanged content points remain the authoritative state."""


def build_page_state_records(
    chunk_records: list[dict[str, Any]],
    *,
    collection: str,
    neo4j_database: str | None,
) -> list[IndexedPageRecord]:
    grouped: dict[str, list[dict[str, Any]]] = {}
    for record in chunk_records:
        grouped.setdefault(str(record.get("doc_id") or ""), []).append(record)
    records: list[IndexedPageRecord] = []
    for doc_id, chunks in grouped.items():
        record = build_page_state_record(
            doc_id=doc_id,
            records=chunks,
            collection=collection,
            neo4j_database=neo4j_database,
        )
        if record is not None:
            records.append(record)
    return records


def build_page_state_record(
    *,
    doc_id: str,
    records: list[dict[str, Any]],
    collection: str,
    neo4j_database: str | None,
) -> IndexedPageRecord | None:
    if not records:
        return None
    payload = records[0].get("payload")
    if not isinstance(payload, Mapping):
        return None
    source_identity = _text(payload.get("source_identity"))
    content_hash = _text(payload.get("content_hash"))
    if source_identity is None or content_hash is None:
        return None
    canonical_url = _text(payload.get("canonical_url") or payload.get("page_url"))
    source_url = _text(
        payload.get("page_url") or payload.get("source_url") or canonical_url
    )
    metadata = {
        key: value
        for key, value in {
            "title": _text(payload.get("title")),
            "source_format": _text(payload.get("source_format")),
            "relative_path": _text(payload.get("relative_path")),
        }.items()
        if value is not None
    }
    return IndexedPageRecord(
        collection=collection,
        source_identity=source_identity,
        source_identity_hash=hashlib.sha256(
            source_identity.encode("utf-8")
        ).hexdigest(),
        canonical_url=canonical_url,
        source_url=source_url,
        doc_id=doc_id,
        source_document_id=_text(
            payload.get("source_document_id") or payload.get("document_id")
        ),
        content_hash=content_hash,
        source_id=_text(payload.get("source_id")),
        task_id=_text(payload.get("task_id")),
        job_id=_text(payload.get("job_id") or payload.get("trace_id")),
        neo4j_database=neo4j_database,
        chunks_count=len(records),
        metadata=metadata,
    )


def _text(value: object) -> str | None:
    text = str(value or "").strip()
    return text or None


__all__ = [
    "IndexedPageRecord",
    "QdrantPageState",
    "build_page_state_record",
    "build_page_state_records",
]
