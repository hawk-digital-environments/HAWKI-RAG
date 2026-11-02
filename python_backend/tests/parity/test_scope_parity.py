"""Scope parity between the Laravel dataset inventory and the Python registry.

The Python query registry is migration-time configuration. It must resolve the
same logical dataset, physical collection, namespace, embedding provider and
model, graph flag and model selection as the Laravel-owned inventory. The
fixture is test-only; production code never reads it.
"""

from __future__ import annotations

from copy import deepcopy
from typing import Any

import pytest

from hawki_backend.api.v1.query import QueryRequestBody
from hawki_backend.query.settings import (
    DatasetScope,
    ProviderModels,
    QueryConfigurationError,
    QuerySettings,
    load_query_settings,
)

from .doubles import fixture_settings, inventory_by_id, load_fixture

FIXTURE = load_fixture()
SETTINGS = fixture_settings(FIXTURE)

# The fixture is a contract. Bump this constant and the fixture together; a
# silent fixture edit must not be able to change what parity means.
EXPECTED_INVENTORY_VERSION = 1


def inventory_problems(
    settings: QuerySettings,
    inventory: list[dict[str, Any]],
) -> list[str]:
    """Return every difference between a registry and the inventory fixture."""

    problems: list[str] = []
    expected_ids = {entry["dataset_id"] for entry in inventory}
    registry_ids = set(settings.datasets)

    for dataset_id in sorted(expected_ids - registry_ids):
        problems.append(f"missing dataset {dataset_id!r}")
    for dataset_id in sorted(registry_ids - expected_ids):
        problems.append(f"extra dataset {dataset_id!r}")

    for entry in inventory:
        dataset_id = entry["dataset_id"]
        scope = settings.datasets.get(dataset_id)
        if scope is None:
            continue
        for field in (
            "qdrant_collection",
            "neo4j_namespace",
            "embedding_provider",
            "embedding_model",
            "graph_enabled",
        ):
            if getattr(scope, field) != entry[field]:
                problems.append(
                    f"{dataset_id}.{field}: {getattr(scope, field)!r} != {entry[field]!r}"
                )

        models = settings.models_for(scope.embedding_provider)
        if models is None:
            problems.append(
                f"{dataset_id} provider {scope.embedding_provider!r} has no models"
            )
            continue
        if models.chat_model != entry["chat_model"]:
            problems.append(
                f"{dataset_id}.chat_model: {models.chat_model!r} != "
                f"{entry['chat_model']!r}"
            )
        if models.vision_model != entry["vision_model"]:
            problems.append(
                f"{dataset_id}.vision_model: {models.vision_model!r} != "
                f"{entry['vision_model']!r}"
            )

        if settings.reranker != entry["expected_reranker"]:
            problems.append(
                f"{dataset_id}.reranker: {settings.reranker!r} != "
                f"{entry['expected_reranker']!r}"
            )
        if settings.rerank_top_n != entry["rerank_top_n"]:
            problems.append(
                f"{dataset_id}.rerank_top_n: {settings.rerank_top_n!r} != "
                f"{entry['rerank_top_n']!r}"
            )
        if settings.reranker_mix_mode != entry["mix_mode"]:
            problems.append(
                f"{dataset_id}.mix_mode: {settings.reranker_mix_mode!r} != "
                f"{entry['mix_mode']!r}"
            )
        if settings.reranker_mix_weight != entry["mix_weight"]:
            problems.append(
                f"{dataset_id}.mix_weight: {settings.reranker_mix_weight!r} != "
                f"{entry['mix_weight']!r}"
            )

    return problems


def _settings_with(
    *,
    datasets: dict[str, DatasetScope] | None = None,
    models: dict[str, ProviderModels] | None = None,
) -> QuerySettings:
    """Return a copy of the fixture registry with selected overrides."""

    return QuerySettings(
        datasets=datasets if datasets is not None else dict(SETTINGS.datasets),
        models=models if models is not None else dict(SETTINGS.models),
        reranker=SETTINGS.reranker,
        rerank_top_n=SETTINGS.rerank_top_n,
        reranker_mix_mode=SETTINGS.reranker_mix_mode,
        reranker_mix_weight=SETTINGS.reranker_mix_weight,
    )


def test_fixture_inventory_version_is_pinned() -> None:
    """A fixture edit must be a deliberate contract change."""

    assert FIXTURE["_meta"]["inventory_version"] == EXPECTED_INVENTORY_VERSION


def test_registry_resolves_exactly_the_inventory_fixture() -> None:
    """Every inventory field resolves exactly, with no missing or extra dataset."""

    assert inventory_problems(SETTINGS, FIXTURE["dataset_inventory"]) == []


