from __future__ import annotations

from pathlib import Path
from typing import Any

GRAPH_CACHE_PATTERNS = (
    "kv_store_doc_status*.json",
    "kv_store_doc_status_chunk_*.json",
    "kv_store_full_docs*.json",
    "kv_store_text_chunks*.json",
    "kv_store_llm_response_cache*.json",
    "kv_store_full_entities*.json",
    "kv_store_full_relations*.json",
    "kv_store_entity_chunks*.json",
    "kv_store_relation_chunks*.json",
    "vdb_*.json",
)


def clear_graph_cache_files(working_dir: Path) -> dict[str, Any]:
    removed: list[str] = []
    failed: dict[str, str] = {}

    for pattern in GRAPH_CACHE_PATTERNS:
        for path in working_dir.glob(pattern):
            if not path.is_file():
                continue
            try:
                path.unlink()
                removed.append(str(path))
            except Exception as exc:
                failed[str(path)] = str(exc)

    return {
        "ok": not failed,
        "working_dir": str(working_dir),
        "removed": removed,
        "failed": failed,
    }
