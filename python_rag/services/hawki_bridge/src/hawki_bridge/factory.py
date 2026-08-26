"""Composition root for the read-only bridge API."""

from __future__ import annotations

from collections.abc import Callable
from contextlib import asynccontextmanager

from fastapi import FastAPI

from hawki_bridge.adapters.temporal_client import TemporalBridgeClient
from hawki_bridge.application.graph import GraphReadService
from hawki_bridge.application.dependencies import QueryDependencies
from hawki_bridge.composition import build_query_dependencies
from hawki_bridge.domain.ports import GraphReader
from hawki_bridge.http.errors import install_exception_handlers
from hawki_bridge.http.middleware import install_request_context_middleware
from hawki_bridge.http.routers import (
    build_graph_router,
    build_health_router,
    build_query_router,
    build_temporal_router,
)
from hawki_bridge.logging_config import configure_logging
from hawki_bridge.settings import BridgeSettings, load_settings
from hawki_bridge.startup_checks import run_startup_checks


def build_app(
    *,
    settings: BridgeSettings | None = None,
    graph_reader: GraphReader | None = None,
    query_dependencies: QueryDependencies | None = None,
    temporal_client_factory: Callable = TemporalBridgeClient,
    runtime_summary: Callable[[], dict[str, object]] | None = None,
    logger_name: str = "hawki_bridge",
) -> FastAPI:
    active_settings = settings or load_settings()
    active_dependencies = query_dependencies or build_query_dependencies()
    active_graph_service = GraphReadService(
        graph_reader or active_dependencies.graph_search
    )
    active_runtime_summary = runtime_summary or bridge_runtime_summary
    logger = configure_logging(active_settings, logger_name=logger_name)

    @asynccontextmanager
    async def lifespan(_application: FastAPI):
        if active_settings.startup_checks_enabled:
            run_startup_checks(
                active_settings,
                logger=logger,
            )
        yield

    application = FastAPI(
        title="HAWKI RAG Bridge",
        version="0.3.0",
        lifespan=lifespan,
    )
    install_exception_handlers(application, logger)
    install_request_context_middleware(application, logger)
    application.include_router(
        build_health_router(runtime_summary=active_runtime_summary)
    )
    application.include_router(
        build_query_router(
            settings=active_settings,
            dependencies=active_dependencies,
        )
    )
    application.include_router(build_graph_router(service=active_graph_service))
    application.include_router(
        build_temporal_router(
            settings=active_settings,
            logger=logger,
            client_factory=temporal_client_factory,
        )
    )

    write_routes = {
        ("POST", "/ingest"),
        ("PUT", "/documents/{doc_id}"),
        ("DELETE", "/documents/{doc_id}"),
        ("POST", "/graph/from-text"),
        ("POST", "/graph/cache/clear"),
    }
    registered = {
        (method.upper(), path)
        for path, operations in application.openapi()["paths"].items()
        for method in operations
        if method.upper() in {"GET", "POST", "PUT", "PATCH", "DELETE"}
    }
    forbidden = write_routes & registered
    if forbidden:
        raise RuntimeError(
            f"Bridge registered forbidden write routes: {sorted(forbidden)}"
        )
    return application


def bridge_runtime_summary() -> dict[str, object]:
    """Return stable process metadata for the optional health payload."""

    return {"role": "bridge", "mode": "read-only"}


__all__ = ["bridge_runtime_summary", "build_app"]
