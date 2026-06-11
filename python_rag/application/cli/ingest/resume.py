"""Resume-state and retry helpers for crawled ingest."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any, Dict, Iterable, Iterator, List, Optional, Set


def batched(iterable: Iterable[Any], size: int) -> Iterator[list[Any]]:
    buffer: list[Any] = []
    for item in iterable:
        buffer.append(item)
        if len(buffer) >= size:
            yield buffer
            buffer = []
    if buffer:
        yield buffer


def safe_state_filename(key: str) -> str:
    digest = hashlib.sha1(key.encode("utf-8", errors="ignore")).hexdigest()
    return f"{digest}.json"


def load_resume_state(path: Path) -> set[str]:
    if not path.exists():
        return set()
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return set()
    doc_ids = data.get("doc_ids", [])
    return {str(doc_id) for doc_id in doc_ids if isinstance(doc_id, (str, int))}


def save_resume_state_payload(
    path: Path,
    *,
    doc_ids: set[str],
    metadata: dict[str, Any],
    updated_at: str,
) -> None:
    payload = {
        "doc_ids": sorted(doc_ids),
        "updated_at": updated_at,
    }
    payload.update(metadata)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def should_split_batch(error: Optional[str]) -> bool:
    if not error:
        return False
    lowered = error.lower()
    retry_markers = [
        "timed out",
        "timeout",
        "read timed out",
        "502",
        "503",
        "504",
        "bad gateway",
        "gateway",
        "service unavailable",
    ]
    return any(marker in lowered for marker in retry_markers)
