"""FastAPI app factory helpers for dependency-injected testability."""

from __future__ import annotations

import logging
import os
import re
import time
from collections.abc import Callable
from pathlib import Path
from typing import Any, TypeVar

import requests
from fastapi import FastAPI, HTTPException, Request  # type: ignore[reportMissingImports]
from fastapi.responses import JSONResponse  # type: ignore[reportMissingImports]
from requests import RequestException

from core.rag_service import RAGService
from graph.neo4j_settings import load_neo4j_settings
from vectorstore.settings import qdrant_settings_from_env
from vectorstore.qdrant_http import QdrantHTTP

from .settings import AppSettings, load_app_settings
from .dependencies import get_provider_or_400
from .logging_config import configure_app_logging
from .runtime import log_gpu_status
from .routers import (
    build_config_router,
    build_graph_router,
    build_health_router,
    build_ingest_router,
    build_query_router,
)


_SENSITIVE_PATTERNS = (
    re.compile(r"(?i)(api[_-]?key|access[_-]?token|authorization|secret|password)=[^\s&]+"),
    re.compile(r"(?i)Authorization:\s*[^\r\n]+"),
    re.compile(r"(?i)https?://[^/@:]+:[^/@]+@"),
)

TQdrant = TypeVar("TQdrant")


def _sanitize_error(message: object) -> str:
    safe = str(message)
    for pattern in _SENSITIVE_PATTERNS:
        safe = pattern.sub("<redacted>", safe)
    return safe


def _build_error_payload(request: Request, *, status: int, error_type: str, message: str | None) -> dict[str, Any]:
    return {
        "error": {
            "type": error_type,
            "status": status,
            "message": _sanitize_error(message or ""),
            "path": str(getattr(request.url, "path", "")),
        }
    }


def _add_exception_handlers(app: FastAPI, logger: logging.Logger) -> None:
    def handle_http_error(request: Request, exc: HTTPException) -> JSONResponse:
        logger.warning(
            "api:error type=http_exception path=%s status=%s detail=%s",
            getattr(request.url, "path", ""),
            exc.status_code,
            _sanitize_error(exc.detail),
        )
        return JSONResponse(
            status_code=exc.status_code,
            content=_build_error_payload(
                request,
                status=exc.status_code,
                error_type=exc.__class__.__name__,
                message=str(exc.detail),
            ),
        )

    def handle_value_error(request: Request, exc: ValueError) -> JSONResponse:
        status_code = 400
        logger.warning(
            "api:error type=value_error path=%s status=%s detail=%s",
            getattr(request.url, "path", ""),
            status_code,
            _sanitize_error(exc),
        )
        return JSONResponse(
            status_code=status_code,
            content=_build_error_payload(
                request,
                status=status_code,
                error_type=exc.__class__.__name__,
                message=str(exc),
            ),
        )

    def handle_request_error(request: Request, exc: RequestException) -> JSONResponse:
        status_code = 503
        logger.error(
            "api:error type=request_error path=%s status=%s detail=%s",
            getattr(request.url, "path", ""),
            status_code,
            _sanitize_error(exc),
        )
        return JSONResponse(
            status_code=status_code,
            content=_build_error_payload(
                request,
                status=status_code,
                error_type="ServiceUnavailable",
                message="Upstream request failed or timed out.",
            ),
        )

    def handle_runtime_error(request: Request, exc: RuntimeError) -> JSONResponse:
        status_code = 502
        logger.error(
            "api:error type=runtime_error path=%s status=%s detail=%s",
            getattr(request.url, "path", ""),
            status_code,
            _sanitize_error(exc),
        )
        return JSONResponse(
            status_code=status_code,
            content=_build_error_payload(
                request,
                status=status_code,
                error_type=exc.__class__.__name__,
                message=str(exc),
            ),
        )

    def handle_unexpected(request: Request, exc: Exception) -> JSONResponse:
        status_code = 500
        logger.exception(
            "api:error type=unhandled_exception path=%s status=%s",
            getattr(request.url, "path", ""),
            status_code,
        )
        return JSONResponse(
            status_code=status_code,
            content=_build_error_payload(
                request,
                status=status_code,
                error_type="InternalServerError",
                message="Unhandled server error.",
            ),
        )

    app.add_exception_handler(HTTPException, handle_http_error)
    app.add_exception_handler(ValueError, handle_value_error)
    app.add_exception_handler(RequestException, handle_request_error)
    app.add_exception_handler(RuntimeError, handle_runtime_error)
    app.add_exception_handler(Exception, handle_unexpected)


def _retry_with_backoff(
    *,
    operation: str,
    attempt_fn: Callable[[], None],
    attempts: int,
    logger: logging.Logger,
    timeout_seconds: float,
    backoff_seconds: float,
) -> None:
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
                "startup:check_retry operation=%s attempt=%s/%s timeout=%.2fs error=%s",
                operation,
                attempt,
                max_attempts,
                timeout_seconds,
                _sanitize_error(exc),
            )
            if backoff > 0:
                time.sleep(backoff)
            backoff = min(backoff * 2, 30.0)


