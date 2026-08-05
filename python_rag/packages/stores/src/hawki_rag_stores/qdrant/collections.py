"""Pure helpers for Qdrant collection metadata."""

from __future__ import annotations

from typing import Any, Iterable, Optional


def collection_names(response_json: dict[str, Any]) -> list[str]:
    data = response_json.get("result", {}) or {}
    names: list[str] = []
    for collection in data.get("collections", []) or []:
        name = collection.get("name") if isinstance(collection, dict) else None
        if name:
            names.append(str(name))
    return names


def pick_most_populated_collection(
    counts: Iterable[tuple[str, Optional[int]]],
) -> Optional[str]:
    best_name = None
    best_count = -1
    for name, count in counts:
        if count is None:
            continue
        if count > best_count:
            best_name = name
            best_count = count
    return best_name


def vector_size_from_config(config: dict[str, Any]) -> Optional[int]:
    params = (config.get("config") or {}).get("params") or {}
    vectors = params.get("vectors") or {}
    if isinstance(vectors, dict) and "size" in vectors:
        return int(vectors.get("size"))
    if isinstance(vectors, dict) and isinstance(vectors.get("params"), dict):
        first = next(iter(vectors["params"].values()), None)
        if isinstance(first, dict) and "size" in first:
            return int(first["size"])
    return None
