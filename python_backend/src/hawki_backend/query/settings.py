"""Strict, startup-validated query scope and model configuration.

The registry is a *migration-time* source of physical dataset scope. It is
populated by an operator from the deployment's dataset inventory, never from
Laravel's ``public`` tables, and this service never writes it. It exists so
the Python query API can resolve trusted Qdrant and Neo4j targets before
Python owns dataset metadata itself; it is expected to be replaced by a
Python-owned dataset registry in a later migration epoch.

Only server-side configuration is read. A client can never supply a
collection, namespace, provider or model through the public query contract.
"""

from __future__ import annotations

import json
import math
import os
import re
from collections.abc import Mapping
from dataclasses import dataclass, field
from types import MappingProxyType

_PREFIX = "HAWKI_BACKEND_QUERY_"

_KNOWN_SUFFIXES = frozenset(
    {
        "DATASETS",
        "MODELS",
        "RERANKER",
        "RERANK_TOP_N",
        "RERANKER_MIX_MODE",
        "RERANKER_MIX_WEIGHT",
    }
)

_DATASET_KEYS = frozenset(
    {
        "qdrant_collection",
        "neo4j_namespace",
        "embedding_provider",
        "embedding_model",
        "graph_enabled",
    }
)
_MODEL_KEYS = frozenset({"chat_model", "vision_model"})

_DATASET_ID_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$")
_PROVIDER_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*$")
_MODEL_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$")
_INTEGER_PATTERN = re.compile(r"^[0-9]+$")

_MAX_COLLECTION_LENGTH = 191
_MAX_NAMESPACE_LENGTH = 191
_MAX_PROVIDER_LENGTH = 80
_MAX_MODEL_LENGTH = 160
_MAX_RERANKER_LENGTH = 32

_DEFAULT_RERANKER = "none"
_DEFAULT_RERANK_TOP_N = 20
_DEFAULT_MIX_MODE = True
_DEFAULT_MIX_WEIGHT = 0.5


class QueryConfigurationError(Exception):
    """Raised when the query scope configuration is missing or invalid.

    This is a startup failure, not a request failure. A service must not boot
    with a partially understood query scope registry.
    """


@dataclass(frozen=True, slots=True)
class DatasetScope:
    """Trusted physical scope for one logical dataset."""

    qdrant_collection: str
    embedding_provider: str
    embedding_model: str
    neo4j_namespace: str | None = None
    graph_enabled: bool = False


@dataclass(frozen=True, slots=True)
class ProviderModels:
    """Server-owned generation models for one model provider."""

    chat_model: str
    vision_model: str


@dataclass(frozen=True, slots=True)
class QuerySettings:
    """Validated query scope, model and reranker defaults.

    Instances are immutable: the dataset and model registries are read-only
    mappings, so a request cannot widen or replace them.
    """

    datasets: Mapping[str, DatasetScope] = field(hash=False)
    models: Mapping[str, ProviderModels] = field(hash=False)
    reranker: str = _DEFAULT_RERANKER
    rerank_top_n: int = _DEFAULT_RERANK_TOP_N
    reranker_mix_mode: bool = _DEFAULT_MIX_MODE
    reranker_mix_weight: float = _DEFAULT_MIX_WEIGHT

    def __post_init__(self) -> None:
        """Freeze the registries so the settings cannot be mutated."""

        object.__setattr__(self, "datasets", MappingProxyType(dict(self.datasets)))
        object.__setattr__(self, "models", MappingProxyType(dict(self.models)))

    def resolve_scope(self, dataset_id: str) -> DatasetScope | None:
        """Return the trusted scope for a dataset, or ``None`` when absent."""

        return self.datasets.get(dataset_id)

    def models_for(self, provider: str) -> ProviderModels | None:
        """Return the server-owned models for a provider, or ``None``."""

        return self.models.get(provider.strip().lower())


