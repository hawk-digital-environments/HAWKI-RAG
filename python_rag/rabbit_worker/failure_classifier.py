from __future__ import annotations

import asyncio
import json
from typing import Any

from pydantic import ValidationError

FAILURE_TRANSIENT = "transient"
FAILURE_PERMANENT = "permanent"


class TransientJobError(Exception):
    """Known retryable processing error."""


class PermanentJobError(Exception):
    """Known non-retryable processing error."""


def classify_failure(exc: Exception) -> str:
    """
    Keep retry classification isolated for easy extension.
    Default behavior is transient to preserve at-least-once processing.
    """
    if isinstance(exc, (PermanentJobError, ValidationError, json.JSONDecodeError, UnicodeDecodeError)):
        return FAILURE_PERMANENT
    if isinstance(exc, (TransientJobError, TimeoutError, asyncio.TimeoutError)):
        return FAILURE_TRANSIENT

    # Optional imports to avoid hard deps in lightweight test environments.
    try:  # pragma: no cover - dependency may not always be installed in tests
        import requests

        if isinstance(exc, requests.exceptions.Timeout):
            return FAILURE_TRANSIENT
        if isinstance(exc, requests.exceptions.ConnectionError):
            return FAILURE_TRANSIENT
        if isinstance(exc, requests.exceptions.HTTPError):
            status = getattr(getattr(exc, "response", None), "status_code", None)
            if status in (400, 401, 403, 404, 405, 409, 410, 415, 422):
                return FAILURE_PERMANENT
            if status in (408, 425, 429, 500, 502, 503, 504):
                return FAILURE_TRANSIENT
    except Exception:
        pass

    # FastAPI style validation/HTTP errors from ingest pipeline.
    status_code = getattr(exc, "status_code", None)
    if isinstance(status_code, int):
        if status_code in (400, 401, 403, 404, 405, 409, 410, 415, 422):
            return FAILURE_PERMANENT
        if status_code in (408, 425, 429, 500, 502, 503, 504):
            return FAILURE_TRANSIENT

    lowered = f"{type(exc).__name__}: {exc}".lower()
    permanent_markers = (
        "invalid schema",
        "validation error",
        "unsupported input",
        "unsupported provider",
        "deterministic parse failure",
        "missing required",
        "malformed json",
    )
    if any(marker in lowered for marker in permanent_markers):
        return FAILURE_PERMANENT

    transient_markers = (
        "timeout",
        "temporarily unavailable",
        "temporary downstream outage",
        "service unavailable",
        "rate limit",
        "too many requests",
        "connection reset",
        "connection refused",
        "network",
    )
    if any(marker in lowered for marker in transient_markers):
        return FAILURE_TRANSIENT

    return FAILURE_TRANSIENT


def error_name(exc: Exception) -> str:
    return type(exc).__name__

