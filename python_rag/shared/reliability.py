"""Cross-cutting reliability and logging contracts for Python services.

This module keeps retry policy, idempotency classification, and request/secret
redaction behavior centralized so adapters and API boundaries share consistent
behavior.
"""

from __future__ import annotations

import logging
import re
from collections.abc import Mapping

from requests import exceptions as request_exceptions

try:
    from neo4j import exceptions as neo4j_exceptions
except Exception:  # pragma: no cover - optional dependency
    neo4j_exceptions = None


RETRY_SAFE_WRITE_OPERATIONS: frozenset[str] = frozenset(
    {
        "qdrant.upsert_points",
        "qdrant.delete_by_filter",
        "neo4j.upsert_triplets",
        "neo4j.delete_by_doc_id",
    }
)

QDRANT_RETRYABLE_STATUS_CODES: frozenset[int] = frozenset({429, 500, 502, 503, 504})
QDRANT_RETRYABLE_EXCEPTIONS: tuple[type[BaseException], ...] = (
    request_exceptions.ConnectTimeout,
    request_exceptions.ConnectionError,
    request_exceptions.ReadTimeout,
    request_exceptions.Timeout,
)
NEO4J_RETRYABLE_ERROR_TOKENS: tuple[str, ...] = (
    "transient",
    "retry",
    "timeout",
    "timed out",
    "connection",
    "temporarily",
    "service unavailable",
    "database unavailable",
    "connection refused",
    "temporary",
)

API_REQUEST_START_EVENT = "api.request_start"
API_REQUEST_END_EVENT = "api.request_end"
API_REQUEST_ERROR_EVENT = "api.request_error"
STARTUP_CHECK_EVENT = "startup.check"
STARTUP_CHECK_RETRY_EVENT = "startup.check_retry"
QDRANT_ADAPTER_EVENT = "adapter.qdrant.request"
NEO4J_ADAPTER_EVENT = "adapter.neo4j.query"

_DEFAULT_HEADER_ALLOWLIST = {
    "content-type",
    "accept",
    "host",
    "x-request-id",
    "x-correlation-id",
    "request-id",
    "idempotency-key",
    "user-agent",
    "authorization",
    "proxy-authorization",
    "x-api-key",
    "api-key",
    "cookie",
    "set-cookie",
}

_HEADER_REDACTIONS = {
    "authorization",
    "proxy-authorization",
    "x-api-key",
    "api-key",
    "x-auth-token",
    "cookie",
    "set-cookie",
}

