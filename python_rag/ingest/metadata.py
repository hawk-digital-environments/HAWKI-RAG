"""Metadata normalization helpers for crawled ingest."""
from __future__ import annotations

import hashlib
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional


def first_str(value: Any) -> Optional[str]:
    if isinstance(value, list) and value:
        value = value[0]
    if value is None:
        return None
    text = str(value).strip()
    if not text:
        return None
    if text.lower() in {"null", "none", "n/a", "undefined"}:
        return None
    return text


def resolve_date(meta: Dict[str, Any], fallback_path: Optional[Path]) -> Optional[str]:
    date = first_str(meta.get("date"))
    if not date:
        date = first_str(meta.get("published_at") or meta.get("updated_at") or meta.get("modified_at"))
    if not date:
        try:
            if fallback_path and fallback_path.exists():
                date = datetime.fromtimestamp(fallback_path.stat().st_mtime, tz=timezone.utc).isoformat()
        except Exception:
            date = None
    return date


def to_array_list(value: Any) -> List[str]:
    if isinstance(value, str):
        return [value]
    if not isinstance(value, list):
        return []
    out: List[str] = []
    for item in value:
        text = first_str(item)
        if text:
            out.append(text)
    return out


def title_from_markdown(markdown: str) -> Optional[str]:
    for line in markdown.splitlines():
        title = line.strip().lstrip("# ").strip()
        if title:
            return title[:200]
    return None


def make_doc_id(page_url: Optional[str], rel_path: str) -> str:
    base = page_url or rel_path
    return hashlib.sha1(base.encode("utf-8", errors="ignore")).hexdigest()
