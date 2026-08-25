"""Cross-cutting reliability and logging contracts for Python services.

This module keeps retry policy, idempotency classification, and request/secret
redaction behavior centralized so adapters and API boundaries share consistent
behavior.
"""

from __future__ import annotations

import logging
import re
from collections.abc import Mapping

from hawki_rag_resilience.optional_imports import import_optional_module


def _requests_exceptions_module() -> object | None:
    requests_module = import_optional_module("requests")
    if requests_module is None:
        return None
    return requests_module.exceptions


def _qdrant_retryable_exception_types() -> tuple[type[BaseException], ...]:
    request_exceptions = _requests_exceptions_module()
    if request_exceptions is None:
        return ()
    return (
        request_exceptions.ConnectTimeout,
        request_exceptions.ConnectionError,
        request_exceptions.ReadTimeout,
        request_exceptions.Timeout,
    )


RETRY_SAFE_WRITE_OPERATIONS: frozenset[str] = frozenset(
    {
        "qdrant.upsert_points",
        "qdrant.delete_by_filter",
    }
)

QDRANT_RETRYABLE_STATUS_CODES: frozenset[int] = frozenset({429, 500, 502, 503, 504})
QDRANT_RETRYABLE_EXCEPTIONS: tuple[type[BaseException], ...] = (
    _qdrant_retryable_exception_types()
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
    re.compile(r'(?i)"(authorization|proxy-authorization)"\s*:\s*"[^"]*"'),
    re.compile(r"(?i)\b(authorization|proxy-authorization)\b\s*[:=]\s*[^\r\n}]+"),
    re.compile(r'(?i)"?(api[_-]?key)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r'(?i)"?(access[_-]?token)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r'(?i)"?(x[_-]?auth[_-]?token)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r'(?i)"?([a-z0-9_-]*token)"?\s*[:=]\s*"?[^"\',}\s&]+"?'),
    re.compile(r'(?i)"?(secret|password)"?\s*[:=]\s*"?[^",}\s]+'),
    re.compile(r"(?i)bearer\s+[A-Za-z0-9._~+/=-]{8,}"),
)

_URL_USERINFO_PATTERN = re.compile(r"(?i)(https?://)[^/\s:@]+:[^@/\s]+@")

REQUEST_ID_HEADERS = ("x-request-id", "x-correlation-id", "request-id")

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
    safe = _URL_USERINFO_PATTERN.sub(r"\1<redacted>@", safe)
    if len(safe) <= max_length:
        return safe
    if max_length <= 3:
        return safe[: max(0, max_length)]
    return f"{safe[: max_length - 3]}..."


def _secret_replacement(match: re.Match[str]) -> str:
    if match.groups():
        return f"{match.group(1)}=<redacted>"
    return "<redacted>"


def is_retryable_http_exception(exc: Exception) -> bool:
    """Shared transport retryability test for HTTP-like dependency errors."""
    request_exceptions = _requests_exceptions_module()
    if request_exceptions is None:
        return False
    if isinstance(exc, _qdrant_retryable_exception_types()):
        return True
    if isinstance(exc, request_exceptions.HTTPError):
        response = getattr(exc, "response", None)
        status = getattr(response, "status_code", None)
        return status in QDRANT_RETRYABLE_STATUS_CODES
    return False


def normalize_retry_attempt_limit(value: int | str, *, minimum: int = 1) -> int:
    """Keep retry attempts in a safe positive range."""
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = int(minimum)
    return parsed if parsed >= minimum else minimum


def pick_request_id(headers: Mapping[str, str], fallback: str | None = None) -> str:
    for key in REQUEST_ID_HEADERS:
        value = headers.get(key)
        if value:
            value = str(value).strip()
            if value:
                return value
    return fallback or ""


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
        _LOGGER.debug(
            "failed to decode request body for request logging", exc_info=True
        )
        return None
    if not decoded.strip():
        return None
    return sanitize_for_log(decoded, max_length=max_length)


def log_redacted_value(message: object, *, max_length: int = 2048) -> str:
    """Return a log-safe string with secrets masked and a stable length cap."""
    return sanitize_for_log(message, max_length=max_length)
