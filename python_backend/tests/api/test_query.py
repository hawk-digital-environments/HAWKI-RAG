"""Vertical tests for the authenticated, authorized query endpoint."""

from __future__ import annotations

import asyncio
import inspect
import json
import os
from datetime import datetime, timezone
from typing import Any

import httpx
import pytest

from hawki_backend.api.v1.query import _RESERVED_FILTER_KEYS
from hawki_backend.auth.errors import (
    AuthenticationError,
    AuthenticationUnavailableError,
    AuthFailureReason,
)
from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationUnavailableError,
    DatasetGrant,
    InMemoryGrantReader,
    LocalGrantPolicy,
)
from hawki_backend.main import create_app
from hawki_backend.query.settings import (
    DatasetScope,
    ProviderModels,
    QueryConfigurationError,
    QuerySettings,
)
from hawki_rag_retrieval.dependencies import QueryDependencies
from hawki_rag_retrieval.errors import (
    AnswerGenerationError,
    DatasetVectorStoreNotReadyError,
    EmbeddingGenerationError,
    InvalidQueryError,
    UnsupportedModelProviderError,
)

_DATASET = "dataset-a"
_COLLECTION = "hawki_dataset_a"
_NAMESPACE = "secret-namespace"
_PROVIDER = "ollama"
_CHAT_MODEL = "llama3.1"
_VISION_MODEL = "llava"
_EMBEDDING_MODEL = "bge-m3"


def _principal(
    *,
    scopes: frozenset[str] = frozenset({"rag:query"}),
    tenant_id: str = "tenant-a",
) -> AuthenticatedPrincipal:
    """Return an authenticated principal with the requested attributes."""

    return AuthenticatedPrincipal(
        kind=PrincipalKind.USER,
        subject="user-1",
        tenant_id=tenant_id,
        client_id="hawki-console",
        scopes=scopes,
        issuer="https://issuer.example",
        expires_at=datetime(2026, 1, 1, tzinfo=timezone.utc),
    )


def _grant(
    *,
    tenant_id: str = "tenant-a",
    dataset_id: str = _DATASET,
) -> DatasetGrant:
    """Return the exact query grant for the default principal."""

    return DatasetGrant(
        tenant_id=tenant_id,
        principal_kind=PrincipalKind.USER,
        principal_id="user-1",
        dataset_id=dataset_id,
        action=AuthorizationAction.QUERY,
    )


class _FakeAuthenticator:
    """Authenticator double that returns a principal or raises a fixed error."""

    def __init__(
        self,
        principal: AuthenticatedPrincipal | None = None,
        *,
        error: Exception | None = None,
    ) -> None:
        self._principal = principal
        self._error = error

    def authenticate(self, _token: str) -> AuthenticatedPrincipal:
        if self._error is not None:
            raise self._error
        if self._principal is None:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        return self._principal


class _ActiveDatasetReader:
    """Grant reader that treats only the listed datasets as active."""

    def __init__(self, active: set[str]) -> None:
        self._active = active

    def has_grant(self, _principal, dataset_id: str, _action) -> bool:
        return dataset_id in self._active


class _UnavailableGrantReader:
    """Grant reader that cannot reach its store."""

    def has_grant(self, _principal, _dataset_id: str, _action) -> bool:
        raise AuthorizationUnavailableError()


class _FakeProvider:
    """Model provider double that records the applied model selection."""

    def __init__(self) -> None:
        self.embed_model = "initial"
        self.rag_model = "initial"
        self.vision_model = "initial"
        self.embedded_queries: list[str] = []
        self.chat_calls = 0

    def embed(self, text: str) -> list[float]:
        self.embedded_queries.append(text)
        return [0.25, 0.75]

    def chat(self, *_args: Any, **_kwargs: Any) -> str:
        self.chat_calls += 1
        return "{}"


class _RecordingVectorSearch:
    """Vector search double that records the trusted scope it receives."""

    def __init__(self, hits: list[dict[str, Any]] | None = None) -> None:
        self.hits = list(hits or [])
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


