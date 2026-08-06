"""Dataset-scoped query scenarios covering validation, search fallbacks, and fail-closed errors."""

from __future__ import annotations

import logging
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from typing import Any
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[3]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


class _FakeResponse:
    def __init__(self, status_code: int, payload: dict[str, Any] | None = None) -> None:
        self.status_code = status_code
        self._payload = payload or {}
        self.text = ""

    def json(self) -> dict[str, Any]:
        return self._payload

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")


def _qdrant_settings() -> Any:
    from hawki_rag_stores.qdrant.settings import QdrantSettings

    return QdrantSettings(
        scheme="http",
        host="qdrant",
        port=6333,
        collection="global_default",
        api_key=None,
        timeout=1.0,
        max_attempts=1,
    )


def _qdrant_http_settings(*, search_all: bool = True, fallback_all: bool = True) -> Any:
    from hawki_rag_stores.qdrant.settings import QdrantHTTPSettings

    return QdrantHTTPSettings(
        log_latency=False,
        search_all=search_all,
        search_all_per_collection=0,
        fallback_all=fallback_all,
        fallback_per_collection=0,
        upsert_timeout=1.0,
        search_timeout=1.0,
        count_timeout=1.0,
        delete_timeout=1.0,
        text_timeout=1.0,
        text_fallback_terms=3,
        text_scroll_hard_cap=100,
        text_scroll_batch=16,
    )


class DatasetNotReadyHttpContractTests(unittest.TestCase):
    """Verify missing scoped storage returns the stable public dataset-not-ready response."""

    def test_structured_dataset_not_ready_error_has_a_stable_public_shape(self) -> None:
        from fastapi import FastAPI, HTTPException
        from asgi_client import ASGITestClient as TestClient

        from hawki_bridge.http.errors import install_exception_handlers

        app = FastAPI()
        install_exception_handlers(
            app, logging.getLogger("dataset_not_ready_contract_test")
        )

        @app.post("/query")
        def query() -> None:
            raise HTTPException(
                status_code=503,
                detail={
                    "code": "dataset_not_ready",
                    "message": "The authorized dataset storage is not ready.",
                },
            )

        with TestClient(app) as client:
            response = client.post("/query")

        self.assertEqual(response.status_code, 503)
        self.assertEqual(
            response.json(),
            {
                "error": {
                    "type": "HTTPException",
                    "status": 503,
                    "message": "The authorized dataset storage is not ready.",
                    "path": "/query",
                    "request_id": "",
                    "code": "dataset_not_ready",
                },
            },
        )


class QueryFallbackScopeTests(unittest.TestCase):
    """Verify lexical and scroll fallbacks inherit the mandatory dataset filter."""

    def test_keyword_and_scroll_fallbacks_receive_mandatory_filters(self) -> None:
        from hawki_bridge.application.query.fallback import keyword_fallback_search

        calls: list[tuple[str, dict[str, Any]]] = []
        expected_filters = {"source_format": "pdf", "dataset_id": "dataset-a"}

        class FakeQdrant:
            def search_with_text(
                self, vector: list[float], **kwargs: Any
            ) -> list[dict[str, Any]]:
                calls.append(("search", kwargs["filters"]))
                return []

            def scroll_with_text(self, **kwargs: Any) -> list[dict[str, Any]]:
                calls.append((f"scroll:{kwargs['require_all']}", kwargs["filters"]))
                if kwargs["require_all"]:
                    return []
                return [
                    {"id": "a", "score": 0.5, "payload": {"dataset_id": "dataset-a"}}
                ]

        hits = keyword_fallback_search(
            FakeQdrant(),
            [0.1],
            "page ten",
            2,
            filters=expected_filters,
            text_scroll_limit_fn=lambda top_k: 5,
            exhaustive_text_fn=lambda: False,
        )

        self.assertEqual(len(hits), 1)
        self.assertEqual(
            calls,
            [
                ("search", expected_filters),
                ("scroll:True", expected_filters),
                ("scroll:False", expected_filters),
            ],
        )


