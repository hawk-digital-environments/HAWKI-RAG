"""Structured, secret-safe event logging for RAWKI boundaries."""

from __future__ import annotations

import logging
from collections.abc import Mapping, Sequence

from hawki_observability.redaction import sanitize_for_log

_MAX_COLLECTION_ITEMS = 50
_MAX_NESTING_DEPTH = 4


def log_event(logger: logging.Logger, event: str, **fields: object) -> None:
    """Log one stable event with bounded, recursively sanitized fields."""

    safe_event = sanitize_for_log(event, max_length=160)
    safe_fields = {
        sanitize_for_log(key, max_length=120): _sanitize_value(value)
        for key, value in fields.items()
        if value is not None
    }
    logger.info("%s %s", safe_event, safe_fields)


def _sanitize_value(value: object, *, depth: int = 0) -> object:
    if isinstance(value, str):
        return sanitize_for_log(value)
    if value is None or isinstance(value, (bool, int, float)):
        return value
    if depth >= _MAX_NESTING_DEPTH:
        return sanitize_for_log(value)
    if isinstance(value, Mapping):
        items = list(value.items())[:_MAX_COLLECTION_ITEMS]
        return {
            sanitize_for_log(key, max_length=120): _sanitize_value(
                item,
                depth=depth + 1,
            )
            for key, item in items
        }
    if isinstance(value, Sequence) and not isinstance(value, (bytes, bytearray)):
        return [
            _sanitize_value(item, depth=depth + 1)
            for item in value[:_MAX_COLLECTION_ITEMS]
        ]
    return sanitize_for_log(value)


__all__ = ["log_event"]
