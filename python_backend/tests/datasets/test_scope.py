"""Tests for the dataset authority selector, scope resolution and startup wiring."""

from __future__ import annotations

import pytest

from hawki_backend.authorization.errors import AuthorizationUnavailableError
from hawki_backend.datasets import DatasetService
from hawki_backend.main import create_app
from hawki_backend.persistence.datasets import (
    DatabaseDatasetScopeRegistry,
    DatasetStoreUnavailableError,
)
from hawki_backend.query.settings import (
    DatasetScope,
    QueryConfigurationError,
    QuerySettings,
    load_query_settings,
)
from hawki_backend.settings import DatabaseSettings

from .doubles import (
    TENANT,
    FakeAuthenticator,
    FakeDatasetStore,
    record,
    service_principal,
)

MODELS = '{"ollama":{"chat_model":"llama3.1","vision_model":"llava"}}'


def _settings(**extra: str) -> dict[str, str]:
    return {
        "HAWKI_BACKEND_QUERY_MODELS": MODELS,
        **extra,
    }


# ------------------------------------------------------------------- selector


def test_environment_is_the_default_dataset_source() -> None:
    settings = load_query_settings(
        _settings(
            HAWKI_BACKEND_QUERY_DATASETS=(
                '{"dataset-a":{"qdrant_collection":"hawki_a",'
                '"embedding_provider":"ollama","embedding_model":"bge-m3"}}'
            )
        )
    )

    assert settings is not None
    assert settings.datasets["dataset-a"].qdrant_collection == "hawki_a"
    assert settings.dataset_scope_resolver is None


def test_a_database_source_requires_a_resolver() -> None:
    with pytest.raises(QueryConfigurationError):
        load_query_settings(_settings(HAWKI_BACKEND_QUERY_DATASET_SOURCE="database"))


def test_a_resolver_requires_the_database_source() -> None:
    resolver = DatabaseDatasetScopeRegistry(FakeDatasetStore())

    with pytest.raises(QueryConfigurationError):
        load_query_settings(_settings(), dataset_scope_resolver=resolver)
    with pytest.raises(QueryConfigurationError):
        load_query_settings(
            _settings(HAWKI_BACKEND_QUERY_DATASET_SOURCE="environment"),
            dataset_scope_resolver=resolver,
        )


def test_database_mode_forbids_the_static_dataset_registry() -> None:
    resolver = DatabaseDatasetScopeRegistry(FakeDatasetStore())

    with pytest.raises(QueryConfigurationError):
        load_query_settings(
            _settings(
                HAWKI_BACKEND_QUERY_DATASET_SOURCE="database",
                HAWKI_BACKEND_QUERY_DATASETS='{"dataset-a":{}}',
            ),
            dataset_scope_resolver=resolver,
        )


def test_database_mode_carries_only_the_resolver_and_models() -> None:
    store = FakeDatasetStore()
    resolver = DatabaseDatasetScopeRegistry(store)
    settings = load_query_settings(
        _settings(HAWKI_BACKEND_QUERY_DATASET_SOURCE="database"),
        dataset_scope_resolver=resolver,
    )

    assert settings is not None
    assert dict(settings.datasets) == {}
    assert settings.dataset_scope_resolver is resolver
    assert settings.models_for("ollama") is not None


def test_an_unknown_dataset_source_is_refused() -> None:
    with pytest.raises(QueryConfigurationError):
        load_query_settings(_settings(HAWKI_BACKEND_QUERY_DATASET_SOURCE="laravel"))


def test_two_authorities_cannot_be_combined_in_memory() -> None:
    resolver = DatabaseDatasetScopeRegistry(FakeDatasetStore())

    with pytest.raises(ValueError):
        QuerySettings(
            datasets={"dataset-a": DatasetScope("hawki_a", "ollama", "bge-m3")},
            dataset_scope_resolver=resolver,
        )


# --------------------------------------------------------------- scope lookup


