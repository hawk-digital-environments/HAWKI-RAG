"""Typed parsing helpers for Qdrant response payloads."""
from __future__ import annotations

from typing import Any, Dict, List, Optional, TypedDict

from vectorstore.collections import collection_names


class SearchResult(TypedDict, total=False):
    id: str
    score: float
    payload: Dict[str, Any]
    vector: List[float]


SearchResultList = List[SearchResult]


class CountResult(TypedDict, total=False):
    count: int


class SearchResponse(TypedDict, total=False):
    result: SearchResultList


class CountResponse(TypedDict, total=False):
    result: CountResult


class ScrollResult(TypedDict, total=False):
    points: List[SearchResult]
    next_page_offset: str | None


class CollectionConfigResult(TypedDict, total=False):
    result: Dict[str, Any]


def parse_collection_names(payload: Dict[str, Any]) -> List[str]:
    """Extract collection names from Qdrant list payload."""
    return collection_names(payload)


def parse_search_result(payload: Dict[str, Any]) -> SearchResultList:
    """Extract search hits from a Qdrant search response."""
    result = payload.get("result")
    return list(result) if isinstance(result, list) else []


def parse_count(payload: Dict[str, Any]) -> Optional[int]:
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


def parse_scroll_points(payload: Dict[str, Any]) -> tuple[List[SearchResult], str | None]:
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


def parse_collection_config(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Extract config result block."""
    result = payload.get("result")
    return result if isinstance(result, dict) else {}
