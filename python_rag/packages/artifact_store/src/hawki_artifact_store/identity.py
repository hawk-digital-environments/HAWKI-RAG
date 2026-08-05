"""Pure identity rules shared by converter and indexer workers."""

from __future__ import annotations

import hashlib
from pathlib import Path


def sha256_text(text: str) -> str:
    """Return the lowercase SHA-256 digest of UTF-8 text."""

    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def document_id(source_id: str, relative_path: str | Path) -> str:
    """Build a stable document ID from a source and POSIX-relative path."""

    path = Path(relative_path)
    if path.is_absolute() or not path.parts or ".." in path.parts:
        raise ValueError("relative_path must stay within the artifact directory")
    if not source_id:
        raise ValueError("source_id must not be empty")

    normalized_path = path.as_posix()
    digest = hashlib.sha256(
        f"{source_id}|{normalized_path}".encode("utf-8")
    ).hexdigest()[:40]
    return f"doc_{digest}"
