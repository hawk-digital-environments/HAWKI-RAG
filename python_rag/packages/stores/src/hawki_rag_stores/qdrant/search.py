"""Search orchestration helpers for Qdrant query execution."""

from __future__ import annotations

import copy
from typing import Any, Callable, Sequence

from hawki_rag_stores.qdrant.responses import SearchResultList


SearchExecutor = Callable[[str, dict[str, Any], float], SearchResultList]


def normalize_query_inputs(
    terms: Sequence[str] | None, fields: Sequence[str] | None
) -> tuple[list[str], list[str]]:
    """Filter empty entries from terms and fields."""
    clean_terms = [t for t in (terms or []) if t]
    clean_fields = [f for f in (fields or []) if f]
    return clean_terms, clean_fields


def merge_search_results(
    collection_hits: Sequence[tuple[str, SearchResultList]],
    *,
    top_k: int,
    annotate_collection: bool = True,
) -> list[dict[str, Any]]:
    """Merge, sort, and trim query hits across collections."""
    merged: list[dict[str, Any]] = []
    for collection, hits in collection_hits:
        for hit in hits:
            if not isinstance(hit, dict):
                continue
            if annotate_collection and "collection" not in hit:
                hit = dict(hit)
                hit["collection"] = collection
            merged.append(hit)
    merged.sort(key=lambda h: float(h.get("score") or 0.0), reverse=True)
    return merged[: int(top_k)]


def search_with_fallback_collections(
    collections: Sequence[str],
    base_body: dict[str, Any],
    timeout: float,
    top_k: int,
    per_collection_limit: int,
    *,
    execute: SearchExecutor,
) -> list[dict[str, Any]]:
    """Search multiple collections and return top-k merged scored hits."""
    if not collections:
        return []
    all_hits = []
    for name in collections:
        body = copy.deepcopy(base_body)
        body["limit"] = int(per_collection_limit)
        hits = execute(name, body, timeout)
        all_hits.append((name, hits))
    return merge_search_results(all_hits, top_k=top_k)
