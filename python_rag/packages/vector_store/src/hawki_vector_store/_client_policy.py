"""Small policies internal to the Qdrant vector-store adapter."""

from __future__ import annotations

from collections.abc import Callable


def resolve_per_collection_limit(requested_limit: int, fallback_limit: int) -> int:
    """Preserve legacy behavior where empty env values used the requested top-k."""

    if requested_limit > 0:
        return requested_limit
    return fallback_limit


def resolve_selected_collection(
    current: str | None,
    pick_default: Callable[[], str | None],
) -> str:
    """Resolve an explicit collection or lazily pick a default."""

    if current:
        return current
    return pick_default() or ""


__all__ = [
    "resolve_per_collection_limit",
    "resolve_selected_collection",
]
