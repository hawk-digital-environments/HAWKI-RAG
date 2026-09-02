"""Vertical FastAPI tests for dataset-scoped query requests."""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any
from unittest.mock import Mock, patch

AUTHORIZED_SCOPE = {
    "dataset_id": "Gebuehren",
    "qdrant_collection": "hawki_gebuehren",
    "neo4j_namespace": "hawki_gebuehren",
    "embedding_provider": "test-provider",
    "embedding_model": "dataset-embedding-model",
    "graph_enabled": False,
}


class _FakeProvider:
    def __init__(self) -> None:
        self.embed_model = "initial-embedding-model"
        self.rag_model = "test-chat-model"
        self.vision_model = "test-vision-model"
        self.embedded_queries: list[str] = []

    def embed(self, text: str) -> list[float]:
        self.embedded_queries.append(text)
        return [0.25, 0.75]

    def chat(self, *_args: Any, **_kwargs: Any) -> str:
        return ""


class _RecordingVectorSearch:
    def __init__(self, hits: list[dict[str, Any]] | None = None) -> None:
        self.hits = hits or []
        self.selected_collections: list[str] = []
        self.search_calls: list[dict[str, Any]] = []

    def select_scoped_collection(self, collection: str) -> None:
        self.selected_collections.append(collection)

    def search_candidates(self, **kwargs: Any) -> list[dict[str, Any]]:
        self.search_calls.append(kwargs)
        return list(self.hits)

    def search_high_recall(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return []

    def search_with_text(self, *_args: Any, **_kwargs: Any) -> list[dict[str, Any]]:
        return []

    def scroll_with_text(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return []

    def scroll_with_text_all(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return []


class _EmptyGraphSearch:
    def build_structural_hits(self, *_args: Any, **_kwargs: Any) -> list[Any]:
        return []

    def fetch_related_graph(self, *_args: Any, **_kwargs: Any) -> list[Any]:
        return []


def _dependencies(
    *,
    provider: _FakeProvider | None = None,
    vector_search: _RecordingVectorSearch | None = None,
):
    from hawki_bridge.application.dependencies import QueryDependencies

    selected_provider = provider or _FakeProvider()
    selected_vector_search = vector_search or _RecordingVectorSearch()
    return QueryDependencies(
        vector_search_factory=lambda: selected_vector_search,
        graph_search=_EmptyGraphSearch(),
        resolve_model_provider=lambda _name: selected_provider,
        rerank_hits=lambda *, hits, **kwargs: hits,
    )


def _build_test_client(
    tmp_path: Path,
    dependencies: Any,
    asgi_test_client_class: type[Any],
) -> Any:
    from hawki_bridge.factory import build_app
    from hawki_bridge.settings import load_settings

    del tmp_path
    app = build_app(
        query_dependencies=dependencies,
        runtime_summary=lambda: {"role": "bridge", "mode": "test"},
        logger_name="test.query_api_flow",
        settings=load_settings({}),
    )
    return asgi_test_client_class(app)


class TestQueryApiFlow:
    """Describe the query contract from HTTP input to authorized retrieval."""

    def test_valid_request_is_validated_and_delegated_with_a_stable_response(
        self,
        tmp_path: Path,
        asgi_test_client_class: type[Any],
    ) -> None:
        dependencies = _dependencies()
        captured: dict[str, Any] = {}

        def query_use_case(body: Any, *, dependencies: Any) -> dict[str, Any]:
            captured["body"] = body
            captured["dependencies"] = dependencies
            return {
                "ok": True,
                "count": 1,
                "hits": [{"id": "chunk-1", "payload": {"dataset_id": "Gebuehren"}}],
                "kg": [],
                "answer": "",
                "retrieval": {"dataset_id": body.authorized_scope.dataset_id},
            }

        with patch(
            "hawki_bridge.http.routers.query.execute_authorized_query",
            side_effect=query_use_case,
        ) as delegate:
            with _build_test_client(
                tmp_path, dependencies, asgi_test_client_class
            ) as client:
                response = client.post(
                    "/query",
                    headers={"X-Request-ID": "query-success-1"},
                    json={
                        "query": "Which fees apply?",
                        "authorized_scope": AUTHORIZED_SCOPE,
                        "provider": "test-provider",
                        "chat_model": "test-chat-model",
                        "vision_model": "test-vision-model",
                        "top_k": 3,
                        "generate": False,
                    },
                )

        assert response.status_code == 200
        assert response.json() == {
            "ok": True,
            "count": 1,
            "hits": [
                {
                    "id": "chunk-1",
                    "score": None,
                    "payload": {"dataset_id": "Gebuehren"},
                }
            ],
            "kg": [],
            "answer": "",
            "retrieval": {"dataset_id": "Gebuehren"},
        }
        assert response.headers["X-Request-ID"] == "query-success-1"
        assert captured["body"].__class__.__name__ == "QueryRequest"
        assert captured["body"].top_k == 3
        assert captured["dependencies"] is dependencies
        delegate.assert_called_once()

    def test_missing_authorized_scope_is_rejected_before_delegation(
        self, tmp_path: Path, asgi_test_client_class: type[Any]
    ) -> None:
        delegate = Mock()

        with patch(
            "hawki_bridge.http.routers.query.execute_authorized_query", delegate
        ):
            with _build_test_client(
                tmp_path, _dependencies(), asgi_test_client_class
            ) as client:
                response = client.post(
                    "/query",
                    json={"query": "Which fees apply?", "provider": "test-provider"},
                )

        assert response.status_code == 422
        assert any(
            error["loc"] == ["body", "authorized_scope"] and error["type"] == "missing"
            for error in response.json()["detail"]
        )
        delegate.assert_not_called()

    def test_authorized_scope_controls_collection_and_dataset_filter(
        self,
        tmp_path: Path,
        asgi_test_client_class: type[Any],
    ) -> None:
        provider = _FakeProvider()
        vector_search = _RecordingVectorSearch(
            [
                {
                    "id": "chunk-authorized",
                    "score": 0.9,
                    "payload": {
                        "component_type": "chunk",
                        "dataset_id": "Gebuehren",
                        "content": "Which fees apply in the authorized fee schedule.",
                    },
                }
            ]
        )
        dependencies = _dependencies(
            provider=provider,
            vector_search=vector_search,
        )

        with patch.dict(
            os.environ,
            {
                "RAG_GENERATE_ANSWER": "false",
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
            },
            clear=False,
        ):
            with _build_test_client(
                tmp_path, dependencies, asgi_test_client_class
            ) as client:
                response = client.post(
                    "/query",
                    json={
                        "query": "Which fees apply?",
                        "authorized_scope": AUTHORIZED_SCOPE,
                        "provider": "test-provider",
                        "chat_model": "test-chat-model",
                        "vision_model": "test-vision-model",
                        "filters": {
                            "dataset_id": "OtherDataset",
                            "qdrantCollection": "global_collection",
                            "topic": "fees",
                        },
                        "top_k": 2,
                        "generate": False,
                    },
                )

        assert response.status_code == 200
        assert response.json()["retrieval"]["dataset_id"] == "Gebuehren"
        assert [hit["id"] for hit in response.json()["hits"]] == ["chunk-authorized"]
        assert vector_search.selected_collections == ["hawki_gebuehren"]
        assert len(vector_search.search_calls) == 1
        assert vector_search.search_calls[0]["filters"] == {
            "topic": "fees",
            "dataset_id": "Gebuehren",
        }
        assert provider.embed_model == "dataset-embedding-model"
        assert provider.embedded_queries == ["Which fees apply?"]

    def test_application_failure_keeps_the_public_error_envelope(
        self, tmp_path: Path, asgi_test_client_class: type[Any]
    ) -> None:
        from hawki_bridge.domain.errors import DatasetVectorStoreNotReadyError

        with patch(
            "hawki_bridge.http.routers.query.execute_authorized_query",
            side_effect=DatasetVectorStoreNotReadyError("missing"),
        ):
            with _build_test_client(
                tmp_path, _dependencies(), asgi_test_client_class
            ) as client:
                response = client.post(
                    "/query",
                    headers={"X-Request-ID": "query-error-1"},
                    json={
                        "query": "Which fees apply?",
                        "authorized_scope": AUTHORIZED_SCOPE,
                        "provider": "test-provider",
                        "chat_model": "test-chat-model",
                        "vision_model": "test-vision-model",
                    },
                )

        assert response.status_code == 503
        assert response.json() == {
            "error": {
                "type": "HTTPException",
                "status": 503,
                "message": "The authorized dataset storage is not ready.",
                "path": "/query",
                "request_id": "query-error-1",
                "code": "dataset_not_ready",
            }
        }
