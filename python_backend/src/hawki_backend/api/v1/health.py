"""Process health endpoint with no external dependency checks."""

from typing import Literal

from fastapi import APIRouter, Depends
from hawki_backend.auth.dependencies import require_scopes
from pydantic import BaseModel


class HealthResponse(BaseModel):
    """Response showing that the application can serve requests."""

    status: Literal["ok"] = "ok"


router = APIRouter()


@router.get("/health", response_model=HealthResponse)
async def health() -> HealthResponse:
    """Return the application's process health."""
    return HealthResponse()


@router.get("/readiness", include_in_schema=False)
def readiness(
    principal=Depends(require_scopes("rag:datasets:manage")),
):
    """Authenticated, bounded, read-only probes; health remains process liveness."""
    import os
    from fastapi.responses import JSONResponse
    from hawki_backend.readiness import report

    result = report(os.environ, live=True)
    return JSONResponse(result, status_code=200 if result["status"] == "PASS" else 503)


@router.get("/readiness/identity", include_in_schema=False)
def readiness_identity(principal=Depends(require_scopes("rag:datasets:manage"))):
    """Non-mutating proof that the registered service key and claims pass this verifier."""
    return {"tenant_id": principal.tenant_id, "client_id": principal.client_id}
