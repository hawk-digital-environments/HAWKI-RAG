"""Config endpoint router."""

from __future__ import annotations

import logging

from fastapi import APIRouter  # type: ignore[reportMissingImports]

from app.settings import AppSettings
from app.config_response import build_config_response


def build_config_router(
    *, logger: logging.Logger, get_provider, qdrant_factory, app_settings: AppSettings
) -> APIRouter:
    """Build the /config route."""

    router = APIRouter()

    @router.get("/config")
    def config():
        response = build_config_response(
            get_provider=get_provider,
            qdrant_factory=qdrant_factory,
            app_settings=app_settings,
        )
        logger.info(
            "config:provider=%s qdrant_collection=%s",
            response["provider"],
            response["qdrant_collection"],
        )
        return response

    return router
