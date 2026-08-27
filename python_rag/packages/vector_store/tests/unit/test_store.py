"""Focused behavior tests for the extracted Qdrant store package."""

from __future__ import annotations

from types import SimpleNamespace
from typing import Any
from unittest.mock import patch

import pytest

from hawki_vector_store.client import QdrantHTTP, ScopedCollectionNotReadyError
from hawki_vector_store.contracts import VectorPoint, VectorSearchHit
from hawki_vector_store.gateway import QdrantHTTPGateway
from hawki_vector_store.payloads import (
    build_match_filter,
    build_search_body,
    combine_filter_bodies,
    iter_batches,
)
from hawki_vector_store.requests import QdrantRequest
from hawki_vector_store.responses import parse_count, parse_scroll_points
from hawki_vector_store.settings import QdrantHTTPSettings, QdrantSettings
from hawki_vector_store.transport import QdrantHTTPTransport


class _Response:
    def __init__(self, status_code: int, payload: dict[str, Any] | None = None) -> None:
        self.status_code = status_code
        self._payload = payload or {}
        self.text = "response"

    def json(self) -> dict[str, Any]:
        return self._payload

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            raise RuntimeError(f"status={self.status_code}")


def _settings() -> QdrantSettings:
    return QdrantSettings(
        scheme="http",
        host="qdrant",
        port=6333,
        collection="dataset-a",
        api_key=None,
        timeout=3.0,
        max_attempts=2,
        retry_attempts_by_operation={},
    )


def _http_settings() -> QdrantHTTPSettings:
    return QdrantHTTPSettings(
        log_latency=False,
        search_all=False,
        search_all_per_collection=0,
        fallback_all=False,
        fallback_per_collection=0,
        upsert_timeout=4.0,
        search_timeout=5.0,
        count_timeout=6.0,
        delete_timeout=7.0,
        text_timeout=8.0,
        text_fallback_terms=3,
        text_scroll_hard_cap=500,
        text_scroll_batch=64,
    )


def test_qdrant_adapter_preserves_vector_contract_shapes() -> None:
    point: VectorPoint = {
        "id": "chunk-1",
        "vector": [0.1, 0.2],
        "payload": {"dataset_id": "dataset-a"},
    }
    hit: VectorSearchHit = {
        "id": point["id"],
        "score": 0.9,
        "payload": point["payload"],
    }

    with patch(
        "hawki_vector_store.client.requests.Session",
        return_value=SimpleNamespace(),
    ):
        client = QdrantHTTP(settings=_settings(), http_settings=_http_settings())

    assert callable(client.search)
    assert callable(client.upsert_points)
    assert hit == {
        "id": "chunk-1",
        "score": 0.9,
        "payload": {"dataset_id": "dataset-a"},
    }


def test_payload_and_response_primitives_preserve_existing_shapes() -> None:
    assert list(iter_batches([1, 2, 3], 2)) == [[1, 2], [3]]
    exact_filter = build_match_filter({"dataset_id": "dataset-a"})
    assert exact_filter == {
        "must": [{"key": "dataset_id", "match": {"value": "dataset-a"}}]
    }
    assert combine_filter_bodies(exact_filter, {"should": [{"key": "title"}]}) == {
        "must": [exact_filter, {"should": [{"key": "title"}]}]
    }
    assert (
        build_search_body([0.1, 0.2], top_k=3, filters={"dataset_id": "dataset-a"})[
            "filter"
        ]
        == exact_filter
    )
    assert parse_count({"result": {"count": "4"}}) == 4
    assert parse_count({"result": {"count": "bad"}}) is None
    assert parse_scroll_points(
        {"result": {"points": [{"id": "one"}, "invalid"], "next_page_offset": "next"}}
    ) == ([{"id": "one"}], "next")


def test_gateway_retries_writes_only_when_operation_id_exists() -> None:
    class RecordingTransport:
        def __init__(self) -> None:
            self.requests: list[QdrantRequest] = []

        def send(self, request: QdrantRequest) -> _Response:
            self.requests.append(request)
            return _Response(200)

    transport = RecordingTransport()
    gateway = QdrantHTTPGateway(transport=transport, collection="dataset-a")  # type: ignore[arg-type]

    gateway.upsert([{"id": "one"}], timeout=1.0)
    gateway.upsert([{"id": "two"}], timeout=1.0, operation_id="job:one")
    gateway.delete_by_filter({"must": []}, timeout=1.0, operation_id="job:delete")

    assert transport.requests[0].retryable is False
    assert transport.requests[1].retryable is True
    assert transport.requests[2].retryable is True
    assert transport.requests[1].operation_id == "job:one"


def test_transport_uses_injected_session_and_bounded_status_retry() -> None:
    class RecordingSession:
        def __init__(self) -> None:
            self.calls: list[tuple[str, str, dict[str, Any]]] = []
            self.responses = [_Response(503), _Response(200)]

        def request(self, method: str, url: str, **kwargs: Any) -> _Response:
            self.calls.append((method, url, kwargs))
            return self.responses.pop(0)

    session = RecordingSession()
    transport = QdrantHTTPTransport(
        base_url="http://qdrant:6333",
        api_key="secret-key",
        default_timeout=2.0,
        max_attempts=2,
        backoff_seconds=0,
        session=session,
    )

    response = transport.send(
        QdrantRequest("GET", "/collections", operation="qdrant.collections.list")
    )

    assert response.status_code == 200
    assert len(session.calls) == 2
    assert session.calls[0][2]["headers"]["api-key"] == "secret-key"
    assert session.calls[0][2]["timeout"] == 2.0


def test_scoped_collection_cannot_be_replaced_or_fall_back() -> None:
    class MissingGateway:
        collection = "dataset-a"

        def search(
            self, collection: str, body: dict[str, Any], *, timeout: float
        ) -> _Response:
            assert collection == "dataset-a"
            assert body["limit"] == 2
            assert timeout == 5.0
            return _Response(404)

    with patch(
        "hawki_vector_store.client.requests.Session",
        return_value=SimpleNamespace(),
    ):
        client = QdrantHTTP(settings=_settings(), http_settings=_http_settings())
    client._gateway = MissingGateway()  # type: ignore[assignment]
    client.select_scoped_collection("dataset-a")

    with pytest.raises(RuntimeError, match="Cannot replace"):
        client.set_collection("dataset-b")
    with pytest.raises(ScopedCollectionNotReadyError, match="not ready"):
        client.search([0.1, 0.2], top_k=2)
