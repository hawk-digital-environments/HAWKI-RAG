"""Optional read-only dependency checks for bridge startup."""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from typing import Any

from hawki_rag_resilience.redaction import sanitize_for_log
from hawki_rag_stores.neo4j.settings import load_neo4j_settings
from hawki_rag_stores.qdrant.client import QdrantHTTP
from hawki_rag_resilience.optional_imports import import_required_module


def run_startup_checks(
    settings: Any,
    *,
    logger: logging.Logger,
    check_qdrant_fn: Callable[[], None] | None = None,
    check_neo4j_fn: Callable[[], None] | None = None,
) -> None:
    operations: dict[str, Callable[[], None]] = {
        "qdrant": check_qdrant_fn or _ping_qdrant,
        "neo4j": check_neo4j_fn or _ping_neo4j,
    }
    for operation, callback in operations.items():
        delay = settings.startup_check_backoff_seconds
        for attempt in range(1, settings.startup_check_attempts + 1):
            try:
                callback()
                break
            except Exception as exc:
                if attempt >= settings.startup_check_attempts:
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
    neo4j = import_required_module(
        "neo4j",
        install_hint="Install hawki-rag-stores to check Neo4j.",
    )
    driver = neo4j.GraphDatabase.driver(
        settings.uri,
        auth=(settings.user, settings.password),
    )
    try:
        with driver.session(database=settings.database) as session:
            session.run("RETURN 1").consume()
    finally:
        driver.close()


__all__ = ["run_startup_checks"]
