"""Small Qdrant client policies shared by the HTTP facade."""

from __future__ import annotations

from collections.abc import Callable
from inspect import signature

from hawki_rag_resilience.optional_imports import import_required_module


class _UnavailableRequestsError(Exception):
    """Internal sentinel used when requests is not installed."""


def request_exception_type() -> type[BaseException]:
    """Return requests' base exception without importing it at module load."""

    try:
        return import_required_module(
            "requests",
            install_hint="Install hawki-rag-stores to use Qdrant HTTP transport.",
        ).exceptions.RequestException
    except RuntimeError:
        return _UnavailableRequestsError


def callable_supports_kwarg(target: object, method_name: str, kwarg: str) -> bool:
    """Return whether a method accepts one named keyword or arbitrary keywords."""

    method = getattr(target, method_name, None)
    if method is None:
        return False
    try:
        params = signature(method).parameters.values()
    except (TypeError, ValueError):
        return False
    return any(
        param.name == kwarg or param.kind == param.VAR_KEYWORD for param in params
    )


def gateway_supports_operation_id(gateway: object, method_name: str) -> bool:
    """Return whether a gateway method accepts an idempotency operation id."""

    method = getattr(gateway, method_name, None)
    if method is None:
        return False
    try:
        params = signature(method).parameters.values()
    except (TypeError, ValueError):
        return False
    return any(
        param.name == "operation_id" or param.kind == param.VAR_KEYWORD
        for param in params
    )


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
    "callable_supports_kwarg",
    "gateway_supports_operation_id",
    "request_exception_type",
    "resolve_per_collection_limit",
    "resolve_selected_collection",
]
