"""Self-contained deterministic doubles for the query parity harness.

The parity suite must not import doubles from ``tests/api/test_query.py``: the
API tests are free to change for the API's own reasons, and parity has to keep
its own characterization. Only the minimal pattern is copied here.
"""

from __future__ import annotations

import asyncio
import json
from collections.abc import Callable, Mapping
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import httpx

from hawki_backend.auth.errors import AuthenticationError, AuthFailureReason
from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationUnavailableError,
    DatasetGrant,
    InMemoryGrantReader,
)
from hawki_backend.main import create_app
from hawki_backend.query.settings import QuerySettings, load_query_settings
from hawki_rag_retrieval.dependencies import QueryDependencies

FIXTURE_PATH = (
    Path(__file__).resolve().parent / "fixtures" / "laravel_query_parity.json"
)

QUERY_PATH = "/v1/query"


def load_fixture() -> dict[str, Any]:
    """Load the canonical parity fixture and fail closed when it is missing."""

    if not FIXTURE_PATH.is_file():
        raise AssertionError(f"Expected parity fixture: {FIXTURE_PATH}")
    return json.loads(FIXTURE_PATH.read_text(encoding="utf-8"))


def fixture_settings(fixture: Mapping[str, Any] | None = None) -> QuerySettings:
    """Build the trusted scope registry exactly as an operator would."""

    data = fixture or load_fixture()
    settings = load_query_settings(data["registry"]["environ"])
    if settings is None:
        raise AssertionError("Parity fixture must produce query settings")
    return settings


def inventory_by_id(
    fixture: Mapping[str, Any] | None = None,
) -> dict[str, dict[str, Any]]:
    """Return the dataset inventory keyed by logical dataset id."""

    data = fixture or load_fixture()
    return {entry["dataset_id"]: entry for entry in data["dataset_inventory"]}


def principal(
    *,
    scopes: frozenset[str] = frozenset({"rag:query"}),
    tenant_id: str = "tenant-a",
    subject: str = "user-1",
) -> AuthenticatedPrincipal:
    """Return an authenticated principal with the requested attributes."""

    return AuthenticatedPrincipal(
        kind=PrincipalKind.USER,
        subject=subject,
        tenant_id=tenant_id,
        client_id="parity-harness",
        scopes=scopes,
        issuer="https://issuer.example",
        expires_at=datetime(2026, 1, 1, tzinfo=timezone.utc),
    )


def grant(
    dataset_id: str,
    *,
    tenant_id: str = "tenant-a",
    subject: str = "user-1",
    action: AuthorizationAction = AuthorizationAction.QUERY,
) -> DatasetGrant:
    """Return the exact grant for the default parity principal."""

    return DatasetGrant(
        tenant_id=tenant_id,
        principal_kind=PrincipalKind.USER,
        principal_id=subject,
        dataset_id=dataset_id,
        action=action,
    )


def grants_for(
    fixture: Mapping[str, Any] | None = None,
    *,
    tenant_id: str = "tenant-a",
    subject: str = "user-1",
) -> InMemoryGrantReader:
    """Return a grant reader covering every dataset in the inventory."""

    data = fixture or load_fixture()
    return InMemoryGrantReader(
        [
            grant(
                entry["dataset_id"],
                tenant_id=tenant_id,
                subject=subject,
            )
            for entry in data["dataset_inventory"]
        ]
    )


class FakeAuthenticator:
    """Authenticator double returning a principal or raising a fixed error."""

    def __init__(
        self,
        authenticated: AuthenticatedPrincipal | None = None,
        *,
        error: Exception | None = None,
    ) -> None:
        self._principal = authenticated
        self._error = error

    def authenticate(self, _token: str) -> AuthenticatedPrincipal:
        if self._error is not None:
            raise self._error
        if self._principal is None:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        return self._principal


class UnavailableGrantReader:
    """Grant reader that cannot reach its store."""

    def has_grant(self, _principal: Any, _dataset_id: str, _action: Any) -> bool:
        raise AuthorizationUnavailableError()


class FakeProvider:
    """Model provider double recording applied models and invocations."""

    def __init__(
        self,
        *,
        embed: Callable[[str], list[float]] | None = None,
        chat: Callable[[str], str] | None = None,
        embed_error: Exception | None = None,
        chat_error: Exception | None = None,
    ) -> None:
        self.embed_model = "initial"
        self.rag_model = "initial"
        self.vision_model = "initial"
        self.embedded_queries: list[str] = []
        self.chat_calls = 0
        self._embed = embed or (lambda _text: [0.25, 0.75])
        self._chat = chat or (lambda _prompt: "{}")
        self._embed_error = embed_error
        self._chat_error = chat_error

    def embed(self, text: str) -> list[float]:
        self.embedded_queries.append(text)
        if self._embed_error is not None:
            raise self._embed_error
        return self._embed(text)

    def chat(self, *_args: Any, **_kwargs: Any) -> str:
        self.chat_calls += 1
        if self._chat_error is not None:
            raise self._chat_error
        return self._chat(_args[1] if len(_args) > 1 else "")


