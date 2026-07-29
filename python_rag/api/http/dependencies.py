"""FastAPI dependency helpers for service access."""
from __future__ import annotations

from fastapi import HTTPException

from domain.ports import ModelProvider, ProviderResolver


def get_provider_or_400(rag_service: ProviderResolver, name: str) -> ModelProvider:
    try:
        return rag_service.get_provider(name)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
