"""Typed request builders for Qdrant transport calls."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class QdrantRequest:
    """Concrete request description passed to the HTTP transport."""

    method: str
    path: str
    json_body: dict[str, Any] | None = None
    timeout: float | None = None
    operation: str | None = None
    retryable: bool = True
    operation_id: str | None = None


def build_get_collection_request(collection: str) -> QdrantRequest:
    return QdrantRequest(
        "GET",
        f"/collections/{collection}",
        operation="qdrant.collections.get",
    )


def build_list_collections_request() -> QdrantRequest:
    return QdrantRequest("GET", "/collections", operation="qdrant.collections.list")


def build_create_collection_request(collection: str, vector_size: int, distance: str) -> QdrantRequest:
    return QdrantRequest(
        "PUT",
        f"/collections/{collection}",
        json_body={"vectors": {"size": int(vector_size), "distance": distance}},
        operation="qdrant.collections.create",
        retryable=False,
    )


def build_upsert_points_request(
    collection: str,
    points: list[dict[str, Any]],
    *,
    timeout: float,
    operation_id: str | None = None,
    retryable: bool = False,
) -> QdrantRequest:
    return QdrantRequest(
        "PUT",
        f"/collections/{collection}/points",
        json_body={"points": points},
        timeout=timeout,
        operation="qdrant.upsert_points",
        operation_id=operation_id,
        retryable=retryable,
    )


def build_count_points_request(collection: str, *, exact: bool, timeout: float) -> QdrantRequest:
    return build_count_points_request_with_filter(collection, exact=exact, timeout=timeout)


def build_count_points_request_with_filter(
    collection: str,
    *,
    exact: bool,
    timeout: float,
    filter_body: dict[str, Any] | None = None,
) -> QdrantRequest:
    body: dict[str, Any] = {"exact": bool(exact)}
    if filter_body:
        body["filter"] = filter_body
    return QdrantRequest(
        "POST",
        f"/collections/{collection}/points/count",
        json_body=body,
        timeout=timeout,
        operation="qdrant.points.count",
    )


def build_search_request(
    collection: str,
    body: dict[str, Any],
    *,
    timeout: float,
) -> QdrantRequest:
    return QdrantRequest(
        "POST",
        f"/collections/{collection}/points/search",
        json_body=body,
        timeout=timeout,
        operation="qdrant.points.search",
    )


def build_delete_by_filter_request(
    collection: str,
    filter_body: dict[str, Any],
    *,
    timeout: float,
    operation_id: str | None = None,
    retryable: bool = False,
) -> QdrantRequest:
    return QdrantRequest(
        "POST",
        f"/collections/{collection}/points/delete",
        json_body={"filter": filter_body},
        timeout=timeout,
        operation="qdrant.delete_by_filter",
        operation_id=operation_id,
        retryable=retryable,
    )


def build_scroll_request(
    collection: str,
    body: dict[str, Any],
    *,
    timeout: float,
) -> QdrantRequest:
    return QdrantRequest(
        "POST",
        f"/collections/{collection}/points/scroll",
        json_body=body,
        timeout=timeout,
        operation="qdrant.points.scroll",
    )
