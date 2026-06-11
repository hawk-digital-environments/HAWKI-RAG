"""FastAPI dependency helpers for service access."""
from __future__ import annotations

from typing import Any

from fastapi import HTTPException  # type: ignore[reportMissingImports]


def get_provider_or_400(rag_service: Any, name: str) -> Any:
    try:
        return rag_service.get_provider(name)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
