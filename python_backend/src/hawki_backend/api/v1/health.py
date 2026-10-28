"""Process health endpoint with no external dependency checks."""

from typing import Literal

from fastapi import APIRouter
from pydantic import BaseModel


class HealthResponse(BaseModel):
    """Response showing that the application can serve requests."""

    status: Literal["ok"] = "ok"


router = APIRouter()


@router.get("/health", response_model=HealthResponse)
async def health() -> HealthResponse:
    """Return the application's process health."""
    return HealthResponse()
