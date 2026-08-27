"""Dataset-scoped query endpoint."""

from fastapi import APIRouter

from hawki_rag_contracts.retrieval.query import QueryResponse

from hawki_bridge.application.dependencies import QueryDependencies
from hawki_bridge.application.query.execution import execute_authorized_query
from hawki_bridge.domain.errors import BridgeQueryError
from hawki_bridge.http.errors import query_error_to_http_exception
from hawki_bridge.http.schemas import QueryRequest, apply_query_settings
from hawki_bridge.settings import BridgeSettings


def build_query_router(
    *,
    settings: BridgeSettings,
    dependencies: QueryDependencies,
) -> APIRouter:
    """Build the HTTP adapter around the typed query application use case."""

    router = APIRouter()

    @router.post("/query", response_model=QueryResponse)
    def query(body: QueryRequest) -> QueryResponse:
        configured = apply_query_settings(body, settings)
        try:
            return execute_authorized_query(configured, dependencies=dependencies)
        except BridgeQueryError as exc:
            raise query_error_to_http_exception(exc) from exc

    return router


__all__ = ["build_query_router"]
