"""Health endpoint router."""

from __future__ import annotations

import logging
from collections.abc import Callable
from typing import Any

from fastapi import APIRouter


def build_health_router(
    *, logger: logging.Logger, runtime_summary: Callable[[], dict[str, Any]]
) -> APIRouter:
    """Build the /health route."""

    router = APIRouter()

    @router.get("/health")
    def health() -> dict[str, Any]:
        logger.debug("health:ok")
        runtime: dict[str, Any] = {}
        try:
            runtime = runtime_summary()
        except Exception as exc:
            runtime = {"error": str(exc)}
        return {"ok": True, "runtime": runtime}

    return router
