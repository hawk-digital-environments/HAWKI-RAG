"""Response interpretation helpers for Qdrant adapters."""
from __future__ import annotations

from typing import Any, Dict, List, Optional, Tuple, TypeVar

from requests import Response

from infrastructure.vectorstore.qdrant_responses import SearchResultList, parse_search_result, parse_scroll_points

SearchRow = dict[str, Any]


def parse_search_payload(response: Response, *, empty_on_not_found: bool = False) -> SearchResultList:
    """Parse vector search hits from a Qdrant response."""
    if response.status_code == 404 and empty_on_not_found:
        return []
    response.raise_for_status()
    return parse_search_result(response.json())


def parse_scroll_payload(
    response: Response,
    *,
    empty_on_not_found: bool = False,
) -> tuple[SearchResultList, Optional[str]]:
    """Parse scroll payload while preserving empty-on-miss behavior."""
    if response.status_code == 404 and empty_on_not_found:
        return [], None
    response.raise_for_status()
    return parse_scroll_points(response.json())


def attach_collection(hits: list[SearchRow], collection: str) -> list[SearchRow]:
    """Attach `collection` metadata to all dict hits where it is missing."""
    out: list[SearchRow] = []
    for hit in hits:
        if not isinstance(hit, dict):
            out.append(hit)
            continue
        if "collection" in hit:
            out.append(hit)
            continue
        with_collection = dict(hit)
        with_collection["collection"] = collection
        out.append(with_collection)
    return out


T = TypeVar("T", bound=dict[str, Any])


def sort_hits_by_score(hits: list[T], limit: int | None = None) -> list[T]:
    """Sort hits descending by `score` and apply an optional limit."""
    merged = sorted(hits, key=lambda h: float(h.get("score") or 0.0), reverse=True)
    if limit is None:
        return merged
    return merged[: int(limit)]

