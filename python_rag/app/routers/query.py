"""Query endpoint router."""

from __future__ import annotations

import logging
from typing import Any

from fastapi import APIRouter

from app.dependencies import get_provider_or_400
from app.schemas import QueryRequest, apply_query_request_settings
from app.settings import AppSettings


def build_query_router(*, logger: logging.Logger, rag_service: Any, app_settings: AppSettings) -> APIRouter:
    """Build query routes."""

    router = APIRouter()

    def get_provider(name: str) -> Any:
        return get_provider_or_400(rag_service, name)

    @router.post("/query")
    def query(body: QueryRequest) -> dict[str, Any]:
        from app.query import query_documents
        body = apply_query_request_settings(body, app_settings)

        logger.info(
            "api:query top_k=%s fast=%s smart=%s",
            body.top_k,
            body.fast_mode,
            body.smart_lookup,
        )
        return query_documents(body, rag_service=rag_service, get_provider=get_provider)

    return router
