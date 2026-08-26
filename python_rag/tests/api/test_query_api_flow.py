"""Vertical FastAPI flow tests for dataset-scoped query requests.

These tests keep network and model dependencies fake while exercising the HTTP
adapter, request schema, application delegation, and trusted storage scope.
"""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Any
from unittest.mock import Mock, patch

from fastapi import HTTPException
from asgi_client import ASGITestClient as TestClient


PYTHON_RAG_ROOT = Path(__file__).resolve().parents[2]
if str(PYTHON_RAG_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_RAG_ROOT))


AUTHORIZED_SCOPE = {
    "dataset_id": "Gebuehren",
    "qdrant_collection": "hawki_gebuehren",
    "neo4j_namespace": "hawki_gebuehren",
    "embedding_provider": "test-provider",
    "embedding_model": "dataset-embedding-model",
    "graph_enabled": False,
}


class _FakeProvider:
    """Small embedding boundary used by the query workflow."""

    def __init__(self) -> None:
        self.embed_model = "initial-embedding-model"
        self.rag_model = "test-chat-model"
        self.embedded_queries: list[str] = []

    def embed(self, text: str) -> list[float]:
        self.embedded_queries.append(text)
        return [0.25, 0.75]


class _FakeRagService:
    """Injected application service that exposes only the provider boundary."""

    def __init__(self) -> None:
        self.provider = _FakeProvider()
        self.provider_requests: list[str] = []

    def get_provider(self, name: str) -> _FakeProvider:
        self.provider_requests.append(name)
        return self.provider

    def runtime_summary(self) -> dict[str, object]:
        return {"role": "bridge", "mode": "test"}


class _RecordingQdrant:
    """Qdrant fake that records the physical collection selected by authorization."""

    def __init__(self) -> None:
        self.selected_collections: list[str] = []

    def select_scoped_collection(self, collection: str) -> None:
        self.selected_collections.append(collection)


def _build_test_client(tmp_path: Path, service: _FakeRagService) -> TestClient:
    from hawki_bridge.factory import build_app
    from hawki_bridge.settings import load_settings

    settings = load_settings({})
    app = build_app(
        service=service,
        logger_name="test.query_api_flow",
        settings=settings,
    )
    return TestClient(app)


