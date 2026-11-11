"""Dataset authority API.

The management routes are internal-BFF routes: they require the
``rag:datasets:manage`` scope that only the trusted BFF signer can mint. The
self-grant routes are user routes and keep the exact public query/ingest
grant semantics. None of the routes accepts a physical scope or a tenant from
the caller.
"""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict, Field

from hawki_backend.auth.dependencies import require_scopes
from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind

from .errors import DatasetError, DatasetNotFoundError, DatasetUnavailableError
from .service import DatasetService

DATASET_ADMIN_SCOPE = "rag:datasets:manage"

router = APIRouter(tags=["datasets"])

_AdminPrincipal = Annotated[
    AuthenticatedPrincipal, Depends(require_scopes(DATASET_ADMIN_SCOPE))
]
_QueryPrincipal = Annotated[
    AuthenticatedPrincipal, Depends(require_scopes("rag:query"))
]
_IngestPrincipal = Annotated[
    AuthenticatedPrincipal, Depends(require_scopes("rag:ingest"))
]


class DatasetCreateBody(BaseModel):
    """The documented Laravel creation payload, in either spelling.

    Unknown fields are ignored, matching Laravel's validated-input behaviour:
    only the documented fields are ever forwarded to the authority. The
    physical, embedding and scope fields are declared so the service can
    refuse an explicit caller-selected value instead of silently ignoring it.
    """

    model_config = ConfigDict(extra="ignore")

    dataset_id: str | None = Field(default=None, max_length=191)
    datasetId: str | None = Field(default=None, max_length=191)
    name: str | None = Field(default=None, max_length=255)
    description: str | None = Field(default=None, max_length=100_000)
    status: str | None = Field(default=None, max_length=64)
    # These fields are accepted by the schema only so the service can refuse
    # them explicitly. A caller may never choose a physical target, an
    # embedding contract or a scope identity.
    qdrant_collection: str | None = Field(default=None, max_length=191)
    qdrantCollection: str | None = Field(default=None, max_length=191)
    neo4j_namespace: str | None = Field(default=None, max_length=191)
    neo4jNamespace: str | None = Field(default=None, max_length=191)
    physical_scope: str | None = Field(default=None, max_length=191)
    physicalScope: str | None = Field(default=None, max_length=191)
    embedding_provider: str | None = Field(default=None, max_length=80)
    embeddingProvider: str | None = Field(default=None, max_length=80)
    embedding_model: str | None = Field(default=None, max_length=160)
    embeddingModel: str | None = Field(default=None, max_length=160)
    graph_enabled: bool | None = None
    graphEnabled: bool | None = None
    tenant_id: str | None = Field(default=None, max_length=191)
    tenantId: str | None = Field(default=None, max_length=191)


class IngestGrantBody(BaseModel):
    """The optional one-time token of the ingest self-grant request.

    Unknown fields are ignored and the body itself is optional, matching the
    legacy request that accepts an absent body and then reports
    ``grant_token_required``.
    """

    model_config = ConfigDict(extra="ignore")

    grant_token: str | None = Field(default=None, max_length=191)


@router.get("/datasets")
def list_datasets(
    request: Request,
    principal: _AdminPrincipal,
    limit: int = 50,
) -> JSONResponse:
    """List the tenant's active datasets."""

    try:
        datasets = _service(request).list(principal.tenant_id, limit)
    except DatasetError as error:
        return _failure(error)
    return JSONResponse({"success": True, "datasets": datasets})


@router.post("/datasets")
def create_dataset(
    body: DatasetCreateBody,
    request: Request,
    principal: _AdminPrincipal,
) -> JSONResponse:
    """Create one dataset and return its one-time bootstrap token."""

    try:
        service = _service(request)
        creation = service.create(
            # Presence, not only non-null values, marks a caller-selected scope
            # field. Unknown fields still never reach the service.
            principal.tenant_id,
            body.model_dump(exclude_unset=True),
        )
        dataset = service.show(principal.tenant_id, creation.record.dataset_id)
    except DatasetError as error:
        return _failure(error)
    payload: dict = {
        "success": True,
        "dataset_id": creation.record.dataset_id,
        "dataset": dataset,
    }
    if creation.grant_token is not None:
        payload["grant_token"] = creation.grant_token
    return JSONResponse(payload, status_code=201 if creation.created else 200)


