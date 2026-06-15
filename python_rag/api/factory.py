"""FastAPI app factory helpers for dependency-injected testability."""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from pathlib import Path
from typing import Any, TypeVar
from fastapi import FastAPI

from application.service import RAGService
from infrastructure.vectorstore import QdrantHTTP

from .settings import AppSettings, load_app_settings
from .http.dependencies import get_provider_or_400
from .logging_config import configure_app_logging
from .runtime import log_gpu_status
from .http.routers import (
    build_config_router,
    build_graph_router,
    build_health_router,
    build_ingest_router,
    build_query_router,
    build_temporal_router,
)
from .http.errors import install_exception_handlers
from .http.middleware import install_request_context_middleware
from .startup_checks import (
    check_neo4j,
    check_provider_availability,
    check_qdrant,
    run_startup_checks,
)
from common.reliability import STARTUP_CHECK_RETRY_EVENT, log_redacted_value

TQdrant = TypeVar("TQdrant")


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


def _check_qdrant(timeout_seconds: float) -> None:
    check_qdrant(timeout_seconds)


def _check_neo4j() -> None:
    check_neo4j()


def _check_provider_availability(service: Any, settings: AppSettings, timeout_seconds: float) -> None:
    check_provider_availability(service, settings, timeout_seconds)


def _run_startup_checks(
    settings: AppSettings,
    *,
    service: Any,
    logger: logging.Logger,
) -> None:
    run_startup_checks(
        settings,
        service=service,
        logger=logger,
        check_qdrant_fn=_check_qdrant,
        check_neo4j_fn=_check_neo4j,
        check_provider_fn=lambda svc, cfg, timeout: _check_provider_availability(svc, cfg, timeout),
        retry_fn=_retry_with_backoff,
    )


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

    install_exception_handlers(app, logger)
    install_request_context_middleware(app, logger)

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
    app.include_router(build_temporal_router(logger=logger))
    return app
