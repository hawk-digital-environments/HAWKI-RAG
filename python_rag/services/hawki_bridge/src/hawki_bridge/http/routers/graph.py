"""Strictly read-only, dataset-scoped graph endpoint."""

from fastapi import APIRouter

from hawki_bridge.application.graph import GraphReadService
from hawki_bridge.http.schemas import GraphReadRequest


def build_graph_router(*, service: GraphReadService) -> APIRouter:
    router = APIRouter(prefix="/graph", tags=["graph"])

    @router.post("/related")
    def related(body: GraphReadRequest) -> dict[str, object]:
        return {
            "facts": service.retrieve_related_graph(
                body.terms,
                authorized_scope=body.authorized_scope,
                limit=body.limit,
            )
        }

    return router


__all__ = ["build_graph_router"]
