"""Read-only bridge configuration endpoint."""

from fastapi import APIRouter

from hawki_bridge.application.config_response import build_config_response


def build_config_router(*, service, qdrant_factory, settings) -> APIRouter:
    router = APIRouter()

    @router.get("/config")
    def config() -> dict[str, object]:
        return build_config_response(
            get_provider=service.get_provider,
            qdrant_factory=qdrant_factory,
            settings=settings,
        )

    return router


__all__ = ["build_config_router"]
