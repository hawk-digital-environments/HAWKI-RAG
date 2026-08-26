"""Incremental ingestion planning helpers."""

from __future__ import annotations

import hashlib
import logging
from collections import OrderedDict
from dataclasses import dataclass, field
from typing import Any, Mapping
from urllib.parse import urlsplit, urlunsplit

from hawki_indexer_worker.indexing.page_state import (
    IndexedPageRecord,
    build_page_state_record,
)

PAGE_URL_KEYS = ("canonical_url", "page_url", "original_url", "url")
URL_LOOKUP_KEYS = ("canonical_url", "page_url", "original_url", "url", "source_url")
PATH_IDENTITY_KEYS = (
    "relative_path",
    "file_path",
    "converted_path",
    "storage_path",
    "original_path",
)


@dataclass(slots=True)
class IncrementalIngestPlan:
    """Decision output for skipping unchanged docs and replacing changed docs."""

    chunk_records: list[dict[str, Any]]
    new_doc_ids: set[str] = field(default_factory=set)
    changed_doc_ids: set[str] = field(default_factory=set)
    unchanged_doc_ids: set[str] = field(default_factory=set)
    replace_doc_ids: set[str] = field(default_factory=set)
    replace_doc_ids_by_doc: dict[str, set[str]] = field(default_factory=dict)
    unchanged_page_records: list[IndexedPageRecord] = field(default_factory=list)
    unchanged_chunks: int = 0


def stable_document_id_from_payload(
    payload: Mapping[str, Any], fallback_doc_id: str
) -> tuple[str, str | None]:
    """Return a durable doc id for website pages and the identity key used."""

    identity_key = page_identity_key(payload)
    if not identity_key:
        return str(fallback_doc_id), None
    digest = hashlib.sha256(identity_key.encode("utf-8", errors="ignore")).hexdigest()[
        :40
    ]
    return f"doc_{digest}", identity_key


def page_identity_key(payload: Mapping[str, Any]) -> str | None:
    """Build a stable page identity while preserving query strings."""

    relative_identity = _first_present(payload, PATH_IDENTITY_KEYS)
    source_url = _normalize_http_url(_first_present(payload, ("source_url",)))

    for key in PAGE_URL_KEYS:
        value = _normalize_http_url(payload.get(key))
        if not value:
            continue
        if relative_identity and source_url and key == "url" and value == source_url:
            return f"url-path:{value}|{relative_identity}"
        return f"url:{value}"

    if source_url:
        if relative_identity:
            return f"url-path:{source_url}|{relative_identity}"
        return f"url:{source_url}"

    return None


def normalized_page_url(payload: Mapping[str, Any]) -> str | None:
    for key in (*PAGE_URL_KEYS, "source_url"):
        value = _normalize_http_url(payload.get(key))
        if value:
            return value
    return None


def content_hash_for_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def plan_incremental_ingest(
    chunk_records: list[dict[str, Any]],
    *,
    doc_stats: dict[str, Any],
    qdrant: Any,
    collection: str,
    operation_id: str | None,
    logger_obj: logging.Logger,
    neo4j_database: str | None = None,
    page_registry: Any | None = None,
) -> IncrementalIngestPlan:
    """Skip unchanged docs and mark changed docs for page-scoped replacement."""

    grouped: OrderedDict[str, list[dict[str, Any]]] = OrderedDict()
    for rec in chunk_records:
        grouped.setdefault(str(rec.get("doc_id") or ""), []).append(rec)

    kept: list[dict[str, Any]] = []
    plan = IncrementalIngestPlan(chunk_records=kept)
    chunks_per_doc = doc_stats.get("chunks_per_doc")
    if not isinstance(chunks_per_doc, dict):
        chunks_per_doc = {}
    registry_hits = 0

    for doc_id, records in grouped.items():
        payload = dict(records[0].get("payload") or {})
        content_hash = str(payload.get("content_hash") or "")
        existing_payload = _find_registry_payload(
            page_registry,
            collection=collection,
            payload=payload,
        )
        if existing_payload:
            registry_hits += 1
        if not existing_payload:
            existing_payload = _find_existing_payload(qdrant, doc_id, payload)
        existing_doc_id = (
            str(existing_payload.get("doc_id") or "") if existing_payload else ""
        )
        existing_hash = (
            str(existing_payload.get("content_hash") or "") if existing_payload else ""
        )

        if existing_payload and existing_hash and existing_hash == content_hash:
            plan.unchanged_doc_ids.add(doc_id)
            plan.unchanged_chunks += len(records)
            page_record = build_page_state_record(
                doc_id=doc_id,
                records=records,
                collection=collection,
                neo4j_database=neo4j_database,
            )
            if page_record is not None:
                plan.unchanged_page_records.append(page_record)
            _mark_doc_skipped(doc_stats, doc_id, payload, len(records))
            continue

        kept.extend(records)
        if existing_payload:
            plan.changed_doc_ids.add(doc_id)
            delete_ids = {doc_id}
            if existing_doc_id:
                delete_ids.add(existing_doc_id)
            plan.replace_doc_ids.update(delete_ids)
            plan.replace_doc_ids_by_doc[doc_id] = delete_ids
        else:
            plan.new_doc_ids.add(doc_id)

    doc_stats["incremental_new_docs"] = len(plan.new_doc_ids)
    doc_stats["incremental_changed_docs"] = len(plan.changed_doc_ids)
    doc_stats["incremental_unchanged_docs"] = len(plan.unchanged_doc_ids)
    doc_stats["incremental_unchanged_chunks"] = plan.unchanged_chunks
    doc_stats["incremental_replacement_doc_ids"] = sorted(plan.replace_doc_ids)
    doc_stats["incremental_registry_hits"] = registry_hits
    doc_stats["total_chunks"] = len(kept)

    logger_obj.info(
        "ingest:incremental new=%s changed=%s unchanged=%s registry_hits=%s kept_chunks=%s skipped_chunks=%s operation_id=%s",
        len(plan.new_doc_ids),
        len(plan.changed_doc_ids),
        len(plan.unchanged_doc_ids),
        registry_hits,
        len(kept),
        plan.unchanged_chunks,
        operation_id or "-",
    )
    return plan