_SECRET_PATTERNS: tuple[re.Pattern[str], ...] = (
    re.compile(r'(?i)"?(authorization)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r'(?i)"?(api[_-]?key)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r'(?i)"?(access[_-]?token)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r'(?i)"?(x[_-]?auth[_-]?token)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r'(?i)"?(secret|password)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r"(?i)bearer\s+[A-Za-z0-9._~+/=-]{8,}"),
)

REQUEST_ID_HEADERS = ("x-request-id", "x-correlation-id", "request-id")

DEFAULT_RETRY_CAP_BY_OPERATION: dict[str, int] = {
    "qdrant.upsert_points": 3,
    "qdrant.delete_by_filter": 3,
    "neo4j.upsert_triplets": 3,
    "neo4j.delete_by_doc_id": 3,
}

DEFAULT_REQUEST_BODY_SNIPPET_BYTES = 2048

_LOGGER = logging.getLogger(__name__)


def is_retry_safe_write(operation: str | None) -> bool:
    """Return whether this operation is safe to retry when an idempotency key exists."""
    if not operation:
        return False
    return operation in RETRY_SAFE_WRITE_OPERATIONS


def sanitize_for_log(message: object, *, max_length: int = 2048) -> str:
    """Return a safe string value for human-readable logs."""
    safe = str(message)
    for pattern in _SECRET_PATTERNS:
        safe = pattern.sub(_secret_replacement, safe)
    if len(safe) <= max_length:
        return safe
    return f"{safe[:max_length]}..."


def _secret_replacement(match: re.Match[str]) -> str:
    if match.groups():
        return f"{match.group(1)}=<redacted>"
    return "<redacted>"


def is_retryable_http_exception(exc: Exception) -> bool:
    """Shared transport retryability test for HTTP-like dependency errors."""
    if isinstance(exc, QDRANT_RETRYABLE_EXCEPTIONS):
        return True
    if isinstance(exc, request_exceptions.HTTPError):
        response = getattr(exc, "response", None)
        status = getattr(response, "status_code", None)
        return status in QDRANT_RETRYABLE_STATUS_CODES
    return False


def is_retryable_neo4j_exception(exc: Exception) -> bool:
    """Classify transient Neo4j exceptions as retryable."""
    if neo4j_exceptions is None:
        return False
    if not isinstance(exc, neo4j_exceptions.Neo4jError):
        return False
    lowered = str(exc).lower()
    return any(token in lowered for token in NEO4J_RETRYABLE_ERROR_TOKENS)


def is_safe_retryable_write(operation: str | None, request_id: str | None) -> bool:
    """Return true when an operation can be retried and request replay is safe."""
    if not request_id:
        return False
    return is_retry_safe_write(operation)


def normalize_retry_attempt_limit(value: int | str, *, minimum: int = 1) -> int:
    """Keep retry attempts in a safe positive range."""
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = int(minimum)
    return parsed if parsed >= minimum else minimum


def normalize_retry_cap(operation: str | None, configured: int | None, *, minimum: int = 1) -> int:
    """Normalize configured retries with explicit per-operation fallback values."""
    baseline = DEFAULT_RETRY_CAP_BY_OPERATION.get((operation or "").strip(), minimum)
    if configured is None:
        return normalize_retry_attempt_limit(baseline, minimum=minimum)
    return normalize_retry_attempt_limit(configured, minimum=minimum)


def pick_request_id(headers: Mapping[str, str], fallback: str | None = None) -> str:
    for key in REQUEST_ID_HEADERS:
        value = headers.get(key)
        if value:
            value = str(value).strip()
            if value:
                return value
    return fallback or ""


def _redact_secret_tokens(text: str) -> str:
    for pattern in _SECRET_PATTERNS:
        text = pattern.sub(_secret_replacement, text)
    text = re.sub(r"(?i)token\s*[:=]\s*[A-Za-z0-9._~+/=-]{12,}", "token=<redacted>", text)
    return text


def preview_request_headers(headers: Mapping[str, str]) -> dict[str, str]:
    """Return a safe subset of request headers for request logging."""
    preview: dict[str, str] = {}
    for key, value in headers.items():
        normalized = key.lower()
        if normalized not in _DEFAULT_HEADER_ALLOWLIST:
            continue
        if normalized in _HEADER_REDACTIONS:
            preview[key] = "<redacted>"
            continue
        preview[key] = sanitize_for_log(value, max_length=128)
    return preview


def preview_request_body(
    body: bytes | bytearray | str | None,
    *,
    content_type: str | None,
    max_length: int = DEFAULT_REQUEST_BODY_SNIPPET_BYTES,
) -> str | None:
    """Return a redacted request-body snippet if this is a JSON-like request."""
    if body is None:
        return None
    if (content_type or "").lower().find("json") < 0:
        return None
    try:
        if isinstance(body, (bytes, bytearray)):
            decoded = bytes(body).decode("utf-8", errors="replace")
        else:
            decoded = str(body)
    except Exception:
        _LOGGER.debug("failed to decode request body for request logging", exc_info=True)
        return None
    if not decoded.strip():
        return None
    return sanitize_for_log(decoded, max_length=max_length)


def log_redacted_value(message: object, *, max_length: int = 2048) -> str:
    """Return a log-safe string with secrets masked and a stable length cap."""
    return _redact_secret_tokens(sanitize_for_log(message, max_length=max_length))
