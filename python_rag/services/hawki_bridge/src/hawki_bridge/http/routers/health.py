"""Bridge liveness and lightweight runtime information."""

from fastapi import APIRouter, Query


def build_health_router(*, runtime_summary) -> APIRouter:
    router = APIRouter()

    @router.get("/health")
    def health(runtime: bool = Query(default=True)) -> dict[str, object]:
        return {
            "ok": True,
            "runtime": runtime_summary() if runtime else {},
        }

    return router


__all__ = ["build_health_router"]
