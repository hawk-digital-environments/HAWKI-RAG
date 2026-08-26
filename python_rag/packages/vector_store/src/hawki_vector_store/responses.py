"""Typed parsing helpers for Qdrant response payloads."""

from __future__ import annotations

from typing import Any, Optional

from hawki_vector_store.collections import collection_names
from hawki_vector_store.contracts import VectorSearchHit


SearchResult = VectorSearchHit
SearchResultList = list[VectorSearchHit]


def parse_collection_names(payload: dict[str, Any]) -> list[str]:
    """Extract collection names from Qdrant list payload."""
    return collection_names(payload)


def parse_search_result(payload: dict[str, Any]) -> SearchResultList:
    """Extract search hits from a Qdrant search response."""
    result = payload.get("result")
    return list(result) if isinstance(result, list) else []


def parse_count(payload: dict[str, Any]) -> Optional[int]:
    """Extract count from a Qdrant count response."""
    result = payload.get("result") or {}
    if not isinstance(result, dict):
        return None
    count = result.get("count")
    if count is None:
        return None
    try:
        return int(count)
    except (TypeError, ValueError):
        return None


def parse_scroll_points(
    payload: dict[str, Any],
) -> tuple[list[VectorSearchHit], str | None]:
    """Extract scroll points and next offset."""
    result = payload.get("result") or {}
    if not isinstance(result, dict):
        return [], None
    points = result.get("points")
    if isinstance(points, list):
        safe_points = [point for point in points if isinstance(point, dict)]
    else:
        safe_points = []
    next_page_offset = result.get("next_page_offset")
    return safe_points, next_page_offset if next_page_offset is not None else None


def parse_collection_config(payload: dict[str, Any]) -> dict[str, Any]:
    """Extract config result block."""
    result = payload.get("result")
    return result if isinstance(result, dict) else {}
