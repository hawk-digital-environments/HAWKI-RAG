"""Page material discovery and extraction helpers."""
from __future__ import annotations

import os
import re
from pathlib import Path
from typing import Any, Dict, Optional, Tuple

try:
    from ingest.url_maps import read_json_file
except ImportError:
    from url_maps import read_json_file

import logging

logger = logging.getLogger(__name__)


def read_text_file(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        try:
            return path.read_text(errors="ignore")
        except Exception:
            return ""


def looks_like_path_list(text: str) -> bool:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    if len(lines) < 3:
        return False
    pathish = 0
    for line in lines:
        if line.startswith(("/", "\\\\")) or re.match(r"^[A-Za-z]:\\\\", line):
            pathish += 1
            continue
        lowered = line.lower()
        if "/app/shared/" in lowered or "/var/www/" in lowered:
            pathish += 1
            continue
        if lowered.endswith((".pdf", ".doc", ".docx", ".ppt", ".pptx")):
            pathish += 1
    return (pathish / max(len(lines), 1)) >= 0.6


def _pick_json_meta(dir_path: Path) -> Tuple[Optional[Path], Dict[str, Any]]:
    json_files = [f for f in dir_path.iterdir() if f.is_file() and f.suffix.lower() == ".json"]
    json_files = [f for f in json_files if f.name != "conversion_meta.json"]
    if not json_files:
        return None, {}

    preferred_names = ("page.json", "metadata.json", "meta.json", "page_meta.json")
    for name in preferred_names:
        for f in json_files:
            if f.name.lower() == name:
                data = read_json_file(f)
                return f, data if isinstance(data, dict) else {}

    best_file = None
    best_data: Dict[str, Any] = {}
    for f in json_files:
        data = read_json_file(f)
        if not isinstance(data, dict):
            continue
        if any(key in data for key in ("url", "page_url", "title", "content", "text")):
            return f, data
        if best_file is None:
            best_file = f
            best_data = data
    return best_file, best_data


def _pick_conversion_meta(dir_path: Path) -> Tuple[Optional[Path], Dict[str, Any]]:
    path = dir_path / "conversion_meta.json"
    if not path.is_file():
        return None, {}
    data = read_json_file(path)
    return path, data if isinstance(data, dict) else {}


def is_excluded_converted_markdown(path: Path) -> bool:
    return path.name.lower().endswith("_converted.md")


def eligible_markdown_files(dir_path: Path) -> list[Path]:
    candidates = [f for f in os.scandir(dir_path)]
    return [
        Path(item.path)
        for item in candidates
        if item.is_file()
        and item.name.lower().endswith(".md")
        and not is_excluded_converted_markdown(Path(item.path))
    ]


def _pick_converted_markdown_file(dir_path: Path) -> Optional[Path]:
    meta_path, meta = _pick_conversion_meta(dir_path)
    if meta_path is None:
        return None

    files = meta.get("files") if isinstance(meta, dict) else None
    candidates: list[Path] = []

    if isinstance(files, list):
        for item in files:
            if not isinstance(item, str) or not item.lower().endswith(".md"):
                continue
            candidate = (dir_path / item).resolve(strict=False)
            try:
                candidate.relative_to(dir_path.resolve(strict=False))
            except ValueError:
                continue
            if candidate.is_file():
                candidates.append(candidate)

    if not candidates:
        candidates = [path for path in dir_path.rglob("*.md") if path.is_file()]

    if not candidates:
        return None

    preferred = {"converted.md": 0, "converted_markdown.md": 1, "content.md": 2}
    candidates.sort(key=lambda path: (preferred.get(path.name.lower(), 99), str(path)))
    return candidates[0]


def _pick_text_file(dir_path: Path) -> Tuple[Optional[Path], str]:
    converted_markdown = _pick_converted_markdown_file(dir_path)
    if converted_markdown is not None:
        return converted_markdown, "converted_markdown"

    candidates = eligible_markdown_files(dir_path)
    if candidates:
        preferred = ("content.md", "converted.md")
        candidates_by_name = {f.name.lower(): f for f in candidates}
        for name in preferred:
            if name in candidates_by_name:
                fmt = "markdown" if name == "content.md" else "converted_markdown"
                return candidates_by_name[name], fmt
        candidates.sort(key=lambda p: p.name.lower())
        return candidates[0], "markdown"

    files_dir = dir_path / "files"
    if files_dir.is_dir():
        for path in files_dir.rglob("converted_markdown.md"):
            if path.is_file():
                return path, "converted_markdown"
    return None, ""


def load_page_materials(dir_path: Path) -> tuple[Dict[str, Any], Optional[Path], Optional[Path], str, str]:
    """
    Reads optional page json (or any *.json) and preferred text content in a folder.
    Returns (meta, md_path, json_path, text, source_format).
    """
    meta: Dict[str, Any] = {}
    json_path: Optional[Path] = None
    md_path: Optional[Path] = None
    text = ""
    source_format = "txt"

    json_path, meta = _pick_json_meta(dir_path)
    if not meta:
        json_path, meta = _pick_conversion_meta(dir_path)

    md_path, source_format = _pick_text_file(dir_path)
    if md_path is not None:
        candidate = read_text_file(md_path).strip()
        if candidate and not looks_like_path_list(candidate):
            text = candidate
            logger.debug("ingest:pick_text path=%s format=%s", md_path, source_format)
        else:
            for item in eligible_markdown_files(dir_path):
                if item == md_path:
                    continue
                candidate = read_text_file(item).strip()
                if candidate and not looks_like_path_list(candidate):
                    md_path = item
                    source_format = "markdown" if item.suffix.lower() == ".md" else "txt"
                    text = candidate
                    logger.debug("ingest:pick_text fallback=%s format=%s", md_path, source_format)
                    break

    if not text and meta:
        content = meta.get("content") or meta.get("text") or ""
        text = str(content).strip()
        source_format = "txt"

    return meta or {}, md_path, json_path, text, source_format
