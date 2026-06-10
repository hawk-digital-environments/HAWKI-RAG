"""Typed request builders for Qdrant transport calls."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Optional


@dataclass(frozen=True)
class QdrantRequest:
    """Concrete request description passed to the HTTP transport."""

    method: str
    path: str
    json_body: Optional[Dict[str, Any]] = None
    timeout: Optional[float] = None


def build_get_collection_request(collection: str) -> QdrantRequest:
    return QdrantRequest("GET", f"/collections/{collection}")


def build_list_collections_request() -> QdrantRequest:
    return QdrantRequest("GET", "/collections")


def build_create_collection_request(collection: str, vector_size: int, distance: str) -> QdrantRequest:
    return QdrantRequest(
        "PUT",
        f"/collections/{collection}",
        json_body={"vectors": {"size": int(vector_size), "distance": distance}},
    )


def build_upsert_points_request(
    collection: str,
    points: list[dict[str, Any]],
    *,
    timeout: float,
) -> QdrantRequest:
    return QdrantRequest(
        "PUT",
        f"/collections/{collection}/points",
        json_body={"points": points},
        timeout=timeout,
    )


def build_count_points_request(collection: str, *, exact: bool, timeout: float) -> QdrantRequest:
    return QdrantRequest(
        "POST",
        f"/collections/{collection}/points/count",
        json_body={"exact": bool(exact)},
        timeout=timeout,
    )


def build_search_request(
    collection: str,
    body: Dict[str, Any],
    *,
    timeout: float,
) -> QdrantRequest:
    return QdrantRequest(
        "POST",
        f"/collections/{collection}/points/search",
        json_body=body,
        timeout=timeout,
    )


def build_delete_by_filter_request(
    collection: str,
    filter_body: Dict[str, Any],
    *,
    timeout: float,
) -> QdrantRequest:
    return QdrantRequest(
        "POST",
        f"/collections/{collection}/points/delete",
        json_body={"filter": filter_body},
        timeout=timeout,
    )


def build_scroll_request(
    collection: str,
    body: Dict[str, Any],
    *,
    timeout: float,
) -> QdrantRequest:
    return QdrantRequest(
        "POST",
        f"/collections/{collection}/points/scroll",
        json_body=body,
        timeout=timeout,
    )