class _RecordingGraphSearch:
    """Graph search double that records the trusted scope it receives."""

    def __init__(
        self,
        *,
        structural_hits: list[dict[str, Any]] | None = None,
        facts: list[dict[str, str]] | None = None,
    ) -> None:
        self.structural_hits = list(structural_hits or [])
        self.facts = list(facts or [])
        self.structural_calls: list[dict[str, Any]] = []
        self.fact_calls: list[dict[str, Any]] = []

    def build_structural_hits(
        self,
        _terms: list[str],
        **kwargs: Any,
    ) -> list[dict[str, Any]]:
        self.structural_calls.append(kwargs)
        return list(self.structural_hits)

    def fetch_related_graph(
        self,
        _terms: list[str],
        **kwargs: Any,
    ) -> list[dict[str, str]]:
        self.fact_calls.append(kwargs)
        return list(self.facts)


def _query_settings(
    *,
    graph_enabled: bool = False,
    namespace: str | None = None,
) -> QuerySettings:
    """Return a trusted registry with the default dataset."""

    return QuerySettings(
        datasets={
            _DATASET: DatasetScope(
                qdrant_collection=_COLLECTION,
                embedding_provider=_PROVIDER,
                embedding_model=_EMBEDDING_MODEL,
                neo4j_namespace=namespace,
                graph_enabled=graph_enabled,
            )
        },
        models={
            _PROVIDER: ProviderModels(
                chat_model=_CHAT_MODEL,
                vision_model=_VISION_MODEL,
            )
        },
    )


def _dependencies(
    *,
    provider: _FakeProvider | None = None,
    vector_search: _RecordingVectorSearch | None = None,
    graph_search: _RecordingGraphSearch | None = None,
) -> tuple[
    QueryDependencies,
    _FakeProvider,
    _RecordingVectorSearch,
    _RecordingGraphSearch,
]:
    """Return recording dependencies and their collaborators."""

    selected_provider = provider or _FakeProvider()
    selected_vector = vector_search or _RecordingVectorSearch(hits=[_semantic_hit()])
    selected_graph = graph_search or _RecordingGraphSearch()
    dependencies = QueryDependencies(
        vector_search_factory=lambda: selected_vector,
        graph_search=selected_graph,
        resolve_model_provider=lambda _name: selected_provider,
        rerank_hits=lambda *, hits, **_kwargs: hits,
    )
    return dependencies, selected_provider, selected_vector, selected_graph


def _semantic_hit() -> dict[str, Any]:
    """Return one semantic hit that survives ranking."""

    return {
        "id": "point-1",
        "score": 0.9,
        "payload": {
            "content": "The answer is in the handbook.",
            "doc_id": "doc-semantic",
            "dataset_id": _DATASET,
        },
    }


def _body(**overrides: Any) -> dict[str, Any]:
    """Return a valid public query body with overrides applied."""

    body: dict[str, Any] = {
        "dataset_id": _DATASET,
        "query": "What is the answer?",
    }
    body.update(overrides)
    return body


def _app(
    *,
    principal: AuthenticatedPrincipal | None = None,
    authenticator: _FakeAuthenticator | None = None,
    grant_reader: Any = None,
    query_settings: QuerySettings | None = None,
    query_dependencies: QueryDependencies | None = None,
):
    """Build a test application with injected boundaries."""

    selected_authenticator = authenticator or _FakeAuthenticator(
        principal or _principal()
    )
    selected_reader = (
        grant_reader if grant_reader is not None else InMemoryGrantReader([_grant()])
    )
    selected_dependencies = (
        query_dependencies if query_dependencies is not None else _dependencies()[0]
    )
    return create_app(
        authenticator=selected_authenticator,
        grant_reader=selected_reader,
        query_settings=query_settings or _query_settings(),
        query_dependencies=selected_dependencies,
    )


async def _post(
    application,
    *,
    token: str | None = "valid",
    body: dict[str, Any] | None = None,
) -> httpx.Response:
    """Issue a POST request against a test application."""

    headers = {"Authorization": f"Bearer {token}"} if token is not None else {}
    transport = httpx.ASGITransport(app=application)
    async with httpx.AsyncClient(
        transport=transport,
        base_url="http://testserver",
        headers=headers,
    ) as client:
        return await client.post("/v1/query", json=body)


def _post_json(application, body: dict[str, Any], *, token: str | None = "valid"):
    """Run a POST request to completion."""

    return asyncio.run(_post(application, token=token, body=body))


# ---------------------------------------------------------------- authentication


