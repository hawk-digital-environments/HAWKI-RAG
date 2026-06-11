"""Local estimate utilities for crawled-data ingest."""
from __future__ import annotations

import math
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional

from application.cli.commands.metadata import first_str, make_doc_id
from application.cli.commands.materials import load_page_materials


def utc_now_iso() -> str:
    """Return an ISO-8601 UTC timestamp with trailing Z."""
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def split_text_local(text: str, target: int, overlap: int) -> list[str]:
    text = (text or "").strip()
    if not text:
        return []
    if len(text) <= target:
        return [text]

    out: list[str] = []
    start = 0
    length = len(text)
    while start < length:
        end = min(length, start + target)
        slice_ = text[start:end]
        cut = slice_.rfind("\n\n")
        if cut != -1 and cut > int(target * 0.6):
            end = start + cut
        chunk = text[start:end].strip()
        if chunk:
            out.append(chunk)
        if end >= length:
            break
        start = max(0, end - overlap)
    return out


def run_local_estimate(
    *,
    page_dirs: list[Path],
    root: Path,
    chunk_chars: int,
    chunk_overlap: int,
    collection: Optional[str],
    batch_size: int,
) -> dict[str, Any]:
    doc_stats: dict[str, Any] = {
        "total_docs": len(page_dirs),
        "processed_docs": 0,
        "skipped_docs": 0,
        "by_format": {},
        "doc_ids": [],
    }
    total_chunks = 0

    for directory in page_dirs:
        meta, _, _, text, source_fmt = load_page_materials(directory)
        if not isinstance(text, str) or text.strip() == "":
            doc_stats["skipped_docs"] += 1
            continue

        page_url = first_str(meta.get("url") or meta.get("page_url"))
        rel = str(directory.relative_to(root))
        doc_id = make_doc_id(page_url, rel)
        chunks = split_text_local(text, chunk_chars, chunk_overlap) or [text]
        chunk_count = 0
        for chunk in chunks:
            if chunk:
                chunk_count += 1

        if chunk_count == 0:
            doc_stats["skipped_docs"] += 1
            continue

        doc_stats["processed_docs"] += 1
        doc_stats["doc_ids"].append(doc_id)
        fmt_key = source_fmt or "unknown"
        by_format = doc_stats["by_format"]
        by_format[fmt_key] = by_format.get(fmt_key, 0) + 1
        chunks_map = doc_stats.setdefault("chunks_per_doc", {})
        chunks_map[doc_id] = chunk_count
        total_chunks += chunk_count

    doc_stats["total_chunks"] = total_chunks
    return {
        "timestamp": utc_now_iso(),
        "estimate_only": True,
        "planned_points": total_chunks,
        "documents": doc_stats,
        "qdrant_preview": {
            "collection": collection or "(server default)",
            "batch_size": batch_size,
            "planned_batches": math.ceil(total_chunks / batch_size) if total_chunks else 0,
            "planned_points": total_chunks,
        },
        "graph_preview_skipped": "Local estimate does not analyze Neo4j impact.",
    }
