"""Vertical FastAPI tests for dataset-scoped query requests."""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any
from unittest.mock import Mock, patch

import pytest

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
        assert captured["body"].fast_mode is True
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


@pytest.fixture
def production_query_client(monkeypatch, asgi_test_client_class):
    """Keep composition and ranking real; replace only external dependencies."""
    from hawki_bridge import composition
    from hawki_reranking import local_reranker
    from hawki_bridge.factory import build_app
    from hawki_bridge.settings import load_settings
    import litellm
    from litellm.types.rerank import RerankResponse

    def make(
        backend,
        model,
        *,
        mode=None,
        fail=False,
        primary_error=None,
        fallback_error=None,
        fallback_override=None,
        gateway_base="https://gateway.invalid",
    ):
        calls, model_names, prompts = [], [], []
        provider = _FakeProvider()

        def chat(system, messages, **kwargs):
            prompts.append(messages[0]["content"])
            return "A grounded answer [Source 1]."

        provider.chat = chat
        hits = [
            {
                "id": "first",
                "score": 0.9,
                "payload": {
                    "doc_id": "doc",
                    "content": "compare fees first",
                    "title": "First source",
                    "dataset_id": "Gebuehren",
                    "chunk_id": "one",
                },
            },
            {
                "id": "second",
                "score": 0.1,
                "payload": {
                    "doc_id": "doc",
                    "content": "compare fees second",
                    "title": "Second source",
                    "dataset_id": "Gebuehren",
                    "chunk_id": "two",
                },
            },
        ]
        vector = _RecordingVectorSearch(hits)
        expansion_filters = []

        def high_recall(**kwargs):
            expansion_filters.append(kwargs["filters"])
            return list(hits)

        vector.search_high_recall = high_recall

        class MockReranker:
            def predict(self, pairs):
                calls.append(pairs)
                if fail:
                    raise RuntimeError("private model failure")
                return [0.9 if "second" in document else 0.1 for _, document in pairs]

        def model_factory(name):
            model_names.append(name)
            return MockReranker()

        def sdk(**kwargs):
            calls.append(kwargs)
            if primary_error is not None and kwargs["model"] == model:
                raise primary_error
            if fallback_error is not None and kwargs["model"] != model:
                raise fallback_error
            if fail:
                raise RuntimeError("private SDK failure")
            return RerankResponse(
                results=[
                    {
                        "index": index,
                        "relevance_score": 0.9 if "second" in document else 0.1,
                    }
                    for index, document in reversed(
                        list(enumerate(kwargs["documents"]))
                    )
                ]
            )

        monkeypatch.setattr(local_reranker, "_create_cross_encoder", model_factory)
        monkeypatch.setattr(litellm, "rerank", sdk)
        monkeypatch.setattr(composition, "QdrantReader", lambda: vector)
        monkeypatch.setattr(composition, "Neo4jReader", _EmptyGraphSearch)
        monkeypatch.setattr(composition, "create_model_provider", lambda _: provider)
        monkeypatch.setenv("RAG_GENERATE_ANSWER", "true")
        monkeypatch.setenv("RAG_ITERATIVE_RETRIEVAL", "true")
        settings = load_settings(
            {
                "RERANKER_MODE": "external",
                "RERANKER_PROVIDER": backend,
                "HAWKI_RERANKER_MODEL": model,
                "RERANKER_MODEL": model,
                "LITELLM_PROXY_API_BASE": gateway_base,
                "LITELLM_PROXY_API_KEY": "test-placeholder",
                "RERANKER_MIX_MODE": "false",
                **(
                    {"RERANKER_FALLBACK_MODEL": fallback_override}
                    if fallback_override is not None
                    else {}
                ),
            }
        )
        client = asgi_test_client_class(build_app(settings=settings))
        body = {
            "query": "compare fees",
            "authorized_scope": AUTHORIZED_SCOPE,
            "provider": "test-provider",
            "chat_model": "test-chat-model",
            "vision_model": "test-vision-model",
            "top_k": 2,
            "rerank_top_n": 2,
        }
        if mode is not None:
            body["reranker"] = mode
        return (
            client,
            body,
            calls,
            model_names,
            prompts,
            vector,
            provider,
            expansion_filters,
        )

    return make


