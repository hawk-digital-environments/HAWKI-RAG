from __future__ import annotations

import json
import logging
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

logger = logging.getLogger(__name__)


def utc_now_iso() -> str:
    return datetime.now(tz=timezone.utc).isoformat()


def int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except Exception:
        return default


def build_summary(
    doc_stats: dict[str, Any],
    *,
    total_chunks: int,
    batch_size: int,
    collection: str,
    graph_enabled: bool,
    estimate_only: bool = False,
    qdrant_ms: float | None = None,
    neo4j_ms: float | None = None,
    total_ms: float | None = None,
) -> dict[str, Any]:
    return {
        "timestamp": utc_now_iso(),
        "estimate_only": estimate_only,
        "planned_points": total_chunks,
        "documents": doc_stats,
        "qdrant_preview": {
            "collection": collection or "(server default)",
            "batch_size": batch_size,
            "planned_batches": (total_chunks + batch_size - 1) // batch_size if total_chunks else 0,
            "planned_points": total_chunks,
            "elapsed_ms": qdrant_ms,
        },
        "graph": {
            "enabled": graph_enabled,
            "elapsed_ms": neo4j_ms,
        },
        "total_ms": total_ms,
        "dry_run": estimate_only,
    }


def build_graph_preview(
    doc_stats: dict[str, Any],
    chunk_records: list[dict[str, Any]],
    triplets_by_doc: dict[str, list[tuple[str, str, str]]],
) -> dict[str, Any]:
    sources: dict[str, dict[str, str]] = {}
    for rec in chunk_records:
        doc_id = str(rec.get("doc_id"))
        if doc_id in sources:
            continue
        payload = rec.get("payload") or {}
        sources[doc_id] = {
            "title": str(payload.get("title") or ""),
            "page_url": str(payload.get("page_url") or payload.get("source_url") or ""),
            "source_url": str(payload.get("source_url") or ""),
        }

    chunks_per_doc = doc_stats.get("chunks_per_doc") or {}
    doc_limit = int_env("RAG_GRAPH_PREVIEW_DOC_LIMIT", 0)
    per_doc: dict[str, Any] = {}
    total_triplets = 0
    docs_with_triplets = 0

    for doc_id, triplets in triplets_by_doc.items():
        if doc_limit > 0 and len(per_doc) >= doc_limit:
            break
        triplet_count = len(triplets)
        total_triplets += triplet_count
        if triplet_count:
            docs_with_triplets += 1
        entry = {
            "chunks": int(chunks_per_doc.get(doc_id, 0)),
            "triplets": triplet_count,
        }
        entry.update(sources.get(doc_id, {}))
        if triplet_count or entry["chunks"]:
            per_doc[doc_id] = entry

    return {
        "timestamp": utc_now_iso(),
        "total_docs": int(doc_stats.get("processed_docs") or 0),
        "total_chunks": int(doc_stats.get("total_chunks") or 0),
        "docs_with_triplets": docs_with_triplets,
        "total_triplets": total_triplets,
        "per_doc": per_doc,
    }


def write_graph_preview(graph_preview: dict[str, Any], public_dir: Path) -> Path | None:
    if not graph_preview:
        return None
    try:
        public_dir.mkdir(parents=True, exist_ok=True)
        preview_path = public_dir / "ingest_graph_preview.json"
        preview_path.write_text(json.dumps(graph_preview, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        return preview_path
    except Exception as exc:
        logger.warning("ingest:failed to write graph preview: %s", exc)
        return None


def write_ingest_summary(summary: dict[str, Any], public_dir: Path) -> Path:
    public_dir.mkdir(parents=True, exist_ok=True)
    summary_path = public_dir / "ingest_summary.json"
    summary_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return summary_path
