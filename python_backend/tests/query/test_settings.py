"""Tests for the strict, startup-validated query scope registry."""

from __future__ import annotations

import dataclasses
import json

import pytest

from hawki_backend.query.settings import (
    DatasetScope,
    ProviderModels,
    QueryConfigurationError,
    QuerySettings,
    load_query_settings,
)


def _env(**overrides: str) -> dict[str, str]:
    """Return a valid query environment with overrides applied."""

    base = {
        "HAWKI_BACKEND_QUERY_DATASETS": json.dumps(
            {
                "dataset-a": {
                    "qdrant_collection": "hawki_dataset_a",
                    "neo4j_namespace": "hawki_dataset_a",
                    "embedding_provider": "ollama",
                    "embedding_model": "bge-m3",
                    "graph_enabled": True,
                }
            }
        ),
        "HAWKI_BACKEND_QUERY_MODELS": json.dumps(
            {
                "ollama": {
                    "chat_model": "llama3.1",
                    "vision_model": "llava",
                }
            }
        ),
    }
    base.update(overrides)
    return base


def test_no_query_variables_means_not_configured() -> None:
    """An environment without query variables must not invent a registry."""

    assert load_query_settings({}) is None


def test_valid_configuration_is_parsed_and_normalized() -> None:
    """A complete configuration yields immutable trusted scope and models."""

    settings = load_query_settings(_env())

    assert settings is not None
    scope = settings.resolve_scope("dataset-a")
    assert scope == DatasetScope(
        qdrant_collection="hawki_dataset_a",
        embedding_provider="ollama",
        embedding_model="bge-m3",
        neo4j_namespace="hawki_dataset_a",
        graph_enabled=True,
    )
    assert settings.models_for("OLLAMA") == ProviderModels(
        chat_model="llama3.1",
        vision_model="llava",
    )
    assert settings.reranker == "none"
    assert settings.rerank_top_n == 20
    assert settings.reranker_mix_mode is True
    assert settings.reranker_mix_weight == 0.5


def test_registries_are_read_only() -> None:
    """A request must not be able to mutate the trusted registry."""

    settings = load_query_settings(_env())
    assert settings is not None

    with pytest.raises(TypeError):
        settings.datasets["dataset-b"] = DatasetScope(  # type: ignore[index]
            qdrant_collection="x",
            embedding_provider="ollama",
            embedding_model="bge-m3",
        )


def test_unknown_query_variable_is_rejected() -> None:
    """A typo must fail at startup, not silently fall back."""

    with pytest.raises(QueryConfigurationError, match="unknown query configuration"):
        load_query_settings(_env(HAWKI_BACKEND_QUERY_DATASET="{}"))


def test_malformed_dataset_json_is_rejected() -> None:
    """A non-JSON dataset registry must fail at startup."""

    with pytest.raises(QueryConfigurationError, match="must be a JSON object"):
        load_query_settings(_env(HAWKI_BACKEND_QUERY_DATASETS="{not-json"))


def test_missing_models_registry_is_rejected() -> None:
    """A dataset registry without models cannot build an internal request."""

    env = _env()
    del env["HAWKI_BACKEND_QUERY_MODELS"]

    with pytest.raises(QueryConfigurationError, match="MODELS.*required"):
        load_query_settings(env)


@pytest.mark.parametrize(
    "field",
    ["qdrant_collection", "embedding_provider", "embedding_model"],
)
def test_missing_required_scope_field_is_rejected(field: str) -> None:
    """Every dataset entry must provide the required trusted fields."""

    entry = {
        "qdrant_collection": "hawki_dataset_a",
        "embedding_provider": "ollama",
        "embedding_model": "bge-m3",
    }
    entry.pop(field)
    env = _env(
        HAWKI_BACKEND_QUERY_DATASETS=json.dumps({"dataset-a": entry}),
    )

    with pytest.raises(QueryConfigurationError):
        load_query_settings(env)


def test_graph_enabled_without_namespace_is_rejected() -> None:
    """Graph retrieval cannot be enabled without a trusted namespace."""

    env = _env(
        HAWKI_BACKEND_QUERY_DATASETS=json.dumps(
            {
                "dataset-a": {
                    "qdrant_collection": "hawki_dataset_a",
                    "embedding_provider": "ollama",
                    "embedding_model": "bge-m3",
                    "graph_enabled": True,
                }
            }
        ),
    )

    with pytest.raises(QueryConfigurationError, match="neo4j_namespace"):
        load_query_settings(env)


def test_unknown_dataset_key_is_rejected() -> None:
    """A dataset entry must not carry an unrecognized scope key."""

    env = _env(
        HAWKI_BACKEND_QUERY_DATASETS=json.dumps(
            {
                "dataset-a": {
                    "qdrant_collection": "hawki_dataset_a",
                    "embedding_provider": "ollama",
                    "embedding_model": "bge-m3",
                    "access_scope_id": "nope",
                }
            }
        ),
    )

    with pytest.raises(QueryConfigurationError, match="unknown keys"):
        load_query_settings(env)


def test_provider_without_models_entry_is_rejected() -> None:
    """Every referenced provider must have server-owned models."""

    env = _env(
        HAWKI_BACKEND_QUERY_DATASETS=json.dumps(
            {
                "dataset-a": {
                    "qdrant_collection": "hawki_dataset_a",
                    "embedding_provider": "litellm",
                    "embedding_model": "bge-m3",
                }
            }
        ),
    )

    with pytest.raises(QueryConfigurationError, match="without a MODELS entry"):
        load_query_settings(env)


def test_duplicate_json_key_is_rejected() -> None:
    """A duplicated dataset definition must not be resolved silently."""

    raw = (
        '{"dataset-a": {"qdrant_collection": "a", "embedding_provider": '
        '"ollama", "embedding_model": "m"}, '
        '"dataset-a": {"qdrant_collection": "b", "embedding_provider": '
        '"ollama", "embedding_model": "m"}}'
    )
    env = _env(HAWKI_BACKEND_QUERY_DATASETS=raw)

    with pytest.raises(QueryConfigurationError, match="more than once"):
        load_query_settings(env)


@pytest.mark.parametrize("weight", ["-0.1", "1.1", "nan", "inf"])
def test_invalid_mix_weight_is_rejected(weight: str) -> None:
    """Reranker defaults must stay finite and bounded."""

    env = _env(HAWKI_BACKEND_QUERY_RERANKER_MIX_WEIGHT=weight)

    with pytest.raises(QueryConfigurationError):
        load_query_settings(env)


def test_optional_reranker_defaults_are_parsed() -> None:
    """Server-owned reranker defaults are configurable."""

    settings = load_query_settings(
        _env(
            HAWKI_BACKEND_QUERY_RERANKER="cross-encoder",
            HAWKI_BACKEND_QUERY_RERANK_TOP_N="12",
            HAWKI_BACKEND_QUERY_RERANKER_MIX_MODE="false",
            HAWKI_BACKEND_QUERY_RERANKER_MIX_WEIGHT="0.25",
        )
    )

    assert settings is not None
    assert settings.reranker == "cross-encoder"
    assert settings.rerank_top_n == 12
    assert settings.reranker_mix_mode is False
    assert settings.reranker_mix_weight == 0.25


def test_query_settings_rejects_mutation_of_defaults() -> None:
    """The settings object is frozen."""

    settings = QuerySettings(datasets={}, models={})

    with pytest.raises(dataclasses.FrozenInstanceError):
        settings.reranker = "other"  # type: ignore[misc]
