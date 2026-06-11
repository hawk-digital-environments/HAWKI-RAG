"""Graph-specific endpoint router."""

from __future__ import annotations

import logging
from typing import Any

from fastapi import APIRouter

from api.http.schemas import GraphRequest


def build_graph_router(
    *, logger: logging.Logger, rag_service: Any, log_graph_status
) -> APIRouter:
    """Build graph utility routes."""
    router = APIRouter()

    @router.post("/graph/from-text")
    def graph_from_text_endpoint(body: GraphRequest) -> dict[str, Any]:
        from graph.graph_text import graph_from_text

        logger.info("api:graph_from_text chars=%s", len(body.text or ""))
        log_graph_status("graph_from_text")
        return graph_from_text(body, rag_service=rag_service)

    @router.post("/graph/cache/clear")
    def clear_graph_cache_endpoint() -> dict[str, Any]:
        logger.info("api:graph_cache_clear")
        return rag_service.clear_graph_cache()

    return router
