"""Health endpoint router."""

from __future__ import annotations

import logging
from collections.abc import Callable
from typing import Any

from fastapi import APIRouter, Query


def build_health_router(
    *, logger: logging.Logger, runtime_summary: Callable[[], dict[str, Any]]
) -> APIRouter:
    """Build the /health route."""

    router = APIRouter()

    @router.get("/health")
    def health(include_runtime: bool = Query(default=True, alias="runtime")) -> dict[str, Any]:
        logger.debug("health:ok")
        runtime_payload: dict[str, Any] = {}
        if include_runtime:
            try:
                runtime_payload = runtime_summary()
            except Exception as exc:
                runtime_payload = {"error": str(exc)}
        return {"ok": True, "runtime": runtime_payload}

    return router
