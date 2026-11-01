"""Public response sanitization for the query boundary.

Structural graph hits currently carry physical identifiers in their payload.
The shared retrieval package keeps that behaviour in this epoch, so the public
boundary removes those fields before a response leaves the service.
"""

from __future__ import annotations

from typing import Any

from hawki_rag_contracts.retrieval.query import QueryResponse

_PHYSICAL_KEYS = frozenset(
    {
        "neo4j_namespace",
        "neo4jNamespace",
        "qdrant_collection",
        "qdrantCollection",
    }
)


def sanitize_query_response(response: QueryResponse) -> QueryResponse:
    """Return the logical response shape without physical storage identifiers."""

    return QueryResponse.model_validate(
        _sanitize_value(response.model_dump(mode="json"))
    )


def _sanitize_value(value: Any) -> Any:
    """Recursively remove physical identifier keys from mappings and lists."""

    if isinstance(value, dict):
        return {
            key: _sanitize_value(item)
            for key, item in value.items()
            if key not in _PHYSICAL_KEYS
        }
    if isinstance(value, list):
        return [_sanitize_value(item) for item in value]
    return value


__all__ = ["sanitize_query_response"]