def _check_qdrant(timeout_seconds: float) -> None:
    settings = qdrant_settings_from_env()
    headers: dict[str, str] = {"Content-Type": "application/json"}
    if settings.api_key:
        headers["api-key"] = settings.api_key

    response = requests.get(
        f"{settings.base_url}/collections",
        headers=headers,
        timeout=timeout_seconds,
    )
    if response.status_code == 401:
        raise RuntimeError("Qdrant auth failed (401 Unauthorized).")
    if response.status_code in (403, 404):
        raise RuntimeError(f"Qdrant returned status {response.status_code} for /collections endpoint.")
    response.raise_for_status()


def _check_neo4j() -> None:
    settings = load_neo4j_settings()
    try:
        from neo4j import GraphDatabase  # type: ignore[reportMissingImports]
        from neo4j import exceptions as neo4j_exceptions  # type: ignore[reportMissingImports]
    except Exception as exc:
        raise RuntimeError("Neo4j driver package is missing.") from exc

    database = settings.database
    driver = GraphDatabase.driver(settings.uri, auth=(settings.user, settings.password))
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


def _check_provider_availability(service: Any, settings: AppSettings, timeout_seconds: float) -> None:
    provider_name = (settings.rag_default_provider or "").strip().lower()
    provider = service.get_provider(provider_name)
    if provider_name != "ollama":
        return

    base = str(getattr(provider, "base", os.environ.get("OLLAMA_API_URL", "http://ollama:11434/api"))).rstrip("/")
    last_error: str | None = None
    for path in ("/api/tags", "/api/version"):
        try:
            response = requests.get(
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


def _run_startup_checks(
    settings: AppSettings,
    *,
    service: Any,
    logger: logging.Logger,
) -> None:
    attempts = max(1, int(settings.startup_check_attempts))
    timeout_seconds = max(0.5, float(settings.startup_check_timeout_seconds))
    backoff_seconds = max(0.0, float(settings.startup_check_backoff_seconds))

    logger.info(
        "startup:checks_start enabled=%s attempts=%s timeout=%s backoff=%s",
        settings.startup_checks_enabled,
        attempts,
        timeout_seconds,
        backoff_seconds,
    )

    _retry_with_backoff(
        operation="qdrant",
        attempt_fn=lambda: _check_qdrant(timeout_seconds),
        attempts=attempts,
        logger=logger,
        timeout_seconds=timeout_seconds,
        backoff_seconds=backoff_seconds,
    )

    _retry_with_backoff(
        operation="neo4j",
        attempt_fn=_check_neo4j,
        attempts=attempts,
        logger=logger,
        timeout_seconds=timeout_seconds,
        backoff_seconds=backoff_seconds,
    )

    _retry_with_backoff(
        operation=f"provider:{(settings.rag_default_provider or '').strip().lower()}",
        attempt_fn=lambda: _check_provider_availability(
            service=service,
            settings=settings,
            timeout_seconds=timeout_seconds,
        ),
        attempts=attempts,
        logger=logger,
        timeout_seconds=timeout_seconds,
        backoff_seconds=backoff_seconds,
    )

    logger.info("startup:checks_passed")


def build_app(
    *,
    rag_service: Any | None = None,
    public_dir: Path | None = None,
    qdrant_factory: Callable[[], TQdrant] = QdrantHTTP,
    logger_name: str = "python_rag.app",
    app_settings: AppSettings | None = None,
) -> FastAPI:
    """Create and wire the RAG FastAPI application."""
    settings = app_settings or load_app_settings()
    logger, graph_debug, graph_debug_log = configure_app_logging(
        settings,
        logger_name=logger_name,
    )
    public_dir = public_dir or settings.public_dir
    service = rag_service or RAGService()

    def get_provider(name: str) -> Any:
        return get_provider_or_400(service, name)

    def get_runtime_summary() -> dict[str, object]:
        return service.graph_runtime_summary()

    def log_graph_status(context: str) -> None:
        log_gpu_status(
            logger,
            context,
            cuda_visible_devices=settings.cuda_visible_devices,
            nvidia_visible_devices=settings.nvidia_visible_devices,
        )

    app = FastAPI(title="LightRAG Service", version="0.2.0")
    app.state.graph_debug = graph_debug
    app.state.graph_debug_log = graph_debug_log
    app.state.startup_checks_enabled = settings.startup_checks_enabled

    _add_exception_handlers(app, logger)

    if settings.startup_checks_enabled:

        @app.on_event("startup")
        def _startup_checks() -> None:
            _run_startup_checks(
                settings,
                service=service,
                logger=logger,
            )

    app.include_router(
        build_health_router(
            logger=logger,
            runtime_summary=get_runtime_summary,
        )
    )
    app.include_router(
        build_config_router(
            logger=logger,
            get_provider=get_provider,
            qdrant_factory=qdrant_factory,
            app_settings=settings,
        )
    )
    app.include_router(
        build_ingest_router(
            logger=logger,
            rag_service=service,
            public_dir=public_dir,
            log_graph_status=log_graph_status,
            app_settings=settings,
        )
    )
    app.include_router(
        build_query_router(
            logger=logger,
            rag_service=service,
            app_settings=settings,
        )
    )
    app.include_router(
        build_graph_router(
            logger=logger,
            rag_service=service,
            log_graph_status=log_graph_status,
        )
    )
    return app
