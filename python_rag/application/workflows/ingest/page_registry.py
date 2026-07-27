"""Postgres-backed registry for already ingested website pages."""
from __future__ import annotations

import hashlib
import json
import logging
from contextlib import contextmanager
from dataclasses import dataclass
from typing import Any, Iterator, Mapping

from temporal_rag.settings import TemporalRagSettings

logger = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True)
class IngestedPageRecord:
    collection: str
    source_identity: str
    source_identity_hash: str
    canonical_url: str | None
    canonical_url_hash: str | None
    source_url: str | None
    doc_id: str
    source_document_id: str | None
    content_hash: str
    source_id: str | None
    task_id: str | None
    job_id: str | None
    qdrant_collection: str | None
    neo4j_database: str | None
    chunks_count: int
    metadata: dict[str, Any]


class IngestedPageRegistry:
    """Read/write adapter for Laravel's `ingested_pages` registry table."""

    def __init__(self, settings: TemporalRagSettings) -> None:
        self.settings = settings

    @classmethod
    def from_env(cls) -> "IngestedPageRegistry":
        return cls(TemporalRagSettings.from_env())

    @contextmanager
    def connection(self) -> Iterator[Any]:
        try:
            import psycopg
        except ModuleNotFoundError as exc:
            raise RuntimeError("psycopg is required for ingested page registry access.") from exc

        conn = psycopg.connect(
            host=self.settings.db_host,
            port=self.settings.db_port,
            dbname=self.settings.db_name,
            user=self.settings.db_user,
            password=self.settings.db_password,
            options="-c timezone=UTC",
            autocommit=True,
        )
        try:
            yield conn
        finally:
            conn.close()

    def find_by_source_identity(self, *, collection: str, source_identity: str) -> dict[str, Any] | None:
        if not collection or not source_identity:
            return None

        try:
            with self.connection() as conn:
                with conn.cursor() as cur:
                    cur.execute(
                        """
                        select doc_id, content_hash, status, canonical_url, source_url
                          from ingested_pages
                         where collection = %s
                           and source_identity_hash = %s
                         limit 1
                        """,
                        (collection, _sha256(source_identity)),
                    )
                    row = cur.fetchone()
        except Exception as exc:
            logger.warning("ingested_pages:lookup unavailable collection=%s error=%s", collection, exc)
            return None

        if not row:
            return None

        doc_id, content_hash, status, canonical_url, source_url = row
        if str(status or "") != "completed":
            return None
        return {
            "doc_id": str(doc_id or ""),
            "content_hash": str(content_hash or ""),
            "status": str(status or ""),
            "canonical_url": canonical_url,
            "source_url": source_url,
        }

    def mark_completed(self, records: list[IngestedPageRecord]) -> None:
        self._upsert_records(records, mark_ingested=True)

    def mark_seen(self, records: list[IngestedPageRecord]) -> None:
        self._upsert_records(records, mark_ingested=False)

    def _upsert_records(self, records: list[IngestedPageRecord], *, mark_ingested: bool) -> None:
        clean_records = [record for record in records if record.collection and record.source_identity]
        if not clean_records:
            return

        try:
            with self.connection() as conn:
                with conn.cursor() as cur:
                    for record in clean_records:
                        cur.execute(_UPSERT_SQL, _upsert_params(record, mark_ingested=mark_ingested))
        except Exception as exc:
            logger.warning(
                "ingested_pages:upsert unavailable count=%s mark_ingested=%s error=%s",
                len(clean_records),
                mark_ingested,
                exc,
            )


def build_page_registry_records(
    chunk_records: list[dict[str, Any]],
    *,
    collection: str,
    neo4j_database: str | None,
) -> list[IngestedPageRecord]:
    grouped: dict[str, list[dict[str, Any]]] = {}
    for record in chunk_records:
        grouped.setdefault(str(record.get("doc_id") or ""), []).append(record)

    out: list[IngestedPageRecord] = []
    for doc_id, records in grouped.items():
        page_record = build_page_registry_record(
            doc_id=doc_id,
            records=records,
            collection=collection,
            neo4j_database=neo4j_database,
        )
        if page_record is not None:
            out.append(page_record)
    return out


