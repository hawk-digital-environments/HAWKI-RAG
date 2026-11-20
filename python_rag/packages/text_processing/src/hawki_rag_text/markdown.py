"""Helpers for removing converter bookkeeping from Markdown text."""

from __future__ import annotations

import re
import unicodedata
from collections.abc import Mapping
from pathlib import Path
from typing import Any


_MARKDOWN_EXTENSIONS = {".md", ".markdown"}
_METADATA_TOKENS = {
    "chunk",
    "chunks",
    "file",
    "files",
    "filename",
    "id",
    "index",
    "name",
    "next",
    "nextchunk",
    "no",
    "number",
    "page",
    "pages",
}


def should_strip_converter_markdown_noise(payload: Mapping[str, Any] | None) -> bool:
    """Return whether a document payload points at converted Markdown content."""
    if not isinstance(payload, Mapping):
        return False

    source_format = str(
        payload.get("source_format") or payload.get("format") or ""
    ).lower()
    if "markdown" in source_format:
        return True

    for key in (
        "relative_path",
        "markdown_path",
        "converted_path",
        "storage_path",
        "file_path",
    ):
        value = payload.get(key)
        if not value:
            continue
        if Path(str(value)).suffix.lower() in _MARKDOWN_EXTENSIONS:
            return True
    return False


def strip_leading_converter_markdown_noise(text: str) -> str:
    """Drop leading Markdown rows made only of converter chunk/file metadata."""
    if not text:
        return ""

    lines = text.splitlines()
    index = 0
    while index < len(lines) and not lines[index].strip():
        index += 1

    content_start = index
    metadata_rows = 0
    while index < len(lines):
        line = lines[index]
        if not line.strip():
            index += 1
            continue
        if _is_converter_metadata_separator(line):
            index += 1
            continue
        if not _is_converter_metadata_row(line):
            break
        metadata_rows += 1
        index += 1

    if metadata_rows == 0:
        return text

    while index < len(lines) and not lines[index].strip():
        index += 1

    if index == content_start:
        return text
    return "\n".join(lines[index:]).strip()


def _is_converter_metadata_row(line: str) -> bool:
    cells = _markdown_cells(line)
    if cells:
        return all(_is_metadata_cell(cell) for cell in cells)
    return _is_metadata_cell(line)


def _markdown_cells(line: str) -> list[str]:
    if "|" not in line:
        return []
    stripped = line.strip().strip("|")
    return [cell.strip() for cell in stripped.split("|") if cell.strip()]


def _is_converter_metadata_separator(line: str) -> bool:
    stripped = line.strip()
    return (
        bool(stripped) and bool(re.fullmatch(r"[\s|:-]+", stripped)) and "-" in stripped
    )


def _is_metadata_cell(value: str) -> bool:
    tokens = _metadata_tokens(value)
    return bool(tokens) and all(token in _METADATA_TOKENS for token in tokens)


def _metadata_tokens(value: str) -> list[str]:
    text = unicodedata.normalize("NFKD", value)
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = text.lower().replace("ß", "ss")
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return [token for token in text.split() if token]


__all__ = [
    "should_strip_converter_markdown_noise",
    "strip_leading_converter_markdown_noise",
]
