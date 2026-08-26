"""Dataset-scoped query endpoint."""

from fastapi import APIRouter

from hawki_bridge.application.query.orchestration import query_documents
from hawki_bridge.http.dependencies import get_provider_or_400
from hawki_bridge.http.schemas import QueryRequest, apply_query_settings


def build_query_router(*, service, settings, dependencies) -> APIRouter:
    router = APIRouter()

    @router.post("/query")
    def query(body: QueryRequest) -> dict[str, object]:
        configured = apply_query_settings(body, settings)
        return query_documents(
            configured,
            rag_service=service,
            get_provider=lambda name: get_provider_or_400(service, name),
            dependencies=dependencies,
        )

    return router


__all__ = ["build_query_router"]
