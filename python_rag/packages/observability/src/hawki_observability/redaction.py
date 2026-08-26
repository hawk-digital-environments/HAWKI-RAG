"""Secret redaction and bounded request previews for logging boundaries."""

from __future__ import annotations

import logging
import re
from collections.abc import Mapping

_HEADER_ALLOWLIST = {
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
_REQUEST_ID_HEADERS = ("x-request-id", "x-correlation-id", "request-id")
DEFAULT_REQUEST_BODY_SNIPPET_BYTES = 2048
_LOGGER = logging.getLogger(__name__)


def sanitize_for_log(message: object, *, max_length: int = 2048) -> str:
    """Return a secret-safe, length-bounded string for logs."""

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


def pick_request_id(headers: Mapping[str, str], fallback: str | None = None) -> str:
    """Return the first supported correlation header or the fallback."""

    for key in _REQUEST_ID_HEADERS:
        value = headers.get(key)
        if value and str(value).strip():
            return str(value).strip()
    return fallback or ""


def preview_request_headers(headers: Mapping[str, str]) -> dict[str, str]:
    """Return an allowlisted, redacted subset of request headers."""

    preview: dict[str, str] = {}
    for key, value in headers.items():
        normalized = key.lower()
        if normalized not in _HEADER_ALLOWLIST:
            continue
        if normalized in _HEADER_REDACTIONS:
            preview[key] = "<redacted>"
        else:
            preview[key] = sanitize_for_log(value, max_length=128)
    return preview


def preview_request_body(
    body: bytes | bytearray | str | None,
    *,
    content_type: str | None,
    max_length: int = DEFAULT_REQUEST_BODY_SNIPPET_BYTES,
) -> str | None:
    """Return a bounded, redacted JSON-like request-body preview."""

    if body is None or "json" not in (content_type or "").lower():
        return None
    try:
        decoded = (
            bytes(body).decode("utf-8", errors="replace")
            if isinstance(body, (bytes, bytearray))
            else str(body)
        )
    except (TypeError, ValueError, UnicodeError):
        _LOGGER.debug("failed to decode request body for logging", exc_info=True)
        return None
    if not decoded.strip():
        return None
    return sanitize_for_log(decoded, max_length=max_length)


def log_redacted_value(message: object, *, max_length: int = 2048) -> str:
    """Alias for log boundaries that emphasizes redaction intent."""

    return sanitize_for_log(message, max_length=max_length)


__all__ = [
    "DEFAULT_REQUEST_BODY_SNIPPET_BYTES",
    "log_redacted_value",
    "pick_request_id",
    "preview_request_body",
    "preview_request_headers",
    "sanitize_for_log",
]
