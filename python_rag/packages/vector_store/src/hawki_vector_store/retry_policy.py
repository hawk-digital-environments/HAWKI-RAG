"""Qdrant-specific retry and idempotency policy."""

from __future__ import annotations

import requests

RETRY_SAFE_WRITE_OPERATIONS = frozenset(
    {
        "qdrant.upsert_points",
        "qdrant.delete_by_filter",
    }
)
QDRANT_RETRYABLE_STATUS_CODES = frozenset({429, 500, 502, 503, 504})
QDRANT_RETRYABLE_EXCEPTIONS = (
    requests.exceptions.ConnectTimeout,
    requests.exceptions.ConnectionError,
    requests.exceptions.ReadTimeout,
    requests.exceptions.Timeout,
)


def is_retry_safe_write(operation: str | None) -> bool:
    """Return whether an idempotency-keyed Qdrant write may be retried."""

    return bool(operation and operation in RETRY_SAFE_WRITE_OPERATIONS)


def is_retryable_http_exception(exc: Exception) -> bool:
    """Classify the requests exceptions that are transient for Qdrant."""

    if isinstance(exc, QDRANT_RETRYABLE_EXCEPTIONS):
        return True
    if isinstance(exc, requests.exceptions.HTTPError):
        response = exc.response
        return getattr(response, "status_code", None) in QDRANT_RETRYABLE_STATUS_CODES
    return False


def normalize_retry_attempt_limit(value: int | str, *, minimum: int = 1) -> int:
    """Keep a configured retry-attempt budget above its minimum."""

    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = minimum
    return parsed if parsed >= minimum else minimum


__all__ = [
    "QDRANT_RETRYABLE_EXCEPTIONS",
    "QDRANT_RETRYABLE_STATUS_CODES",
    "is_retry_safe_write",
    "is_retryable_http_exception",
    "normalize_retry_attempt_limit",
]