class TestQueryApiFlow:
    """Describe the query contract from HTTP input to authorized retrieval."""

    def test_valid_request_is_validated_and_delegated_with_a_stable_response(
        self,
        tmp_path: Path,
    ) -> None:
        service = _FakeRagService()
        captured: dict[str, Any] = {}

        def query_use_case(
            body: Any, *, rag_service: Any, get_provider: Any, dependencies: Any
        ) -> dict[str, Any]:
            captured["body"] = body
            captured["service"] = rag_service
            captured["provider"] = get_provider(body.provider)
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
            "hawki_bridge.http.routers.query.query_documents",
            side_effect=query_use_case,
        ) as delegate:
            with _build_test_client(tmp_path, service) as client:
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
            "hits": [{"id": "chunk-1", "payload": {"dataset_id": "Gebuehren"}}],
            "kg": [],
            "answer": "",
            "retrieval": {"dataset_id": "Gebuehren"},
        }
        assert response.headers["X-Request-ID"] == "query-success-1"
        assert captured["body"].__class__.__name__ == "QueryRequest"
        assert captured["body"].top_k == 3
        assert captured["service"] is service
        assert captured["dependencies"] is not None
        assert captured["provider"] is service.provider
        assert service.provider_requests == ["test-provider"]
        delegate.assert_called_once()

    def test_missing_authorized_scope_is_rejected_before_delegation(
        self, tmp_path: Path
    ) -> None:
        service = _FakeRagService()
        delegate = Mock()

        with patch("hawki_bridge.http.routers.query.query_documents", delegate):
            with _build_test_client(tmp_path, service) as client:
                response = client.post(
                    "/query",
                    json={
                        "query": "Which fees apply?",
                        "provider": "test-provider",
                    },
                )

        assert response.status_code == 422
        assert set(response.json()) == {"detail"}
        assert any(
            error["loc"] == ["body", "authorized_scope"] and error["type"] == "missing"
            for error in response.json()["detail"]
        )
        delegate.assert_not_called()

    def test_authorized_scope_selects_the_only_collection_and_mandatory_dataset_filter(
        self,
        tmp_path: Path,
    ) -> None:
        from hawki_bridge.application.query.execution import run_query_documents

        service = _FakeRagService()
        qdrant = _RecordingQdrant()
        search_calls: list[dict[str, Any]] = []

        def search_boundary(**kwargs: Any) -> list[dict[str, Any]]:
            search_calls.append(kwargs)
            return [
                {
                    "id": "chunk-authorized",
                    "score": 0.9,
                    "payload": {
                        "component_type": "chunk",
                        "dataset_id": "Gebuehren",
                        "content": "The authorized fee schedule.",
                    },
                }
            ]

        def execute_with_fake_boundaries(
            body: Any,
            *,
            rag_service: Any,
            get_provider: Any,
            **_configured_dependencies: Any,
        ) -> dict[str, Any]:
            return run_query_documents(
                body,
                rag_service=rag_service,
                get_provider=get_provider,
                qdrant_ctor=lambda: qdrant,
                analyze_prompt_fn=lambda query: {
                    "blocked": False,
                    "issues": [],
                    "sanitized": query,
                },
                enforce_output_safety_fn=lambda answer: {
                    "blocked": False,
                    "issues": [],
                    "answer": answer,
                },
                sanitize_prompt_text_fn=lambda value: value,
                build_query_rewrite_fn=lambda _provider, query, **_kwargs: {
                    "enabled": False,
                    "rewritten_query": query,
                    "high_level_keys": [],
                    "low_level_keys": [],
                    "modality_hints": [],
                    "entity_terms": [],
                },
                build_query_terms_fn=lambda *_args: [],
                run_search_fn=search_boundary,
                keyword_fallback_fn=lambda *_args, **_kwargs: [],
                build_structural_hits_fn=lambda *_args, **_kwargs: [],
                structural_hops_fn=lambda: 0,
                structural_limit_fn=lambda top_k: top_k,
                fusion_weights_fn=lambda: (1.0, 0.0),
                rerank_and_filter_hits_fn=lambda hits, **kwargs: hits[
                    : kwargs["top_k"]
                ],
                should_iterate_fn=lambda *_args: False,
                collect_expansion_terms_fn=lambda *_args, **_kwargs: [],
                merge_hits_fn=lambda primary, secondary, limit: (primary + secondary)[
                    :limit
                ],
                build_fused_hits_fn=lambda semantic, structural, **_kwargs: (
                    semantic + structural
                ),
                prepare_context_fn=lambda _hits, **_kwargs: ([], [], 0),
                run_high_recall_fn=lambda **_kwargs: [],
                fetch_related_terms_fn=lambda *_args, **_kwargs: [],
                context_limits_fn=lambda: (1000, 5),
                score_thresholds_fn=lambda: (0.0, 0.0),
                iterative_retrieval_enabled_fn=lambda: False,
                generation_enabled_fn=lambda: False,
                configured_search_top_k_fn=lambda top_k: top_k,
                extract_terms_fn=lambda _text: [],
                terms_from_payload_fn=lambda _payload: [],
            )

        with patch(
            "hawki_bridge.application.query.orchestration.run_query_documents",
            side_effect=execute_with_fake_boundaries,
        ):
            with _build_test_client(tmp_path, service) as client:
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
        assert qdrant.selected_collections == ["hawki_gebuehren"]
        assert len(search_calls) == 1
        assert search_calls[0]["qdrant"] is qdrant
        assert search_calls[0]["filters"] == {
            "topic": "fees",
            "dataset_id": "Gebuehren",
        }
        assert service.provider.embed_model == "dataset-embedding-model"
        assert service.provider.embedded_queries == ["Which fees apply?"]

    def test_structured_query_failure_keeps_the_public_error_envelope(
        self, tmp_path: Path
    ) -> None:
        service = _FakeRagService()
        failure = HTTPException(
            status_code=503,
            detail={
                "code": "dataset_not_ready",
                "message": "The authorized dataset storage is not ready.",
            },
        )

        with patch(
            "hawki_bridge.http.routers.query.query_documents",
            side_effect=failure,
        ):
            with _build_test_client(tmp_path, service) as client:
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