def _find_registry_payload(
    page_registry: Any | None,
    *,
    collection: str,
    payload: Mapping[str, Any],
) -> dict[str, Any] | None:
    if page_registry is None:
        return None
    source_identity = str(payload.get("source_identity") or "").strip()
    if not source_identity:
        return None
    try:
        record = page_registry.find_by_source_identity(
            collection=collection,
            source_identity=source_identity,
        )
    except Exception:
        raise
    if isinstance(record, dict):
        return record
    return None


def _mark_doc_skipped(
    doc_stats: dict[str, Any],
    doc_id: str,
    payload: Mapping[str, Any],
    chunk_count: int,
) -> None:
    doc_stats["processed_docs"] = max(0, int(doc_stats.get("processed_docs") or 0) - 1)
    doc_stats["skipped_docs"] = int(doc_stats.get("skipped_docs") or 0) + 1

    doc_ids = doc_stats.get("doc_ids")
    if isinstance(doc_ids, list):
        doc_stats["doc_ids"] = [value for value in doc_ids if str(value) != doc_id]

    chunks_per_doc = doc_stats.get("chunks_per_doc")
    if isinstance(chunks_per_doc, dict):
        chunks_per_doc.pop(doc_id, None)

    by_format = doc_stats.get("by_format")
    source_format = str(payload.get("source_format") or "unknown")
    if isinstance(by_format, dict) and source_format in by_format:
        remaining = int(by_format.get(source_format) or 0) - 1
        if remaining > 0:
            by_format[source_format] = remaining
        else:
            by_format.pop(source_format, None)

    skipped = doc_stats.setdefault("incremental_skipped_documents", [])
    if isinstance(skipped, list):
        skipped.append(
            {
                "doc_id": doc_id,
                "chunks": chunk_count,
                "reason": "unchanged_content_hash",
                "source_url": payload.get("page_url") or payload.get("source_url"),
            }
        )


def _find_existing_payload(
    qdrant: Any, doc_id: str, payload: Mapping[str, Any]
) -> dict[str, Any] | None:
    for filters in _lookup_filters(doc_id, payload):
        try:
            points = qdrant.find_points_by_payload(filters, limit=1)
        except Exception:
            raise
        if not points:
            continue
        point = points[0] if isinstance(points[0], dict) else {}
        point_payload = point.get("payload") if isinstance(point, dict) else None
        if isinstance(point_payload, dict):
            return point_payload
    return None


def _lookup_filters(doc_id: str, payload: Mapping[str, Any]) -> list[dict[str, Any]]:
    filters: list[dict[str, Any]] = [{"doc_id": str(doc_id)}]
    seen = {tuple(filters[0].items())}

    source_identity = payload.get("source_identity")
    if source_identity:
        _append_filter(filters, seen, {"source_identity": str(source_identity)})

    relative_identity = _first_present(payload, PATH_IDENTITY_KEYS)
    for key in URL_LOOKUP_KEYS:
        raw = _first_present(payload, (key,))
        if not raw:
            continue
        values = [str(raw)]
        normalized = _normalize_http_url(raw)
        if normalized and normalized not in values:
            values.append(normalized)
        for value in values:
            if key == "source_url" and relative_identity:
                _append_filter(
                    filters, seen, {key: value, "relative_path": relative_identity}
                )
                continue
            _append_filter(filters, seen, {key: value})

    return filters


def _append_filter(
    filters: list[dict[str, Any]],
    seen: set[tuple[tuple[str, str], ...]],
    filter_body: dict[str, Any],
) -> None:
    key = tuple(sorted((str(k), str(v)) for k, v in filter_body.items()))
    if key in seen:
        return
    seen.add(key)
    filters.append(filter_body)


def _first_present(payload: Mapping[str, Any], keys: tuple[str, ...]) -> str | None:
    for key in keys:
        value = payload.get(key)
        if isinstance(value, list):
            value = value[0] if value else None
        if value is None:
            continue
        text = str(value).strip()
        if text:
            return text
    return None


def _normalize_http_url(value: object) -> str | None:
    if value is None:
        return None
    text = str(value).strip()
    if not text:
        return None
    parts = urlsplit(text)
    if parts.scheme.lower() not in {"http", "https"} or not parts.netloc:
        return None

    scheme = parts.scheme.lower()
    netloc = parts.netloc.lower()
    if scheme == "http" and netloc.endswith(":80"):
        netloc = netloc[:-3]
    if scheme == "https" and netloc.endswith(":443"):
        netloc = netloc[:-4]
    path = parts.path or ""
    if path != "/":
        path = path.rstrip("/")
    else:
        path = ""
    return urlunsplit((scheme, netloc, path, parts.query, ""))


__all__ = [
    "IncrementalIngestPlan",
    "content_hash_for_text",
    "normalized_page_url",
    "page_identity_key",
    "plan_incremental_ingest",
    "stable_document_id_from_payload",
]