def test_provider_is_the_dataset_embedding_provider() -> None:
    """The internal request can never fall back to a different vector space."""

    for entry in FIXTURE["dataset_inventory"]:
        body = QueryRequestBody(
            dataset_id=entry["dataset_id"],
            query="delta epsilon",
        )
        scope = SETTINGS.resolve_scope(entry["dataset_id"])
        assert scope is not None
        models = SETTINGS.models_for(scope.embedding_provider)
        assert models is not None

        request = body.to_query_request(
            scope=scope,
            models=models,
            defaults=SETTINGS,
        )

        assert request.provider == entry["embedding_provider"]
        assert request.provider == request.authorized_scope.embedding_provider
        assert request.chat_model == entry["chat_model"]
        assert request.vision_model == entry["vision_model"]
        assert request.authorized_scope.qdrant_collection == entry["qdrant_collection"]
        assert request.authorized_scope.neo4j_namespace == entry["neo4j_namespace"]
        assert request.authorized_scope.graph_enabled == entry["graph_enabled"]


def test_graph_enabled_requires_a_namespace() -> None:
    """The registry invariant holds for the fixture and for each scope."""

    for entry in FIXTURE["dataset_inventory"]:
        if entry["graph_enabled"]:
            assert entry["neo4j_namespace"], entry["dataset_id"]

    for dataset_id, scope in SETTINGS.datasets.items():
        if scope.graph_enabled:
            assert scope.neo4j_namespace, dataset_id


def test_drift_guard_detects_a_missing_dataset() -> None:
    """A registry that silently drops a dataset must be reported."""

    inventory = FIXTURE["dataset_inventory"]
    datasets = dict(SETTINGS.datasets)
    datasets.pop(inventory[0]["dataset_id"])

    problems = inventory_problems(_settings_with(datasets=datasets), inventory)

    assert any("missing dataset" in problem for problem in problems)


def test_drift_guard_detects_an_extra_dataset() -> None:
    """A registry entry with no inventory counterpart must be reported."""

    inventory = FIXTURE["dataset_inventory"]
    datasets = dict(SETTINGS.datasets)
    datasets["dataset-unlisted"] = DatasetScope(
        qdrant_collection="hawki_dataset_unlisted",
        embedding_provider="ollama",
        embedding_model="bge-m3",
    )

    problems = inventory_problems(_settings_with(datasets=datasets), inventory)

    assert any("extra dataset" in problem for problem in problems)


def test_drift_guard_detects_a_provider_model_mismatch() -> None:
    """A provider whose models drift from the inventory must be reported."""

    inventory = FIXTURE["dataset_inventory"]
    models = dict(SETTINGS.models)
    provider = next(iter(models))
    models[provider] = ProviderModels(chat_model="different-model", vision_model="x")

    problems = inventory_problems(_settings_with(models=models), inventory)

    assert any("chat_model" in problem for problem in problems)


def test_drift_guard_detects_a_scope_value_mismatch() -> None:
    """Any changed physical target must be reported."""

    inventory = FIXTURE["dataset_inventory"]
    first = inventory[0]
    datasets = dict(SETTINGS.datasets)
    datasets[first["dataset_id"]] = DatasetScope(
        qdrant_collection="drifted_collection",
        embedding_provider=first["embedding_provider"],
        embedding_model=first["embedding_model"],
        neo4j_namespace=first["neo4j_namespace"],
        graph_enabled=first["graph_enabled"],
    )

    problems = inventory_problems(_settings_with(datasets=datasets), inventory)

    assert any("qdrant_collection" in problem for problem in problems)


def test_loader_rejects_graph_enabled_without_namespace() -> None:
    """The graph invariant is enforced by the production loader, not the test."""

    environ = deepcopy(FIXTURE["registry"]["environ"])
    environ["HAWKI_BACKEND_QUERY_DATASETS"] = (
        '{"dataset-graph":{"qdrant_collection":"hawki_dataset_graph",'
        '"embedding_provider":"openai","embedding_model":"text-embedding-3-large",'
        '"graph_enabled":true}}'
    )

    with pytest.raises(QueryConfigurationError):
        load_query_settings(environ)


def test_loader_rejects_a_provider_without_models() -> None:
    """Every referenced provider must have a server-owned model entry."""

    environ = deepcopy(FIXTURE["registry"]["environ"])
    environ["HAWKI_BACKEND_QUERY_MODELS"] = (
        '{"ollama":{"chat_model":"llama3.1","vision_model":"llava"}}'
    )

    with pytest.raises(QueryConfigurationError):
        load_query_settings(environ)


def test_loader_rejects_an_unknown_query_variable() -> None:
    """A typo must fail at startup instead of being silently ignored."""

    environ = deepcopy(FIXTURE["registry"]["environ"])
    environ["HAWKI_BACKEND_QUERY_UNKNOWN"] = "value"

    with pytest.raises(QueryConfigurationError):
        load_query_settings(environ)


def test_fixture_inventory_matches_registry_ids() -> None:
    """The inventory helper and the registry agree on the logical dataset set."""

    assert set(inventory_by_id(FIXTURE)) == set(SETTINGS.datasets)
