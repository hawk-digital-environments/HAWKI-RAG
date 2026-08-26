"""Primitive Qdrant request gateway.

This module keeps request construction and transport execution together so
`QdrantHTTP` can focus on orchestration and policy decisions.
"""

from __future__ import annotations

from typing import Any

from hawki_vector_store.requests import (
    QdrantRequest,
    build_count_points_request,
    build_count_points_request_with_filter,
    build_create_collection_request,
    build_delete_by_filter_request,
    build_get_collection_request,
    build_list_collections_request,
    build_scroll_request,
    build_search_request,
    build_upsert_points_request,
)
from hawki_vector_store.transport import QdrantHTTPTransport
from hawki_rag_resilience.reliability import is_retry_safe_write


class QdrantHTTPGateway:
    """Low-level request gateway for a configured Qdrant collection."""

    def __init__(self, *, transport: QdrantHTTPTransport, collection: str) -> None:
        self._transport = transport
        self.collection = collection

    def send(self, request: QdrantRequest) -> Any:
        return self._transport.send(request)

    def ensure_collection(self, *, vector_size: int, distance: str) -> Any:
        return self.send(
            build_create_collection_request(
                self.collection,
                vector_size=vector_size,
                distance=distance,
            )
        )

    def list_collections(self) -> Any:
        return self.send(build_list_collections_request())

    def get_collection(self) -> Any:
        return self.send(build_get_collection_request(self.collection))

    def search(self, collection: str, body: dict[str, Any], *, timeout: float) -> Any:
        return self.send(build_search_request(collection, body, timeout=timeout))

    def upsert(
        self,
        points: list[dict[str, Any]],
        *,
        timeout: float,
        operation_id: str | None = None,
    ) -> Any:
        operation_retryable = bool(
            operation_id and is_retry_safe_write("qdrant.upsert_points")
        )
        return self.send(
            build_upsert_points_request(
                self.collection,
                points,
                timeout=timeout,
                operation_id=operation_id,
                retryable=operation_retryable,
            )
        )

    def count_points(
        self,
        collection: str,
        *,
        exact: bool,
        timeout: float,
        filter_body: dict[str, Any] | None = None,
    ) -> Any:
        if filter_body:
            return self.send(
                build_count_points_request_with_filter(
                    collection,
                    exact=exact,
                    timeout=timeout,
                    filter_body=filter_body,
                )
            )
        return self.send(
            build_count_points_request(collection, exact=exact, timeout=timeout)
        )

    def delete_by_filter(
        self,
        filter_body: dict[str, Any],
        *,
        timeout: float,
        operation_id: str | None = None,
    ) -> Any:
        operation_retryable = bool(
            operation_id and is_retry_safe_write("qdrant.delete_by_filter")
        )
        return self.send(
            build_delete_by_filter_request(
                self.collection,
                filter_body=filter_body,
                timeout=timeout,
                operation_id=operation_id,
                retryable=operation_retryable,
            )
        )

    def scroll(self, collection: str, body: dict[str, Any], *, timeout: float) -> Any:
        return self.send(build_scroll_request(collection, body, timeout=timeout))
