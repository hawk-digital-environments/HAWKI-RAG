"""Storage handoff helpers for Markdown ingestion activities."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any, Iterable

from common.converter_markdown import strip_leading_converter_markdown_noise


SCRAPER_BOOKKEEPING_FILENAMES = frozenset({
    "completed_urls.json",
    "crawler.log",
    "job_state.json",
    "sitemap.json",
    "summary.json",
    "urls_index.json",
})


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


def list_conversion_input_files(raw_dir: str) -> list[Path]:
    """Return real source files while excluding crawler bookkeeping artifacts."""

    if is_object_prefix(raw_dir):
        raise RuntimeError("s3:// raw prefixes require an object-storage adapter in this deployment.")

    root = Path(raw_dir.removeprefix("file://")).expanduser().resolve()
    if not root.exists() or not root.is_dir():
        raise RuntimeError(f"Raw directory was not found: {root}")

    looks_like_crawler_output = any((root / name).is_file() for name in SCRAPER_BOOKKEEPING_FILENAMES)
    return [
        path
        for path in sorted(root.rglob("*"))
        if path.is_file()
        and (not looks_like_crawler_output or path.name not in SCRAPER_BOOKKEEPING_FILENAMES)
    ]


def select_markdown_files(markdown_dir: str, selected_files: Iterable[str] | None) -> list[str]:
    """Validate converter-selected Markdown paths before ingestion."""

    if selected_files is None:
        return list_markdown_files(markdown_dir)

    if is_object_prefix(markdown_dir):
        raise RuntimeError("s3:// Markdown reads require an object-storage adapter in this deployment.")

    root = Path(markdown_dir.removeprefix("file://")).expanduser().resolve()
    selected: list[str] = []
    seen: set[str] = set()
    for value in selected_files:
        if not isinstance(value, str) or not value.strip():
            continue
        path = Path(value.removeprefix("file://")).expanduser().resolve()
        try:
            path.relative_to(root)
        except ValueError as exc:
            raise RuntimeError(f"Converter-selected Markdown path escaped its output directory: {path}") from exc
        if not path.is_file() or path.suffix.lower() not in {".md", ".markdown"}:
            continue
        normalized = str(path)
        if normalized not in seen:
            seen.add(normalized)
            selected.append(normalized)
    return sorted(selected)


def read_text_file(path: str) -> str:
    if is_object_prefix(path):
        raise RuntimeError("s3:// Markdown reads require an object-storage adapter in this deployment.")

    local_path = Path(path)
    text = local_path.read_text(encoding="utf-8")
    if local_path.suffix.lower() in {".md", ".markdown"}:
        return strip_leading_converter_markdown_noise(text)
    return text


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
    "SCRAPER_BOOKKEEPING_FILENAMES",
    "is_object_prefix",
    "list_conversion_input_files",
    "list_markdown_files",
    "read_text_file",
    "select_markdown_files",
    "sha256_text",
    "stable_document_id",
    "write_manifest",
]