def test_query_requires_authentication() -> None:
    response = _post_json(_app(), _body(), token=None)

    assert response.status_code == 401
    assert response.json() == {"detail": "authentication_required"}


def test_query_rejects_invalid_token() -> None:
    app = _app(
        authenticator=_FakeAuthenticator(
            error=AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        )
    )

    response = _post_json(app, _body())

    assert response.status_code == 401
    assert response.json()["detail"] == "invalid_token"


def test_query_requires_rag_query_scope() -> None:
    app = _app(principal=_principal(scopes=frozenset()))

    response = _post_json(app, _body())

    assert response.status_code == 403
    assert response.json()["detail"] == "insufficient_scope"


def test_query_authentication_unavailable() -> None:
    app = _app(authenticator=_FakeAuthenticator(error=AuthenticationUnavailableError()))

    response = _post_json(app, _body())

    assert response.status_code == 503
    assert response.json()["detail"] == "authentication_unavailable"


# ---------------------------------------------------------------- authorization


def test_authorized_grant_executes_query() -> None:
    dependencies, provider, vector_search, _ = _dependencies()
    app = _app(query_dependencies=dependencies)

    response = _post_json(app, _body())

    assert response.status_code == 200, response.text
    assert vector_search.selected_collections == [_COLLECTION]
    assert vector_search.search_calls[0]["filters"]["dataset_id"] == _DATASET
    assert provider.embed_model == _EMBEDDING_MODEL
    assert provider.rag_model == _CHAT_MODEL
    assert provider.vision_model == _VISION_MODEL


def test_missing_grant_returns_dataset_not_found() -> None:
    app = _app(grant_reader=InMemoryGrantReader())

    response = _post_json(app, _body())

    assert response.status_code == 404
    assert response.json()["detail"]["error"] == "dataset_not_found"


def test_wrong_tenant_returns_dataset_not_found() -> None:
    dependencies, _, vector_search, _ = _dependencies()
    grants = InMemoryGrantReader([_grant(tenant_id="tenant-a")])
    baseline = _post_json(
        _app(query_dependencies=dependencies, grant_reader=grants), _body()
    )
    assert baseline.status_code == 200
    searches_before_denial = len(vector_search.search_calls)

    app = _app(
        query_dependencies=dependencies,
        principal=_principal(tenant_id="tenant-b"),
        grant_reader=grants,
    )

    response = _post_json(app, _body())

    assert response.status_code == 404
    assert response.json()["detail"]["error"] == "dataset_not_found"
    assert len(vector_search.search_calls) == searches_before_denial


def test_inactive_dataset_returns_dataset_not_found() -> None:
    app = _app(grant_reader=_ActiveDatasetReader(set()))

    response = _post_json(app, _body())

    assert response.status_code == 404
    assert response.json()["detail"]["error"] == "dataset_not_found"


def test_authorization_unavailable_is_not_a_denial() -> None:
    app = _app(grant_reader=_UnavailableGrantReader())

    response = _post_json(app, _body())

    assert response.status_code == 503
    assert response.json()["detail"]["error"] == "authorization_unavailable"


# ------------------------------------------------------------------------ scope


def test_missing_scope_configuration_returns_dataset_not_ready() -> None:
    app = _app(
        query_settings=QuerySettings(datasets={}, models={}),
    )

    response = _post_json(app, _body())

    assert response.status_code == 409
    assert response.json()["detail"]["error"] == "dataset_not_ready"


def test_configured_dataset_and_namespace_reach_graph_reader() -> None:
    structural_hit = {
        "id": "neo4j:a:uses:b",
        "score": 0.9,
        "payload": {
            "component_type": "relation",
            "doc_id": "doc-graph",
            "dataset_id": _DATASET,
            "neo4j_namespace": _NAMESPACE,
            "content": "A -uses-> B",
            "title": "Graph relation",
        },
        "source": "neo4j",
    }
    graph_search = _RecordingGraphSearch(
        structural_hits=[structural_hit],
        facts=[{"subject": "A", "relation": "uses", "object": "B"}],
    )
    dependencies, _, _, _ = _dependencies(graph_search=graph_search)
    app = _app(
        query_settings=_query_settings(
            graph_enabled=True,
            namespace=_NAMESPACE,
        ),
        query_dependencies=dependencies,
    )

    response = _post_json(app, _body(fast_mode=False))

    assert response.status_code == 200, response.text
    assert graph_search.structural_calls[0]["dataset_id"] == _DATASET
    assert graph_search.structural_calls[0]["neo4j_namespace"] == _NAMESPACE
    assert graph_search.fact_calls[0]["dataset_id"] == _DATASET
    assert graph_search.fact_calls[0]["neo4j_namespace"] == _NAMESPACE


