"""Pure helpers for chunked LightRAG doc-status storage."""

from __future__ import annotations

import re
from collections.abc import Callable, Iterable, Sequence
from typing import Any, TypeVar


_RecordT = TypeVar("_RecordT")


def chunk_file_sort_key(path: str) -> int:
    """Sort chunk files by the numeric suffix in ``*_chunk_N.json``."""

    match = re.search(r"_chunk_(\d+)\.json$", path)
    return int(match.group(1)) if match else 10**9


def sort_chunk_files(paths: Iterable[str]) -> list[str]:
    """Return chunk files sorted in write/read order."""

    return sorted(paths, key=chunk_file_sort_key)


def merge_chunk_payloads(
    paths: Iterable[str],
    load_json_fn: Callable[[str], object],
) -> dict[str, Any]:
    """Load and merge JSON object payloads from chunk files."""

    merged: dict[str, Any] = {}
    for path in sort_chunk_files(paths):
        payload = load_json_fn(path) or {}
        if isinstance(payload, dict):
            merged.update(payload)
    return merged


def chunk_item_dicts(
    items: Sequence[tuple[str, Any]],
    *,
    max_entries: int,
) -> list[dict[str, Any]]:
    """Split status items into dictionaries suitable for chunk files."""

    if not items:
        return [{}]
    size = max(1, int(max_entries))
    return [dict(items[start : start + size]) for start in range(0, len(items), size)]


def is_duplicate_doc_record(
    doc_id: str,
    doc: object,
    *,
    failed_status_value: str,
) -> bool:
    """Detect duplicate document status records that LightRAG stores as failed."""

    if not isinstance(doc, dict):
        return False
    metadata = doc.get("metadata")
    if isinstance(metadata, dict) and metadata.get("is_duplicate") is True:
        return True
    if isinstance(doc_id, str) and doc_id.startswith("dup-"):
        return True
    if str(doc.get("status") or "") != failed_status_value:
        return False
    error_msg = str(doc.get("error_msg") or "")
    return "Content already exists." in error_msg and "Original doc_id:" in error_msg


def annotate_duplicate_skip_metadata(
    doc_id: str,
    doc: _RecordT,
    *,
    failed_status_value: str,
) -> _RecordT:
    """Mark duplicate records as effective skipped while preserving raw status."""

    if not is_duplicate_doc_record(doc_id, doc, failed_status_value=failed_status_value):
        return doc
    if not isinstance(doc, dict):
        return doc
    metadata = doc.get("metadata")
    if not isinstance(metadata, dict):
        metadata = {}
    metadata.setdefault("is_duplicate", True)
    metadata.setdefault("effective_status", "skipped")
    metadata.setdefault("skip_reason", "duplicate")
    doc["metadata"] = metadata
    return doc


def count_status_records(
    records: Iterable[tuple[str, object]],
    *,
    status_values: Iterable[str],
    failed_status_value: str,
    duplicate_count_key: str,
) -> dict[str, int]:
    """Count doc statuses while excluding duplicate attempts from FAILED."""

    counts = {status_value: 0 for status_value in status_values}
    counts[duplicate_count_key] = 0
    for doc_id, doc in records:
        if is_duplicate_doc_record(str(doc_id), doc, failed_status_value=failed_status_value):
            counts[duplicate_count_key] += 1
            continue
        status_val = str((doc or {}).get("status") or "") if isinstance(doc, dict) else ""
        if status_val in counts:
            counts[status_val] += 1
        elif status_val:
            counts[status_val] = counts.get(status_val, 0) + 1
    return counts


__all__ = [
    "annotate_duplicate_skip_metadata",
    "chunk_file_sort_key",
    "chunk_item_dicts",
    "count_status_records",
    "is_duplicate_doc_record",
    "merge_chunk_payloads",
    "sort_chunk_files",
]