def load_query_settings(
    environ: Mapping[str, str] | None = None,
) -> QuerySettings | None:
    """Load the query scope configuration from an environment mapping.

    Args:
        environ: The environment to read. Defaults to ``os.environ`` when it is
            not supplied, so the call site decides what is read.

    Returns:
        The validated configuration, or ``None`` when no
        ``HAWKI_BACKEND_QUERY_*`` variable is present. ``None`` means the
        query scope registry is not configured and the query route must fail
        closed.

    Raises:
        QueryConfigurationError: When any query variable is present but the
            configuration is incomplete or invalid.
    """

    source = os.environ if environ is None else environ
    present = {name for name in source if name.startswith(_PREFIX)}
    if not present:
        return None

    _reject_unknown_names(present)
    datasets = _datasets(source)
    models = _models(source)
    _require_models_for_datasets(datasets, models)

    return QuerySettings(
        datasets=datasets,
        models=models,
        reranker=_reranker(source),
        rerank_top_n=_integer(
            source,
            "RERANK_TOP_N",
            _DEFAULT_RERANK_TOP_N,
            1,
            1000,
        ),
        reranker_mix_mode=_boolean(
            source,
            "RERANKER_MIX_MODE",
            _DEFAULT_MIX_MODE,
        ),
        reranker_mix_weight=_float(
            source,
            "RERANKER_MIX_WEIGHT",
            _DEFAULT_MIX_WEIGHT,
            0.0,
            1.0,
        ),
    )


def _env_name(suffix: str) -> str:
    """Return the full environment variable name for a query suffix."""

    return f"{_PREFIX}{suffix}"


def _reject_unknown_names(present: set[str]) -> None:
    """Reject an unknown ``HAWKI_BACKEND_QUERY_*`` variable as a typo."""

    known = {_env_name(suffix) for suffix in _KNOWN_SUFFIXES}
    unknown = sorted(present - known)
    if unknown:
        raise QueryConfigurationError(
            "unknown query configuration: " + ", ".join(unknown)
        )


def _unique_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    """Reject a JSON object that defines the same key more than once."""

    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise QueryConfigurationError(
                f"query configuration defines {key!r} more than once"
            )
        result[key] = value
    return result


def _json_object(raw: str, suffix: str) -> dict[str, object]:
    """Parse a required JSON object from one configuration variable."""

    try:
        parsed = json.loads(raw, object_pairs_hook=_unique_object)
    except json.JSONDecodeError as error:
        raise QueryConfigurationError(
            f"{_env_name(suffix)} must be a JSON object"
        ) from error
    if not isinstance(parsed, dict):
        raise QueryConfigurationError(f"{_env_name(suffix)} must be a JSON object")
    return parsed


def _datasets(source: Mapping[str, str]) -> Mapping[str, DatasetScope]:
    """Parse and validate the dataset scope registry."""

    raw = source.get(_env_name("DATASETS"))
    if raw is None:
        raise QueryConfigurationError(f"{_env_name('DATASETS')} is required")
    parsed = _json_object(raw, "DATASETS")
    if not parsed:
        raise QueryConfigurationError(
            f"{_env_name('DATASETS')} must define at least one dataset"
        )

    datasets: dict[str, DatasetScope] = {}
    for dataset_id, entry in parsed.items():
        _require_dataset_id(dataset_id)
        if not isinstance(entry, dict):
            raise QueryConfigurationError(
                f"dataset {dataset_id!r} must map to a JSON object"
            )
        unknown = sorted(set(entry) - _DATASET_KEYS)
        if unknown:
            raise QueryConfigurationError(
                f"dataset {dataset_id!r} has unknown keys: " + ", ".join(unknown)
            )

        collection = _entry_text(
            entry,
            "qdrant_collection",
            dataset_id,
            _MAX_COLLECTION_LENGTH,
        )
        provider = _entry_text(
            entry,
            "embedding_provider",
            dataset_id,
            _MAX_PROVIDER_LENGTH,
        ).lower()
        if not _PROVIDER_PATTERN.fullmatch(provider):
            raise QueryConfigurationError(
                f"dataset {dataset_id!r} has an invalid embedding_provider"
            )
        model = _entry_text(
            entry,
            "embedding_model",
            dataset_id,
            _MAX_MODEL_LENGTH,
        )
        if not _MODEL_PATTERN.fullmatch(model):
            raise QueryConfigurationError(
                f"dataset {dataset_id!r} has an invalid embedding_model"
            )

        graph_enabled = entry.get("graph_enabled", False)
        if not isinstance(graph_enabled, bool):
            raise QueryConfigurationError(
                f"dataset {dataset_id!r} graph_enabled must be a boolean"
            )

        namespace = _optional_namespace(entry, dataset_id)
        if graph_enabled and namespace is None:
            raise QueryConfigurationError(
                f"dataset {dataset_id!r} enables graph retrieval without a "
                "neo4j_namespace"
            )

        datasets[dataset_id] = DatasetScope(
            qdrant_collection=collection,
            embedding_provider=provider,
            embedding_model=model,
            neo4j_namespace=namespace,
            graph_enabled=graph_enabled,
        )
    return datasets


