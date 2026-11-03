"""Retired bridge control surface: one authoritative backend, no SDK fallback."""

from fastapi import APIRouter, HTTPException


def build_temporal_router(*, settings, logger, client_factory=None) -> APIRouter:
    router = APIRouter(prefix="/temporal", tags=["retired-control"])

    @router.post("/{operation:path}")
    async def retired(operation: str) -> dict:
        raise HTTPException(410, "Workflow control moved to the authenticated backend.")

    return router
