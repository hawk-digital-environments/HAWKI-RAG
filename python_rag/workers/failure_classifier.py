from __future__ import annotations

import asyncio
import json
from typing import Any

from pydantic import ValidationError

FAILURE_TRANSIENT = "transient"
FAILURE_PERMANENT = "permanent"


class TransientIngestionError(Exception):
    """Known retryable ingestion error."""


class PermanentIngestionError(Exception):
    """Known non-retryable ingestion error."""


def classify_failure(exc: Exception) -> str:
    # Permanent classes first.
    if isinstance(exc, (PermanentIngestionError, ValidationError, json.JSONDecodeError, UnicodeDecodeError)):
        return FAILURE_PERMANENT
    if isinstance(exc, (FileNotFoundError, IsADirectoryError)):
        return FAILURE_PERMANENT

    # Transient classes.
    if isinstance(exc, (TransientIngestionError, TimeoutError, asyncio.TimeoutError, ConnectionError)):
        return FAILURE_TRANSIENT

    # Optional dependencies / runtime error types.
    try:  # pragma: no cover - optional dependency in tests
        from neo4j.exceptions import ServiceUnavailable as Neo4jServiceUnavailable

        if isinstance(exc, Neo4jServiceUnavailable):
            return FAILURE_TRANSIENT
    except Exception:
        pass

    lowered = f"{type(exc).__name__}: {exc}".lower()

    permanent_markers = (
        "invalid schema",
        "validation error",
        "unsupported output format",
        "path outside allowed root",
        "outside shared storage root",
        "converted file missing",
        "empty converted document",
        "deterministic parsing failure",
        "no valid content to ingest",
    )
    if any(marker in lowered for marker in permanent_markers):
        return FAILURE_PERMANENT

    transient_markers = (
        "timeout",
        "temporarily unavailable",
        "temporary qdrant failure",
        "temporary neo4j failure",
        "temporary embedding model failure",
        "temporary filesystem issue",
        "temporary db connection issue",
        "connection reset",
        "connection refused",
        "service unavailable",
        "rate limit",
        "too many requests",
    )
    if any(marker in lowered for marker in transient_markers):
        return FAILURE_TRANSIENT

    # Default to transient to keep at-least-once delivery semantics.
    return FAILURE_TRANSIENT


def error_name(exc: Exception) -> str:
    return type(exc).__name__


def is_permanent(exc: Exception) -> bool:
    return classify_failure(exc) == FAILURE_PERMANENT


__all__ = [
    "FAILURE_PERMANENT",
    "FAILURE_TRANSIENT",
    "PermanentIngestionError",
    "TransientIngestionError",
    "classify_failure",
    "error_name",
    "is_permanent",
]

