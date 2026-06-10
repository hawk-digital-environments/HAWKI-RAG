"""URL resolution helpers for crawled ingest folders."""
from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any, Dict, Optional, Set, Tuple

from ingest.metadata import first_str


def normalize_path(path_like: Any) -> Optional[Path]:
    if path_like is None:
        return None
    try:
        path = Path(str(path_like)).expanduser()
        return path.resolve(strict=False)
    except Exception:
        return None


def read_json_file(path: Path) -> Dict[str, Any]:
    try:
        return json.loads(path.read_text(encoding="utf-8", errors="ignore"))
    except Exception:
        try:
            return json.loads(path.read_text(errors="ignore"))
        except Exception:
            return {}


def build_url_maps(root: Path) -> Tuple[Dict[Path, str], Dict[Path, str]]:
    page_url_map: Dict[Path, str] = {}
    source_url_map: Dict[Path, str] = {}
    pdf_lookup: Dict[Path, Dict[str, str]] = {}
    root_resolved = root.resolve(strict=False)

    for dirpath, _, filenames in os.walk(root):
        dir_path = Path(dirpath)
        dir_resolved = dir_path.resolve(strict=False)
        json_files = [filename for filename in filenames if filename.lower().endswith(".json")]
        if not json_files:
            continue
        for filename in json_files:
            if filename == "conversion_meta.json":
                continue
            data = read_json_file(dir_path / filename)
            if not isinstance(data, dict):
                continue
            page_url = first_str(data.get("url") or data.get("page_url"))
            if page_url:
                page_url_map.setdefault(dir_resolved, page_url)
            pdfs = data.get("pdfs")
            if isinstance(pdfs, list):
                for pdf_entry in pdfs:
                    if not isinstance(pdf_entry, dict):
                        continue
                    local_path = normalize_path(pdf_entry.get("local_path"))
                    pdf_url = first_str(pdf_entry.get("url"))
                    if not local_path or not pdf_url:
                        continue
                    pdf_lookup[local_path] = {
                        "page_url": page_url,
                        "source_url": pdf_url,
                    }

    for dirpath, _, filenames in os.walk(root):
        if "conversion_meta.json" not in filenames:
            continue
        dir_path = Path(dirpath)
        conv_meta = read_json_file(dir_path / "conversion_meta.json")
        if not isinstance(conv_meta, dict):
            continue
        source_pdf = normalize_path(conv_meta.get("source_pdf") or conv_meta.get("source_file"))
        explicit_page_url = first_str(conv_meta.get("page_url") or conv_meta.get("url") or conv_meta.get("original_url"))
        explicit_source_url = first_str(conv_meta.get("source_url") or conv_meta.get("original_url"))
        info = pdf_lookup.get(source_pdf, {}) if source_pdf else {}

        target_dirs: Set[Path] = set()
        dir_resolved = dir_path.resolve(strict=False)
        target_dirs.add(dir_resolved)
        output_dir = normalize_path(conv_meta.get("output_dir"))
        if output_dir:
            target_dirs.add(output_dir)
        for base in list(target_dirs):
            output_path = normalize_path(base / "output")
            if output_path:
                target_dirs.add(output_path)
        for target_dir in target_dirs:
            page_url = explicit_page_url or info.get("page_url")
            source_url = explicit_source_url or info.get("source_url") or page_url
            if page_url:
                page_url_map.setdefault(target_dir, page_url)
            if source_url:
                source_url_map.setdefault(target_dir, source_url)

    for dir_path, page_url in page_url_map.items():
        source_url_map.setdefault(dir_path, page_url)

    if root_resolved in page_url_map and root_resolved not in source_url_map:
        source_url_map[root_resolved] = page_url_map[root_resolved]

    return page_url_map, source_url_map


def resolve_url_for_path(mapping: Dict[Path, str], path: Path, root: Path) -> Optional[str]:
    try:
        current = path.resolve(strict=False)
        root_resolved = root.resolve(strict=False)
    except Exception:
        return None

    while True:
        if current in mapping:
            return mapping[current]
        if current == root_resolved:
            break
        parent = current.parent
        if parent == current:
            break
        try:
            if not current.is_relative_to(root_resolved):
                break
        except AttributeError:
            current_str = str(current)
            root_str = str(root_resolved)
            if not current_str.startswith(root_str.rstrip(os.sep) + os.sep):
                break
        current = parent

    return None