@pytest.mark.parametrize(
    ("field", "value"),
    [
        ("tenant_id", "tenant-b"),
        ("authorized_scope", {}),
        ("auth_context", {}),
        ("collection", "x"),
        ("qdrant_collection", "x"),
        ("qdrantCollection", "x"),
        ("neo4j_namespace", "x"),
        ("neo4jNamespace", "x"),
        ("graph_enabled", True),
        ("provider", _PROVIDER),
        ("embedding_provider", _PROVIDER),
        ("embedding_model", _EMBEDDING_MODEL),
        ("chat_model", _CHAT_MODEL),
        ("graph_model", _CHAT_MODEL),
        ("vision_model", _VISION_MODEL),
    ],
)
def test_client_cannot_send_physical_identifiers(field: str, value: Any) -> None:
    body = _body()
    body[field] = value

    response = _post_json(_app(), body)

    assert response.status_code == 422


# -------------------------------------------------------------- request hardening


@pytest.mark.parametrize("key", sorted(_RESERVED_FILTER_KEYS))
def test_reserved_filter_keys_are_rejected(key: str) -> None:
    response = _post_json(_app(), _body(filters={key: "x"}))

    assert response.status_code == 422


@pytest.mark.parametrize(
    "filters",
    [
        {"k" * 201: "value"},
        {"source": "v" * 4001},
    ],
)
def test_oversized_filter_key_or_value_is_rejected(
    filters: dict[str, Any],
) -> None:
    response = _post_json(_app(), _body(filters=filters))

    assert response.status_code == 422


def test_user_filters_keep_the_mandatory_dataset_predicate() -> None:
    dependencies, _, vector_search, _ = _dependencies()
    app = _app(query_dependencies=dependencies)

    response = _post_json(
        app,
        _body(filters={"source": "handbook"}),
    )

    assert response.status_code == 200, response.text
    assert vector_search.search_calls[0]["filters"] == {
        "source": "handbook",
        "dataset_id": _DATASET,
    }


# -------------------------------------------------------------------- execution


def test_successful_query_returns_response_shape() -> None:
    response = _post_json(_app(), _body())

    assert response.status_code == 200, response.text
    payload = response.json()
    assert payload["ok"] is True
    assert payload["count"] == 1
    assert set(payload) >= {"ok", "count", "hits", "kg", "answer", "retrieval"}


def test_fast_mode_disables_graph_retrieval() -> None:
    graph_search = _RecordingGraphSearch()
    dependencies, _, _, _ = _dependencies(graph_search=graph_search)
    app = _app(
        query_settings=_query_settings(
            graph_enabled=True,
            namespace=_NAMESPACE,
        ),
        query_dependencies=dependencies,
    )

    response = _post_json(app, _body(fast_mode=True))

    assert response.status_code == 200, response.text
    assert graph_search.structural_calls == []
    assert graph_search.fact_calls == []


def test_dataset_vector_store_not_ready_maps_to_dataset_not_ready() -> None:
    class _NotReadyFactory:
        def __call__(self):
            raise DatasetVectorStoreNotReadyError()

    dependencies = QueryDependencies(
        vector_search_factory=_NotReadyFactory(),
        graph_search=_RecordingGraphSearch(),
        resolve_model_provider=lambda _name: _FakeProvider(),
        rerank_hits=lambda *, hits, **_kwargs: hits,
    )
    app = _app(query_dependencies=dependencies)

    response = _post_json(app, _body())

    assert response.status_code == 409
    assert response.json()["detail"]["error"] == "dataset_not_ready"


def test_unexpected_failure_maps_to_query_failed() -> None:
    class _BrokenFactory:
        def __call__(self):
            raise RuntimeError("boom")

    dependencies = QueryDependencies(
        vector_search_factory=_BrokenFactory(),
        graph_search=_RecordingGraphSearch(),
        resolve_model_provider=lambda _name: _FakeProvider(),
        rerank_hits=lambda *, hits, **_kwargs: hits,
    )
    app = _app(query_dependencies=dependencies)

    response = _post_json(app, _body())

    assert response.status_code == 500
    assert response.json()["detail"]["error"] == "query_failed"
    assert "boom" not in response.text