def test_the_registry_resolves_only_active_complete_scope() -> None:
    store = FakeDatasetStore()
    store.add(record(dataset_id="dataset-a"))
    store.add(record(dataset_id="archived", status="archived"))
    store.add(record(dataset_id="incomplete", collection=""))
    registry = DatabaseDatasetScopeRegistry(store)

    scope = registry.resolve_scope("dataset-a", TENANT)

    assert scope == DatasetScope(
        qdrant_collection="hawki_dataset_a",
        embedding_provider="ollama",
        embedding_model="bge-m3",
        neo4j_namespace="hawki_dataset_a",
        graph_enabled=False,
    )
    assert registry.resolve_scope("archived", TENANT) is None
    assert registry.resolve_scope("incomplete", TENANT) is None
    assert registry.resolve_scope("missing", TENANT) is None
    assert registry.resolve_scope("dataset-a", "tenant-b") is None


def test_a_missing_tenant_is_an_authorization_infrastructure_failure() -> None:
    registry = DatabaseDatasetScopeRegistry(FakeDatasetStore())

    with pytest.raises(AuthorizationUnavailableError):
        registry.resolve_scope("dataset-a")


def test_a_store_outage_is_an_authorization_infrastructure_failure() -> None:
    store = FakeDatasetStore()
    store.add(record())
    store.unavailable = True
    registry = DatabaseDatasetScopeRegistry(store)

    with pytest.raises(AuthorizationUnavailableError):
        registry.resolve_scope("dataset-a", TENANT)


def test_query_settings_pass_the_authenticated_tenant_to_the_resolver() -> None:
    class SpyResolver:
        def __init__(self) -> None:
            self.calls: list[tuple[str, str | None]] = []

        def resolve_scope(self, dataset_id: str, tenant_id: str | None = None):
            self.calls.append((dataset_id, tenant_id))
            return None

    resolver = SpyResolver()
    settings = QuerySettings(dataset_scope_resolver=resolver)

    assert settings.resolve_scope("dataset-a", "tenant-a") is None
    assert resolver.calls == [("dataset-a", "tenant-a")]


# ------------------------------------------------------------------- startup


def test_an_invalid_runtime_selector_fails_startup(monkeypatch) -> None:
    monkeypatch.setenv("HAWKI_BACKEND_QUERY_DATASET_SOURCE", "laravel")

    with pytest.raises(QueryConfigurationError):
        create_app(authenticator=FakeAuthenticator(service_principal()))


def test_database_mode_without_a_database_fails_startup(monkeypatch) -> None:
    monkeypatch.setenv("HAWKI_BACKEND_QUERY_DATASET_SOURCE", "database")

    with pytest.raises(QueryConfigurationError):
        create_app(authenticator=FakeAuthenticator(service_principal()))


def test_database_mode_exposes_the_dataset_router_without_restart(monkeypatch) -> None:
    monkeypatch.setenv("HAWKI_BACKEND_QUERY_DATASET_SOURCE", "database")
    monkeypatch.setenv("HAWKI_BACKEND_QUERY_MODELS", MODELS)
    app = create_app(
        authenticator=FakeAuthenticator(service_principal()),
        database_settings=DatabaseSettings("postgresql+psycopg://user@host/db"),
    )

    paths = set(app.openapi()["paths"])
    assert "/v1/datasets" in paths
    assert "/v1/query/datasets" in paths
    assert isinstance(app.state.dataset_service, DatasetService)
    assert app.state.query_settings.dataset_scope_resolver is not None


def test_database_mode_requires_the_model_registry(monkeypatch) -> None:
    monkeypatch.setenv("HAWKI_BACKEND_QUERY_DATASET_SOURCE", "database")

    with pytest.raises(QueryConfigurationError):
        create_app(
            authenticator=FakeAuthenticator(service_principal()),
            database_settings=DatabaseSettings("postgresql+psycopg://user@host/db"),
        )


def test_store_unavailability_never_becomes_a_scope_denial() -> None:
    store = FakeDatasetStore()
    store.unavailable = True
    registry = DatabaseDatasetScopeRegistry(store)

    with pytest.raises(AuthorizationUnavailableError) as error:
        registry.resolve_scope("dataset-a", TENANT)

    assert not isinstance(error.value, DatasetStoreUnavailableError)
