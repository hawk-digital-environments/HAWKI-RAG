"""Authenticated ops HTTP boundary. All failures retain Python authority."""

import logging
from collections.abc import Callable
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, Request
import sqlalchemy as sa
from hawki_backend.workflow_control.schema import operations
from hawki_backend.auth.dependencies import require_scopes
from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.api.v1.query import QueryRequestBody, query
from .schemas import ExpandBody, ClearBody, ClearReceipt
from .service import OpsService
from .admission import prepare_clear, finish_clear

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/ops", tags=["graph-ops"])
Reader = Annotated[AuthenticatedPrincipal, Depends(require_scopes("rag:query"))]
Manager = Annotated[
    AuthenticatedPrincipal, Depends(require_scopes("rag:datasets:manage"))
]
Dataset = Annotated[str, Query(min_length=1, max_length=191)]


def service(request: Request) -> OpsService:
    value = getattr(request.app.state, "ops_service", None)
    if value is None:
        raise HTTPException(503, "ops_unavailable")
    return value


def execute(operation: Callable[[], dict]) -> dict:
    try:
        return operation()
    except HTTPException:
        raise
    except Exception as error:
        logger.warning("Graph operations unavailable error=%s", type(error).__name__)
        raise HTTPException(503, "ops_unavailable") from None


@router.get("/graph/overview")
def overview(
    request: Request,
    principal: Reader,
    dataset_id: Dataset,
    limit: int = Query(default=80, ge=1, le=300),
):
    return execute(
        lambda: service(request).read(
            principal, dataset_id, "graph_overview", limit=limit
        )
    )


@router.get("/graph/search")
def search(
    request: Request,
    principal: Reader,
    dataset_id: Dataset,
    q: str = Query(min_length=1, max_length=4000),
    limit: int = Query(default=12, ge=1, le=50),
):
    return execute(
        lambda: service(request).read(
            principal, dataset_id, "graph_search", query=q, limit=limit
        )
    )


@router.get("/graph/node")
def node(
    request: Request,
    principal: Reader,
    dataset_id: Dataset,
    node_id: str = Query(min_length=1, max_length=4096),
):
    return execute(
        lambda: service(request).read(
            principal, dataset_id, "graph_node", node_id=node_id
        )
    )


@router.post("/graph/expand")
def expand(request: Request, principal: Reader, body: ExpandBody):
    return execute(
        lambda: service(request).read(
            principal,
            body.dataset_id,
            "graph_expand",
            node_id=body.node_id,
            depth=max(1, min(3, body.depth)),
            limit=body.limit,
        )
    )


@router.get("/graph/semantic-search")
def semantic_search(
    request: Request,
    principal: Reader,
    dataset_id: Dataset,
    q: str = Query(min_length=1, max_length=4000),
    limit: int = Query(default=8, ge=1, le=25),
):
    def run():
        ops = service(request)
        ops.dataset(principal, dataset_id)
        result = query(
            QueryRequestBody(
                dataset_id=dataset_id,
                query=q,
                top_k=limit,
                generate=False,
                fast_mode=False,
            ),
            principal,
            request,
        )
        names = {
            fact.get(key)
            for fact in result.kg
            for key in ("subject", "object")
            if fact.get(key)
        }
        return ops.read(
            principal,
            dataset_id,
            "graph_search",
            query=q,
            names=sorted(names),
            limit=limit,
        )

    return execute(run)


@router.post("/graph/clear")
def clear(request: Request, principal: Manager, body: ClearBody):
    return execute(lambda: service(request).clear(principal, body.dataset_id))


@router.post("/graph/clear-admission", include_in_schema=False)
def clear_admission(request: Request, principal: Manager, body: ClearBody):
    """Legacy executor uses the same Python-owned durable admission decision."""

    def run():
        ops = service(request)
        ops.dataset(principal, body.dataset_id, manager=True)
        operation, namespace = prepare_clear(
            ops.engine, principal.tenant_id, body.dataset_id
        )
        return {
            "ok": True,
            "operation_id": operation,
            "dataset_id": body.dataset_id,
            "neo4j_namespace": namespace,
        }

    return execute(run)


@router.post("/graph/clear-receipt", include_in_schema=False)
def clear_receipt(request: Request, principal: Manager, body: ClearReceipt):
    def run():
        ops = service(request)
        with ops.engine.connect() as connection:
            row = (
                connection.execute(
                    sa.select(operations).where(
                        operations.c.operation_id == body.operation_id,
                        operations.c.action == "graph_clear",
                        operations.c.payload["tenant_id"].as_string()
                        == principal.tenant_id,
                        operations.c.payload["dataset_id"].as_string()
                        == body.dataset_id,
                    )
                )
                .mappings()
                .first()
            )
        if row is None:
            raise HTTPException(404, "dataset_not_found")
        if row["status"] == "confirmed":
            return {"ok": True}
        finish_clear(
            ops.engine,
            body.operation_id,
            {
                "deleted_nodes": body.deleted_nodes,
                "deleted_relationships": body.deleted_relationships,
            }
            if body.success
            else None,
        )
        return {"ok": body.success}

    return execute(run)


@router.get("/stats")
def stats(request: Request, principal: Manager):
    return execute(lambda: service(request).stats(principal))


@router.get("/config")
def config(request: Request, principal: Manager):
    return execute(lambda: service(request).config(principal))


@router.get("/graph/scope", include_in_schema=False)
def scope(request: Request, principal: Reader, dataset_id: Dataset):
    """Internal BFF projection for scoped legacy graph reads."""

    def run():
        record = service(request).dataset(principal, dataset_id)
        return {"dataset_id": dataset_id, "neo4j_namespace": record.neo4j_namespace}

    return execute(run)
