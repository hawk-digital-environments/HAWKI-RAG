"""Storage handoff helpers for Markdown ingestion activities."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any, Iterable


def is_object_prefix(path: str) -> bool:
    return path.startswith("s3://")


def list_markdown_files(markdown_dir: str) -> list[str]:
    if is_object_prefix(markdown_dir):
        raise RuntimeError("s3:// Markdown prefixes require an object-storage adapter in this deployment.")

    root = Path(markdown_dir.removeprefix("file://")).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        raise RuntimeError(f"Markdown directory was not found: {root}")

    paths = [
        path
        for path in root.rglob("*")
        if path.is_file() and path.suffix.lower() in {".md", ".markdown"}
    ]
    return [str(path) for path in sorted(paths)]


def read_text_file(path: str) -> str:
    if is_object_prefix(path):
        raise RuntimeError("s3:// Markdown reads require an object-storage adapter in this deployment.")

    return Path(path).read_text(encoding="utf-8")


def stable_document_id(source_id: str, markdown_file: str, markdown_root: str) -> str:
    relative = str(Path(markdown_file).resolve().relative_to(Path(markdown_root).resolve()))
    digest = hashlib.sha256(f"{source_id}|{relative}".encode("utf-8")).hexdigest()[:40]
    return f"doc_{digest}"


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def write_manifest(manifest_path: str, records: Iterable[dict[str, Any]]) -> None:
    if is_object_prefix(manifest_path):
        return

    path = Path(manifest_path.removeprefix("file://")).expanduser().resolve()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(list(records), indent=2, sort_keys=True) + "\n", encoding="utf-8")


__all__ = [
    "is_object_prefix",
    "list_markdown_files",
    "read_text_file",
    "sha256_text",
    "stable_document_id",
    "write_manifest",
]
