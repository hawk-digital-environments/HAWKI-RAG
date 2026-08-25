"""Optional read-only dependency checks for bridge startup."""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from typing import Any

from neo4j import GraphDatabase
from requests import RequestException

from hawki_rag_resilience.redaction import sanitize_for_log
from hawki_rag_stores.neo4j.errors import (
    NEO4J_OPERATION_ERRORS,
    is_retryable_neo4j_error,
)
from hawki_rag_stores.neo4j.settings import load_neo4j_settings
from hawki_rag_stores.qdrant.client import QdrantHTTP


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
        "qdrant": (check_qdrant_fn or _ping_qdrant, (RequestException,)),
        "neo4j": (check_neo4j_fn or _ping_neo4j, NEO4J_OPERATION_ERRORS),
    }
    for operation, (callback, handled_errors) in operations.items():
        delay = settings.startup_check_backoff_seconds
        for attempt in range(1, settings.startup_check_attempts + 1):
            try:
                callback()
                break
            except handled_errors as exc:
                is_retryable = operation != "neo4j" or is_retryable_neo4j_error(exc)
                if attempt >= settings.startup_check_attempts or not is_retryable:
                    raise
                logger.warning(
                    "startup.check_retry operation=%s attempt=%s error=%s",
                    operation,
                    attempt,
                    sanitize_for_log(exc),
                )
                time.sleep(delay)
                delay = min(delay * 2, 30.0)


def _ping_qdrant() -> None:
    QdrantHTTP().list_collections()


def _ping_neo4j() -> None:
    settings = load_neo4j_settings()
    driver = GraphDatabase.driver(
        settings.uri,
        auth=(settings.user, settings.password),
        max_transaction_retry_time=settings.max_transaction_retry_time,
    )
    try:
        with driver.session(database=settings.database) as session:
            session.run("RETURN 1").consume()
    finally:
        driver.close()


__all__ = ["run_startup_checks"]