def build_page_registry_record(
    *,
    doc_id: str,
    records: list[dict[str, Any]],
    collection: str,
    neo4j_database: str | None,
) -> IngestedPageRecord | None:
    if not records:
        return None
    payload = records[0].get("payload") or {}
    if not isinstance(payload, Mapping):
        return None

    source_identity = str(payload.get("source_identity") or "").strip()
    content_hash = str(payload.get("content_hash") or "").strip()
    if not source_identity or not content_hash:
        return None

    canonical_url = _nullable_string(payload.get("canonical_url") or payload.get("page_url"))
    source_url = _nullable_string(payload.get("page_url") or payload.get("source_url") or canonical_url)
    metadata = {
        "title": _nullable_string(payload.get("title")),
        "source_format": _nullable_string(payload.get("source_format")),
        "relative_path": _nullable_string(payload.get("relative_path")),
    }
    metadata = {key: value for key, value in metadata.items() if value is not None}

    return IngestedPageRecord(
        collection=collection,
        source_identity=source_identity,
        source_identity_hash=_sha256(source_identity),
        canonical_url=canonical_url,
        canonical_url_hash=_sha256(canonical_url) if canonical_url else None,
        source_url=source_url,
        doc_id=doc_id,
        source_document_id=_nullable_string(payload.get("source_document_id") or payload.get("document_id")),
        content_hash=content_hash,
        source_id=_nullable_string(payload.get("source_id")),
        task_id=_nullable_string(payload.get("task_id")),
        job_id=_nullable_string(payload.get("job_id") or payload.get("trace_id")),
        qdrant_collection=collection,
        neo4j_database=neo4j_database,
        chunks_count=len(records),
        metadata=metadata,
    )


def _nullable_string(value: object) -> str | None:
    if value is None:
        return None
    text = str(value).strip()
    return text or None


def _sha256(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def _upsert_params(record: IngestedPageRecord, *, mark_ingested: bool) -> tuple[Any, ...]:
    return (
        record.collection,
        record.source_identity_hash,
        record.source_identity,
        record.canonical_url_hash,
        record.canonical_url,
        record.source_url,
        record.doc_id,
        record.source_document_id,
        record.content_hash,
        "completed",
        record.source_id,
        record.task_id,
        record.job_id,
        record.qdrant_collection,
        record.neo4j_database,
        int(record.chunks_count),
        mark_ingested,
        json.dumps(record.metadata),
        mark_ingested,
    )


_UPSERT_SQL = """
insert into ingested_pages (
    collection,
    source_identity_hash,
    source_identity,
    canonical_url_hash,
    canonical_url,
    source_url,
    doc_id,
    source_document_id,
    content_hash,
    status,
    source_id,
    task_id,
    job_id,
    qdrant_collection,
    neo4j_database,
    chunks_count,
    last_seen_at,
    last_ingested_at,
    metadata,
    created_at,
    updated_at
)
values (
    %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
    now(),
    case when %s then now() else now() end,
    %s::json,
    now(),
    now()
)
on conflict (collection, source_identity_hash) do update
   set source_identity = excluded.source_identity,
       canonical_url_hash = excluded.canonical_url_hash,
       canonical_url = excluded.canonical_url,
       source_url = excluded.source_url,
       doc_id = excluded.doc_id,
       source_document_id = excluded.source_document_id,
       content_hash = excluded.content_hash,
       status = excluded.status,
       source_id = coalesce(excluded.source_id, ingested_pages.source_id),
       task_id = coalesce(excluded.task_id, ingested_pages.task_id),
       job_id = coalesce(excluded.job_id, ingested_pages.job_id),
       qdrant_collection = excluded.qdrant_collection,
       neo4j_database = excluded.neo4j_database,
       chunks_count = excluded.chunks_count,
       last_seen_at = now(),
       last_ingested_at = case
           when %s then now()
           else coalesce(ingested_pages.last_ingested_at, excluded.last_ingested_at)
       end,
       metadata = (coalesce(ingested_pages.metadata::jsonb, '{}'::jsonb) || excluded.metadata::jsonb)::json,
       updated_at = now()
"""


__all__ = [
    "IngestedPageRecord",
    "IngestedPageRegistry",
    "build_page_registry_record",
    "build_page_registry_records",
]
