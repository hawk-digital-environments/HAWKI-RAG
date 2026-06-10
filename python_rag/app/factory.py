"""FastAPI app factory helpers for dependency-injected testability."""

from __future__ import annotations

from pathlib import Path
from typing import Any, Callable, TypeVar

from fastapi import FastAPI

from core.rag_service import RAGService
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

TQdrant = TypeVar("TQdrant")

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