@router.get("/datasets/{dataset_id}")
def show_dataset(
    dataset_id: str,
    request: Request,
    principal: _AdminPrincipal,
) -> JSONResponse:
    """Return one active dataset with its details."""

    try:
        dataset = _service(request).show(principal.tenant_id, dataset_id)
    except DatasetNotFoundError:
        return JSONResponse(
            {
                "success": False,
                "message": f"Dataset {dataset_id} was not found.",
            },
            status_code=404,
        )
    except DatasetError as error:
        return _failure(error)
    if dataset is None:
        return JSONResponse(
            {
                "success": False,
                "message": f"Dataset {dataset_id} was not found.",
            },
            status_code=404,
        )
    return JSONResponse({"success": True, "dataset": dataset})


@router.delete("/datasets/{dataset_id}/storage")
async def delete_dataset(
    dataset_id: str,
    request: Request,
    principal: _AdminPrincipal,
) -> JSONResponse:
    """Archive a dataset and drive every owned source through deletion."""

    try:
        result = await _service(request).delete(principal.tenant_id, dataset_id)
    except DatasetError as error:
        return _failure(error)
    if result is None:
        return JSONResponse(
            {
                "success": False,
                "message": f"Dataset {dataset_id} was not found.",
            },
            status_code=404,
        )
    pending = result.cleanup_pending
    return JSONResponse(
        {
            "success": True,
            "message": (
                f"Dataset {dataset_id} is hidden. Scoped cleanup will continue "
                "automatically in the background."
                if pending
                else f"Dataset {dataset_id} cleanup is complete."
            ),
            "cleanup": {
                "dataset_id": result.record.dataset_id,
                "status": result.record.status,
                "cleanup_pending": pending,
            },
        },
        status_code=202 if pending else 200,
    )


@router.post("/datasets/{dataset_id}/query-grants/self")
def grant_self_query(
    dataset_id: str,
    request: Request,
    principal: _QueryPrincipal,
) -> JSONResponse:
    """Grant the current query principal access to one ready dataset."""

    user = _user(principal)
    try:
        _service(request).grant_query(user, dataset_id)
    except DatasetError as error:
        return _failure(error)
    return JSONResponse(
        {
            "success": True,
            "dataset_id": dataset_id,
            "query_access": {"granted": True, "permission": "query"},
        }
    )


@router.post("/datasets/{dataset_id}/ingest-grants/self")
def grant_self_ingest(
    dataset_id: str,
    request: Request,
    principal: _IngestPrincipal,
    body: IngestGrantBody | None = None,
) -> JSONResponse:
    """Redeem the creator's one-time token into an exact ingest grant."""

    user = _user(principal)
    grant_token = body.grant_token if body is not None else None
    try:
        replayed = _service(request).grant_ingest(user, dataset_id, grant_token)
    except DatasetError as error:
        return _failure(error)
    return JSONResponse(
        {
            "success": True,
            "dataset_id": dataset_id,
            "ingest_access": {"granted": True, "permission": "ingest"},
            "replayed": replayed,
        }
    )


@router.get("/query/datasets")
def query_datasets(
    request: Request,
    principal: _QueryPrincipal,
) -> JSONResponse:
    """Return exactly the datasets the current user may query."""

    try:
        datasets = _service(request).query_datasets(principal)
    except DatasetError as error:
        return _failure(error)
    return JSONResponse({"success": True, "datasets": datasets})


def _service(request: Request) -> DatasetService:
    service = getattr(request.app.state, "dataset_service", None)
    if service is None:
        raise DatasetUnavailableError()
    return service


def _user(principal: AuthenticatedPrincipal) -> AuthenticatedPrincipal:
    if principal.kind is not PrincipalKind.USER:
        raise HTTPException(status_code=403, detail="insufficient_scope")
    return principal


def _failure(error: DatasetError) -> JSONResponse:
    return JSONResponse(status_code=error.status_code, content=error.payload())


__all__ = ["DATASET_ADMIN_SCOPE", "router"]
