"""Health endpoint router."""

from __future__ import annotations

import logging
from collections.abc import Callable
from typing import Any, Dict

from fastapi import APIRouter  # type: ignore[reportMissingImports]


def build_health_router(
    *, logger: logging.Logger, runtime_summary: Callable[[], Dict[str, Any]]
) -> APIRouter:
    """Build the /health route."""

    router = APIRouter()

    @router.get("/health")
    def health() -> Dict[str, Any]:
        logger.debug("health:ok")
        runtime: Dict[str, Any] = {}
        try:
            runtime = runtime_summary()
        except Exception as exc:
            runtime = {"error": str(exc)}
        return {"ok": True, "runtime": runtime}

    return router
