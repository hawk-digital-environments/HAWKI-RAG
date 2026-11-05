"""Trusted authorization-scope helpers for document queries.

The control plane authorizes the logical dataset and derives its physical
storage targets. Caller metadata filters cannot change that trusted scope.
"""

from __future__ import annotations

import math
from typing import Any

from hawki_bridge.domain.ports import ScopedFilters


_RESERVED_FILTER_KEYS = frozenset(
    {
        "auth_context",
        "authorized_scope",
        "collection",
        "dataset_id",
        "graph_enabled",
        "neo4j_namespace",
        "qdrant_collection",
    }
)


def normalize_filter_key(key: str) -> str:
    """Normalize public filter keys before checking authorization-owned names."""

    characters: list[str] = []
    for index, character in enumerate(key.strip()):
        if character == "-":
            characters.append("_")
        elif character.isupper():
            if index > 0:
                characters.append("_")
            characters.append(character.lower())
        else:
            characters.append(character.lower())
    return "".join(characters)


def build_scoped_query_filters(
    dataset_id: str,
    user_filters: dict[str, Any] | None,
) -> ScopedFilters:
    """Combine sanitized user metadata with the mandatory dataset predicate.

    The mandatory value is written last so a caller cannot replace it. Storage
    routing keys are not user-searchable metadata and are removed as well.
    """

    sanitized_filters: ScopedFilters = {}
    for key, value in (user_filters or {}).items():
        normalized_key = str(key).strip()
        if (
            not normalized_key
            or normalize_filter_key(normalized_key) in _RESERVED_FILTER_KEYS
        ):
            continue
        if not isinstance(value, (str, int, float, bool)):
            raise ValueError(f"Unsupported query filter value for '{normalized_key}'.")
        if isinstance(value, float) and not math.isfinite(value):
            raise ValueError(
                f"Query filter value for '{normalized_key}' must be finite."
            )
        sanitized_filters[normalized_key] = value

    sanitized_filters["dataset_id"] = dataset_id
    return sanitized_filters


__all__ = ["build_scoped_query_filters"]