def _models(source: Mapping[str, str]) -> Mapping[str, ProviderModels]:
    """Parse and validate the server-owned provider model registry."""

    raw = source.get(_env_name("MODELS"))
    if raw is None:
        raise QueryConfigurationError(f"{_env_name('MODELS')} is required")
    parsed = _json_object(raw, "MODELS")
    if not parsed:
        raise QueryConfigurationError(
            f"{_env_name('MODELS')} must define at least one provider"
        )

    models: dict[str, ProviderModels] = {}
    for provider, entry in parsed.items():
        provider_key = provider.strip().lower()
        if not provider_key or not _PROVIDER_PATTERN.fullmatch(provider_key):
            raise QueryConfigurationError(
                f"model registry contains an invalid provider {provider!r}"
            )
        if provider_key in models:
            raise QueryConfigurationError(
                f"model registry defines provider {provider_key!r} more than once"
            )
        if not isinstance(entry, dict):
            raise QueryConfigurationError(
                f"provider {provider_key!r} must map to a JSON object"
            )
        unknown = sorted(set(entry) - _MODEL_KEYS)
        if unknown:
            raise QueryConfigurationError(
                f"provider {provider_key!r} has unknown keys: " + ", ".join(unknown)
            )

        chat_model = _entry_text(
            entry,
            "chat_model",
            provider_key,
            _MAX_MODEL_LENGTH,
        )
        if not _MODEL_PATTERN.fullmatch(chat_model):
            raise QueryConfigurationError(
                f"provider {provider_key!r} has an invalid chat_model"
            )
        vision_model = _entry_text(
            entry,
            "vision_model",
            provider_key,
            _MAX_MODEL_LENGTH,
        )
        if not _MODEL_PATTERN.fullmatch(vision_model):
            raise QueryConfigurationError(
                f"provider {provider_key!r} has an invalid vision_model"
            )

        models[provider_key] = ProviderModels(
            chat_model=chat_model,
            vision_model=vision_model,
        )
    return models


def _require_models_for_datasets(
    datasets: Mapping[str, DatasetScope],
    models: Mapping[str, ProviderModels],
) -> None:
    """Require a model entry for every provider a dataset references."""

    for dataset_id, scope in datasets.items():
        if scope.embedding_provider not in models:
            raise QueryConfigurationError(
                f"dataset {dataset_id!r} references provider "
                f"{scope.embedding_provider!r} without a MODELS entry"
            )


def _require_dataset_id(dataset_id: str) -> None:
    """Reject a registry key that cannot be a public dataset identifier."""

    if not isinstance(dataset_id, str) or not _DATASET_ID_PATTERN.fullmatch(dataset_id):
        raise QueryConfigurationError(
            f"query configuration contains an invalid dataset_id {dataset_id!r}"
        )