class RecordingVectorSearch:
    """Vector search double returning fixed hits and recording scope and calls."""

    def __init__(
        self,
        *,
        semantic_hits: list[dict[str, Any]] | None = None,
        lexical_hits: list[dict[str, Any]] | None = None,
        secondary_hits: list[dict[str, Any]] | None = None,
    ) -> None:
        self.semantic_hits = list(semantic_hits or [])
        self.lexical_hits = list(lexical_hits or [])
        self.secondary_hits = list(secondary_hits or [])
        self.selected_collections: list[str] = []
        self.search_calls: list[dict[str, Any]] = []
        self.high_recall_calls: list[dict[str, Any]] = []

    def select_scoped_collection(self, collection: str) -> None:
        self.selected_collections.append(collection)

    def search_candidates(self, **kwargs: Any) -> list[dict[str, Any]]:
        self.search_calls.append(kwargs)
        return [dict(hit) for hit in self.semantic_hits]

    def search_high_recall(self, **kwargs: Any) -> list[dict[str, Any]]:
        self.high_recall_calls.append(kwargs)
        return [dict(hit) for hit in self.secondary_hits]

    def search_with_text(self, *_args: Any, **_kwargs: Any) -> list[dict[str, Any]]:
        return [dict(hit) for hit in self.lexical_hits]

    def scroll_with_text(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return []

    def scroll_with_text_all(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return []


class RecordingGraphSearch:
    """Graph search double returning fixed data and recording trusted scope."""

    def __init__(
        self,
        *,
        structural_hits: list[dict[str, Any]] | None = None,
        facts: list[dict[str, str]] | None = None,
        structural_error: Exception | None = None,
        fact_error: Exception | None = None,
    ) -> None:
        self.structural_hits = list(structural_hits or [])
        self.facts = list(facts or [])
        self.structural_calls: list[dict[str, Any]] = []
        self.fact_calls: list[dict[str, Any]] = []
        self._structural_error = structural_error
        self._fact_error = fact_error

    def build_structural_hits(
        self,
        _terms: list[str],
        **kwargs: Any,
    ) -> list[dict[str, Any]]:
        self.structural_calls.append(kwargs)
        if self._structural_error is not None:
            raise self._structural_error
        return [dict(hit) for hit in self.structural_hits]

    def fetch_related_graph(
        self,
        _terms: list[str],
        **kwargs: Any,
    ) -> list[dict[str, str]]:
        self.fact_calls.append(kwargs)
        if self._fact_error is not None:
            raise self._fact_error
        return [dict(fact) for fact in self.facts]


def identity_rerank(
    *,
    hits: list[dict[str, Any]],
    **_kwargs: Any,
) -> list[dict[str, Any]]:
    """Return the ranked hits unchanged for deterministic comparison."""

    return [dict(hit) for hit in hits]


def dependencies(
    *,
    provider: FakeProvider | None = None,
    vector_search: RecordingVectorSearch | None = None,
    graph_search: RecordingGraphSearch | None = None,
    vector_factory: Callable[[], Any] | None = None,
    rerank: Callable[..., list[dict[str, Any]]] | None = None,
) -> tuple[
    QueryDependencies,
    FakeProvider,
    RecordingVectorSearch,
    RecordingGraphSearch,
]:
    """Return deterministic query dependencies and their collaborators."""

    selected_provider = provider or FakeProvider()
    selected_vector = vector_search or RecordingVectorSearch()
    selected_graph = graph_search or RecordingGraphSearch()
    factory = vector_factory or (lambda: selected_vector)
    return (
        QueryDependencies(
            vector_search_factory=factory,
            graph_search=selected_graph,
            resolve_model_provider=lambda _name: selected_provider,
            rerank_hits=rerank or identity_rerank,
        ),
        selected_provider,
        selected_vector,
        selected_graph,
    )


def build_app(
    *,
    authenticated: AuthenticatedPrincipal | None = None,
    authenticator: FakeAuthenticator | None = None,
    grant_reader: Any = None,
    query_settings: QuerySettings | None = None,
    query_dependencies: QueryDependencies | None = None,
) -> Any:
    """Build a test application with injected authentication and query boundaries."""

    selected = authenticator or FakeAuthenticator(authenticated or principal())
    return create_app(
        authenticator=selected,
        grant_reader=grant_reader if grant_reader is not None else grants_for(),
        query_settings=query_settings or fixture_settings(),
        query_dependencies=query_dependencies or dependencies()[0],
    )


async def apost_query(
    application: Any,
    body: Mapping[str, Any] | None,
    *,
    token: str | None = "valid",
) -> httpx.Response:
    """Issue one asynchronous POST against the query endpoint."""

    headers = {"Authorization": f"Bearer {token}"} if token is not None else {}
    transport = httpx.ASGITransport(app=application)
    async with httpx.AsyncClient(
        transport=transport,
        base_url="http://testserver",
        headers=headers,
    ) as client:
        return await client.post(QUERY_PATH, json=body)


def post_query(
    application: Any,
    body: Mapping[str, Any] | None,
    *,
    token: str | None = "valid",
) -> httpx.Response:
    """Run one POST request to completion."""

    return asyncio.run(apost_query(application, body, token=token))


__all__ = [
    "FIXTURE_PATH",
    "FakeAuthenticator",
    "FakeProvider",
    "RecordingGraphSearch",
    "RecordingVectorSearch",
    "UnavailableGrantReader",
    "build_app",
    "dependencies",
    "fixture_settings",
    "grant",
    "grants_for",
    "identity_rerank",
    "inventory_by_id",
    "load_fixture",
    "post_query",
    "principal",
]