@pytest.mark.parametrize(
    "backend,model",
    [
        ("local", "mixedbread-ai/mxbai-rerank-base-v1"),
        ("local", "BAAI/bge-reranker-v2-m3"),
        ("litellm", "litellm_proxy/jina-rerank"),
        ("litellm", "cohere/rerank-v3.5"),
    ],
)
@pytest.mark.parametrize("mode", [None, "external"])
def test_production_backends_rank_expand_and_generate(
    production_query_client, backend, model, mode
):
    client, body, calls, names, prompts, vector, provider, filters = (
        production_query_client(backend, model, mode=mode)
    )
    assert calls == names == []
    for _ in range(2):
        response = client.post("/query", json=body)
        assert response.status_code == 200, response.text
        result = response.json()
        assert [hit["id"] for hit in result["hits"]] == ["second", "first"]
        assert result["hits"][0]["payload"] == vector.hits[1]["payload"]
        assert result["retrieval"]["iterative_pass"] is True
        assert result["answer"] == "A grounded answer [Source 1]."
    assert len(calls) == 4
    assert all(f == {"dataset_id": "Gebuehren"} for f in filters)
    assert all(
        call["filters"] == {"dataset_id": "Gebuehren"} for call in vector.search_calls
    )
    assert vector.selected_collections == ["hawki_gebuehren"] * 2
    assert provider.embed_model == "dataset-embedding-model"
    assert all(p.index("Second source") < p.index("First source") for p in prompts)
    if backend == "local":
        assert names == [model]
    else:
        assert names == []
        for call in calls:
            assert call["model"] == model
            assert sorted(call["documents"]) == [
                "compare fees first",
                "compare fees second",
            ]
            assert call["top_n"] == 2
            assert call["timeout"] == 30
            assert call["num_retries"] == call["max_retries"] == 0
            assert call["return_documents"] is False
            if model.startswith("litellm_proxy/"):
                assert call["api_base"] == "https://gateway.invalid"
                assert call["api_key"] == "test-placeholder"
            else:
                assert "api_base" not in call and "api_key" not in call


@pytest.mark.parametrize(
    "gateway_base", ["https://gateway.invalid/v1", "https://gateway.invalid/v1/"]
)
def test_gateway_base_v1_suffix_is_normalized_before_sdk_dispatch(
    production_query_client, gateway_base
):
    client, body, calls, *_ = production_query_client(
        "litellm", "litellm_proxy/jina-rerank", gateway_base=gateway_base
    )
    response = client.post("/query", json=body)
    assert response.status_code == 200, response.text
    assert calls
    assert all(call["api_base"] == "https://gateway.invalid" for call in calls)


@pytest.mark.parametrize("backend", ["local", "litellm"])
def test_production_disabled_override_skips_inference(production_query_client, backend):
    client, body, calls, names, *_ = production_query_client(
        backend, "test/model", mode="none"
    )
    assert client.post("/query", json=body).status_code == 200
    assert calls == names == []


@pytest.mark.parametrize("backend", ["local", "litellm"])
def test_production_backend_failures_continue_query(
    production_query_client, backend, caplog
):
    client, body, calls, _, prompts, *_ = production_query_client(
        backend, "test/model", fail=True
    )
    response = client.post("/query", json=body)
    assert response.status_code == 200
    assert [hit["id"] for hit in response.json()["hits"]] == ["first", "second"]
    assert len(calls) == 2
    assert prompts[0].index("First source") < prompts[0].index("Second source")
    assert "private model failure" not in caplog.text
    assert "private SDK failure" not in caplog.text


@pytest.mark.parametrize(
    "outcome", ["primary_success", "jlu_success", "both_fail", "auth_error", "disabled"]
)
def test_production_ixdlab_fallback_preserves_query_policy(
    production_query_client, outcome, caplog
):
    import litellm

    primary = "litellm_proxy/jina-rerank"
    fallback = "litellm_proxy/jlu/jina-rerank"
    timeout = litellm.Timeout(
        "private exception", model=primary, llm_provider="litellm_proxy"
    )
    error = None if outcome == "primary_success" else timeout
    if outcome == "auth_error":
        error = litellm.AuthenticationError(
            "private exception", model=primary, llm_provider="litellm_proxy"
        )
    client, body, calls, _, _, vector, provider, filters = production_query_client(
        "litellm",
        primary,
        gateway_base="https://ai.ixdlab.hawk.de",
        primary_error=error,
        fallback_error=timeout if outcome == "both_fail" else None,
        fallback_override="none" if outcome == "disabled" else None,
    )
    response = client.post("/query", json=body)
    assert response.status_code == 200
    retried = outcome in {"jlu_success", "both_fail"}
    assert [call["model"] for call in calls] == (
        [primary, fallback] * 2 if retried else [primary] * 2
    )
    success = outcome in {"primary_success", "jlu_success"}
    assert [h["id"] for h in response.json()["hits"]] == (
        ["second", "first"] if success else ["first", "second"]
    )
    assert response.json()["retrieval"]["iterative_pass"] is True
    for hit in response.json()["hits"]:
        assert hit["payload"] == next(
            h["payload"] for h in vector.hits if h["id"] == hit["id"]
        )
    for call in calls:
        assert call["api_base"] == "https://ai.ixdlab.hawk.de"
        assert call["api_key"] == "test-placeholder"
        assert call["timeout"] == 30
        assert call["num_retries"] == call["max_retries"] == 0
        assert call["top_n"] == 2
    assert all(f == {"dataset_id": "Gebuehren"} for f in filters)
    assert provider.embed_model == "dataset-embedding-model"
    assert "private exception" not in caplog.text