def _entry_text(
    entry: Mapping[str, object],
    key: str,
    owner: str,
    max_length: int,
) -> str:
    """Return a required, non-empty, bounded text value from an entry."""

    value = entry.get(key)
    if not isinstance(value, str):
        raise QueryConfigurationError(
            f"dataset/provider {owner!r} requires a string {key}"
        )
    text = value.strip()
    if not text:
        raise QueryConfigurationError(
            f"dataset/provider {owner!r} requires a non-empty {key}"
        )
    if len(text) > max_length:
        raise QueryConfigurationError(f"dataset/provider {owner!r} {key} is too long")
    return text


def _optional_namespace(
    entry: Mapping[str, object],
    dataset_id: str,
) -> str | None:
    """Return a validated optional Neo4j namespace."""

    value = entry.get("neo4j_namespace")
    if value is None:
        return None
    if not isinstance(value, str):
        raise QueryConfigurationError(
            f"dataset {dataset_id!r} neo4j_namespace must be a string"
        )
    namespace = value.strip()
    if not namespace:
        raise QueryConfigurationError(
            f"dataset {dataset_id!r} neo4j_namespace must not be empty"
        )
    if len(namespace) > _MAX_NAMESPACE_LENGTH:
        raise QueryConfigurationError(
            f"dataset {dataset_id!r} neo4j_namespace is too long"
        )
    if any(character.isspace() for character in namespace):
        raise QueryConfigurationError(
            f"dataset {dataset_id!r} neo4j_namespace must not contain whitespace"
        )
    return namespace


def _reranker(source: Mapping[str, str]) -> str:
    """Return the server-owned default reranker mode."""

    raw = source.get(_env_name("RERANKER"))
    if raw is None:
        return _DEFAULT_RERANKER
    text = raw.strip()
    if not text:
        raise QueryConfigurationError(f"{_env_name('RERANKER')} must not be empty")
    if len(text) > _MAX_RERANKER_LENGTH:
        raise QueryConfigurationError(f"{_env_name('RERANKER')} is too long")
    return text


def _boolean(source: Mapping[str, str], suffix: str, default: bool) -> bool:
    """Return a strict boolean value, falling back to the default."""

    raw = source.get(_env_name(suffix))
    if raw is None:
        return default
    text = raw.strip().lower()
    if text in {"1", "true", "yes", "on"}:
        return True
    if text in {"0", "false", "no", "off"}:
        return False
    raise QueryConfigurationError(f"{_env_name(suffix)} must be a boolean")


def _integer(
    source: Mapping[str, str],
    suffix: str,
    default: int,
    minimum: int,
    maximum: int,
) -> int:
    """Return a bounded integer value, falling back to the default."""

    raw = source.get(_env_name(suffix))
    if raw is None:
        return default
    text = raw.strip()
    if not _INTEGER_PATTERN.fullmatch(text):
        raise QueryConfigurationError(f"{_env_name(suffix)} must be an integer")
    value = int(text)
    if value < minimum or value > maximum:
        raise QueryConfigurationError(
            f"{_env_name(suffix)} must be between {minimum} and {maximum}"
        )
    return value


def _float(
    source: Mapping[str, str],
    suffix: str,
    default: float,
    minimum: float,
    maximum: float,
) -> float:
    """Return a bounded finite float, falling back to the default."""

    raw = source.get(_env_name(suffix))
    if raw is None:
        return default
    try:
        value = float(raw.strip())
    except ValueError as error:
        raise QueryConfigurationError(
            f"{_env_name(suffix)} must be a number"
        ) from error
    if not math.isfinite(value) or value < minimum or value > maximum:
        raise QueryConfigurationError(
            f"{_env_name(suffix)} must be between {minimum} and {maximum}"
        )
    return value


__all__ = [
    "DatasetScope",
    "ProviderModels",
    "QueryConfigurationError",
    "QuerySettings",
    "load_query_settings",
]