class QdrantStrictCollectionTests(unittest.TestCase):
    """Verify scoped search never reaches global collection search or fallback paths."""

    def test_scoped_search_bypasses_search_all_and_global_fallback(self) -> None:
        from hawki_rag_stores.qdrant.client import QdrantHTTP

        calls: list[tuple[str, Any]] = []

        class FakeGateway:
            collection = "global_default"

            def search(
                self, collection: str, body: dict[str, Any], timeout: float
            ) -> _FakeResponse:
                calls.append(("search", collection))
                calls.append(("filter", body["filter"]))
                return _FakeResponse(200, {"result": []})

            def list_collections(self) -> _FakeResponse:
                raise AssertionError("scoped search must not list collections")

        with (
            patch(
                "hawki_rag_stores.qdrant.client.requests.Session",
                return_value=SimpleNamespace(),
            ),
            patch(
                "hawki_rag_stores.qdrant.client.QdrantHTTPGateway",
                return_value=FakeGateway(),
            ),
        ):
            client = QdrantHTTP(
                settings=_qdrant_settings(),
                http_settings=_qdrant_http_settings(search_all=True, fallback_all=True),
            )
            client.select_scoped_collection("hawki_dataset_a")
            self.assertEqual(
                client.search([0.1], filters={"dataset_id": "dataset-a"}),
                [],
            )

        self.assertEqual(calls[0], ("search", "hawki_dataset_a"))
        self.assertEqual(
            calls[1],
            (
                "filter",
                {"must": [{"key": "dataset_id", "match": {"value": "dataset-a"}}]},
            ),
        )

    def test_text_vector_and_scroll_requests_and_dataset_filter_with_lexical_filter(
        self,
    ) -> None:
        from hawki_rag_stores.qdrant.client import QdrantHTTP

        search_filters: list[dict[str, Any]] = []
        scroll_filters: list[dict[str, Any]] = []

        class FakeGateway:
            collection = "global_default"

            def search(
                self, collection: str, body: dict[str, Any], timeout: float
            ) -> _FakeResponse:
                search_filters.append(body["filter"])
                return _FakeResponse(200, {"result": []})

            def scroll(
                self, collection: str, body: dict[str, Any], timeout: float
            ) -> _FakeResponse:
                scroll_filters.append(body["filter"])
                return _FakeResponse(
                    200,
                    {"result": {"points": [], "next_page_offset": None}},
                )

        with (
            patch(
                "hawki_rag_stores.qdrant.client.requests.Session",
                return_value=SimpleNamespace(),
            ),
            patch(
                "hawki_rag_stores.qdrant.client.QdrantHTTPGateway",
                return_value=FakeGateway(),
            ),
        ):
            client = QdrantHTTP(
                settings=_qdrant_settings(),
                http_settings=_qdrant_http_settings(search_all=True, fallback_all=True),
            )
            client.select_scoped_collection("hawki_dataset_a")
            client.search_with_text(
                [0.1],
                top_k=2,
                terms=["page"],
                fields=["content"],
                filters={"dataset_id": "dataset-a"},
            )
            client.scroll_with_text(
                terms=["page"],
                fields=["content"],
                limit=2,
                filters={"dataset_id": "dataset-a"},
            )

        mandatory_filter = {
            "must": [{"key": "dataset_id", "match": {"value": "dataset-a"}}]
        }
        strict_text_filter = {
            "must": [
                {
                    "should": [
                        {"key": "content", "match": {"text": "page"}},
                    ]
                }
            ]
        }
        self.assertEqual(
            search_filters[0],
            {"must": [mandatory_filter, strict_text_filter]},
        )
        self.assertEqual(
            scroll_filters[0],
            {"must": [mandatory_filter, strict_text_filter]},
        )

    def test_missing_scoped_collection_fails_without_global_fallback(self) -> None:
        from hawki_rag_stores.qdrant.client import (
            QdrantHTTP,
            ScopedCollectionNotReadyError,
        )

        class FakeGateway:
            collection = "global_default"

            def search(
                self, collection: str, body: dict[str, Any], timeout: float
            ) -> _FakeResponse:
                return _FakeResponse(404)

            def list_collections(self) -> _FakeResponse:
                raise AssertionError("missing scoped collection must not fall back")

        with (
            patch(
                "hawki_rag_stores.qdrant.client.requests.Session",
                return_value=SimpleNamespace(),
            ),
            patch(
                "hawki_rag_stores.qdrant.client.QdrantHTTPGateway",
                return_value=FakeGateway(),
            ),
        ):
            client = QdrantHTTP(
                settings=_qdrant_settings(),
                http_settings=_qdrant_http_settings(search_all=True, fallback_all=True),
            )
            client.select_scoped_collection("missing_collection")
            with self.assertRaises(ScopedCollectionNotReadyError):
                client.search([0.1], filters={"dataset_id": "dataset-a"})


if __name__ == "__main__":
    unittest.main()
