"""Optional read-only dependency checks for bridge startup."""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from typing import Any

from hawki_observability.redaction import sanitize_for_log
from hawki_bridge.adapters.neo4j_reader import (
    NEO4J_ERRORS,
    ping_neo4j,
)
from hawki_bridge.adapters.qdrant_reader import (
    QDRANT_OPERATION_ERRORS,
    ping_qdrant,
)


def run_startup_checks(
    settings: Any,
    *,
    logger: logging.Logger,
    check_qdrant_fn: Callable[[], None] | None = None,
    check_neo4j_fn: Callable[[], None] | None = None,
) -> None:
    operations: dict[
        str, tuple[Callable[[], None], tuple[type[BaseException], ...]]
    ] = {
        "qdrant": (check_qdrant_fn or ping_qdrant, QDRANT_OPERATION_ERRORS),
        "neo4j": (check_neo4j_fn or ping_neo4j, NEO4J_ERRORS),
    }
    for operation, (callback, handled_errors) in operations.items():
        delay = settings.startup_check_backoff_seconds
        for attempt in range(1, settings.startup_check_attempts + 1):
            try:
                callback()
                break
            except handled_errors as exc:
                is_retryable = operation != "neo4j" or exc.is_retryable()
                if attempt >= settings.startup_check_attempts or not is_retryable:
                    raise
                logger.warning(
                    "startup.check_retry operation=%s attempt=%s error=%s",
                    operation,
                    attempt,
                    type(exc).__name__
                    if operation == "neo4j"
                    else sanitize_for_log(exc),
                )
                time.sleep(delay)
                delay = min(delay * 2, 30.0)


__all__ = ["run_startup_checks"]
