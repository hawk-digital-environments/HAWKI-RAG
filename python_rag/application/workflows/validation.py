from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, List, Mapping, Tuple


URL_KEYS = ("source_url", "page_url", "original_url", "url")
PATH_KEYS = ("file_path", "converted_path", "storage_path", "original_path")


def validate_ingest_document(doc: object) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []

    doc_id = getattr(doc, "id", None)
    text = getattr(doc, "text", None)
    payload = getattr(doc, "payload", None)

    if doc_id is None or str(doc_id).strip() == "":
        errors.append("doc id is missing.")

    if not isinstance(text, str):
        errors.append("document text is not readable.")
    elif text.strip() == "":
        errors.append("document text is empty.")

    if payload is None:
        payload = {}
    if not isinstance(payload, Mapping):
        errors.append("document payload must be an object.")
        return errors, warnings

    if not _first_present(payload, URL_KEYS):
        warnings.append("metadata URL is missing.")

    if not _first_present(payload, ("title",)):
        warnings.append("metadata title is missing.")

    file_path = _first_present(payload, PATH_KEYS)
    if file_path and not Path(str(file_path)).exists():
        warnings.append(f"metadata file path does not exist: {file_path}")

    return errors, warnings


def normalize_ingest_metadata(doc: object) -> dict[str, Any]:
    payload = dict(getattr(doc, "payload", None) or {})
    doc_id = str(getattr(doc, "id", ""))

    if not payload.get("title"):
        payload["title"] = _title_from_payload(payload) or doc_id or "Untitled document"

    url = _first_present(payload, URL_KEYS)
    if url:
        payload.setdefault("source_url", str(url))
        payload.setdefault("page_url", str(url))

    return payload


def _first_present(payload: Mapping[str, Any], keys: tuple[str, ...]) -> object | None:
    for key in keys:
        value = payload.get(key)
        if isinstance(value, str):
            value = value.strip()
        if value not in (None, ""):
            return value
    return None


def _title_from_payload(payload: Mapping[str, Any]) -> str | None:
    for key in ("original_filename", "converted_relative_path", "file_path", "converted_path", "storage_path"):
        value = payload.get(key)
        if not value:
            continue
        name = Path(str(value)).stem.strip()
        if name:
            return name
    return None