@pytest.mark.parametrize(
    ("error", "status", "code"),
    [
        (InvalidQueryError(), 400, "invalid_query"),
        (UnsupportedModelProviderError(), 400, "invalid_query"),
        (EmbeddingGenerationError(), 500, "embedding_failed"),
        (AnswerGenerationError(), 502, "answer_generation_failed"),
    ],
)
def test_retrieval_errors_map_to_stable_public_errors(
    error: Exception,
    status: int,
    code: str,
) -> None:
    """Retrieval failures must not leak their type or message to the client."""

    class _FailingFactory:
        def __call__(self):
            raise error

    dependencies = QueryDependencies(
        vector_search_factory=_FailingFactory(),
        graph_search=_RecordingGraphSearch(),
        resolve_model_provider=lambda _name: _FakeProvider(),
        rerank_hits=lambda *, hits, **_kwargs: hits,
    )
    app = _app(query_dependencies=dependencies)

    response = _post_json(app, _body())

    assert response.status_code == status
    assert response.json()["detail"]["error"] == code
    assert type(error).__name__ not in response.text


# --------------------------------------------------------------------- response


def test_nested_physical_identifiers_are_removed() -> None:
    semantic_hit = _semantic_hit()
    semantic_hit["payload"]["qdrant_collection"] = "secret-collection"
    semantic_hit["payload"]["nested"] = {"neo4j_namespace": "deep-secret"}

    structural_hit = {
        "id": "neo4j:a:uses:b",
        "score": 0.9,
        "payload": {
            "component_type": "relation",
            "doc_id": "doc-graph",
            "dataset_id": _DATASET,
            "neo4j_namespace": _NAMESPACE,
            "content": "A -uses-> B",
            "title": "Graph relation",
            "nested": {"qdrant_collection": "deep-collection"},
        },
        "source": "neo4j",
    }
    vector_search = _RecordingVectorSearch(hits=[semantic_hit])
    graph_search = _RecordingGraphSearch(
        structural_hits=[structural_hit],
        facts=[{"subject": "A", "relation": "uses", "object": "B"}],
    )
    dependencies, _, _, _ = _dependencies(
        vector_search=vector_search,
        graph_search=graph_search,
    )
    app = _app(
        query_settings=_query_settings(
            graph_enabled=True,
            namespace=_NAMESPACE,
        ),
        query_dependencies=dependencies,
    )

    response = _post_json(app, _body(fast_mode=False))

    assert response.status_code == 200, response.text
    serialized = json.dumps(response.json())
    assert "secret-namespace" not in serialized
    assert "secret-collection" not in serialized
    assert "deep-secret" not in serialized
    assert "deep-collection" not in serialized
    assert "neo4j_namespace" not in serialized
    assert "qdrant_collection" not in serialized


# --------------------------------------------------------------------- contract


def test_query_route_is_synchronous() -> None:
    from hawki_backend.api.v1.query import query

    assert not inspect.iscoroutinefunction(query)


def test_unconfigured_query_service_fails_closed(monkeypatch) -> None:
    """No scope registry means no query execution, never a guessed fallback."""

    for name in list(os.environ):
        if name.startswith("HAWKI_BACKEND_QUERY_"):
            monkeypatch.delenv(name, raising=False)

    application = create_app(
        authenticator=_FakeAuthenticator(_principal()),
        policy=LocalGrantPolicy(InMemoryGrantReader([_grant()])),
    )

    response = _post_json(application, _body())

    assert response.status_code == 503
    assert response.json()["detail"]["error"] == "query_unavailable"


def test_create_app_rejects_invalid_query_configuration(monkeypatch) -> None:
    """A malformed scope registry must stop the application at startup."""

    monkeypatch.setenv("HAWKI_BACKEND_QUERY_DATASETS", "{not-json")

    with pytest.raises(QueryConfigurationError):
        create_app(
            authenticator=_FakeAuthenticator(_principal()),
            policy=LocalGrantPolicy(InMemoryGrantReader()),
        )
