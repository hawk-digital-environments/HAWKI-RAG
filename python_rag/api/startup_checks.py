"""Startup dependency checks for the FastAPI app."""

from __future__ import annotations

import logging
import os
import time
from collections.abc import Callable
from typing import Any

from api.settings import AppSettings
from infrastructure.graph.neo4j_settings import load_neo4j_settings
from infrastructure.vectorstore.settings import qdrant_settings_from_env
from common.optional_imports import import_required_module
from common.reliability import (
    STARTUP_CHECK_EVENT,
    STARTUP_CHECK_RETRY_EVENT,
    log_redacted_value,
)


def _requests_module() -> Any:
    return import_required_module(
        "requests",
        install_hint="Install python_rag/requirements.txt to run startup dependency checks.",
    )


def retry_with_backoff(
    *,
    operation: str,
    attempt_fn: Callable[[], None],
    attempts: int,
    logger: logging.Logger,
    timeout_seconds: float,
    backoff_seconds: float,
) -> None:
    """Run a startup check with bounded exponential backoff."""

    max_attempts = max(1, int(attempts))
    backoff = max(0.0, float(backoff_seconds))
    for attempt in range(1, max_attempts + 1):
        try:
            attempt_fn()
            return
        except Exception as exc:
            if attempt >= max_attempts:
                raise
            logger.warning(
                "event=%s operation=%s attempt=%s/%s timeout=%.2fs error=%s",
                STARTUP_CHECK_RETRY_EVENT,
                operation,
                attempt,
                max_attempts,
                timeout_seconds,
                log_redacted_value(exc),
            )
            if backoff > 0:
                time.sleep(backoff)
            backoff = min(backoff * 2, 30.0)


def check_qdrant(timeout_seconds: float) -> None:
    """Verify Qdrant collections endpoint availability."""

    settings = qdrant_settings_from_env()
    headers: dict[str, str] = {"Content-Type": "application/json"}
    if settings.api_key:
        headers["api-key"] = settings.api_key

    response = _requests_module().get(
        f"{settings.base_url}/collections",
        headers=headers,
        timeout=timeout_seconds,
    )
    if response.status_code == 401:
        raise RuntimeError("Qdrant auth failed (401 Unauthorized).")
    if response.status_code in (403, 404):
        raise RuntimeError(f"Qdrant returned status {response.status_code} for /collections endpoint.")
    response.raise_for_status()


def check_neo4j() -> None:
    """Verify Neo4j can open a session and run a trivial query."""

    settings = load_neo4j_settings()
    neo4j_module = import_required_module(
        "neo4j",
        install_hint="Install python_rag/requirements.txt to run startup dependency checks.",
    )
    graph_database = neo4j_module.GraphDatabase
    neo4j_exceptions = neo4j_module.exceptions

    database = settings.database
    driver = graph_database.driver(settings.uri, auth=(settings.user, settings.password))
    try:
        with driver.session(database=database) as session:
            session.run("RETURN 1").consume()
    except neo4j_exceptions.Neo4jError as exc:
        raise RuntimeError(f"Neo4j startup ping failed: {exc}") from exc
    finally:
        try:
            driver.close()
        except Exception:
            pass


def check_provider_availability(service: Any, settings: AppSettings, timeout_seconds: float) -> None:
    """Verify the configured default provider is reachable when supported."""

    provider_name = (settings.rag_default_provider or "").strip().lower()
    if provider_name != "ollama":
        return

    provider = service.get_provider(provider_name)
    base = str(getattr(provider, "base", os.environ.get("OLLAMA_API_URL", "http://ollama:11434/api"))).rstrip("/")
    last_error: str | None = None
    for path in ("/api/tags", "/api/version"):
        try:
            response = _requests_module().get(
                f"{base}{path}",
                headers={"Accept": "application/json"},
                timeout=timeout_seconds,
            )
            if response.status_code == 200:
                return
            response.raise_for_status()
        except Exception as exc:  # noqa: BLE001
            last_error = str(exc)

    raise RuntimeError(f"Ollama provider check failed: {last_error}")


def run_startup_checks(
    settings: AppSettings,
    *,
    service: Any,
    logger: logging.Logger,
    check_qdrant_fn: Callable[[float], None] = check_qdrant,
    check_neo4j_fn: Callable[[], None] = check_neo4j,
    check_provider_fn: Callable[[Any, AppSettings, float], None] = check_provider_availability,
    retry_fn: Callable[..., None] = retry_with_backoff,
) -> None:
    """Run all configured dependency checks."""

    attempts = max(1, int(settings.startup_check_attempts))
    timeout_seconds = max(0.5, float(settings.startup_check_timeout_seconds))
    backoff_seconds = max(0.0, float(settings.startup_check_backoff_seconds))

    logger.info(
        "event=%s enabled=%s attempts=%s timeout=%s backoff=%s",
        STARTUP_CHECK_EVENT,
        settings.startup_checks_enabled,
        attempts,
        timeout_seconds,
        backoff_seconds,
    )

    retry_fn(
        operation="qdrant",
        attempt_fn=lambda: check_qdrant_fn(timeout_seconds),
        attempts=attempts,
        logger=logger,
        timeout_seconds=timeout_seconds,
        backoff_seconds=backoff_seconds,
    )

    retry_fn(
        operation="neo4j",
        attempt_fn=check_neo4j_fn,
        attempts=attempts,
        logger=logger,
        timeout_seconds=timeout_seconds,
        backoff_seconds=backoff_seconds,
    )

    retry_fn(
        operation=f"provider:{(settings.rag_default_provider or '').strip().lower()}",
        attempt_fn=lambda: check_provider_fn(
            service,
            settings,
            timeout_seconds,
        ),
        attempts=attempts,
        logger=logger,
        timeout_seconds=timeout_seconds,
        backoff_seconds=backoff_seconds,
    )

    logger.info(
        "event=%s qdrant_ok=true neo4j_ok=true provider_check_done=true",
        STARTUP_CHECK_EVENT,
    )


__all__ = [
    "check_neo4j",
    "check_provider_availability",
    "check_qdrant",
    "retry_with_backoff",
    "run_startup_checks",
]
