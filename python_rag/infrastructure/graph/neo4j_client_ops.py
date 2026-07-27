"""Client-side policies for the Neo4j graph facade."""

from __future__ import annotations

from typing import Any, Callable

from infrastructure.graph.neo4j_transport import Neo4jQueryExecutor, Neo4jQueryExecutorProtocol
from common.reliability import is_retry_safe_write


def ensure_query_executor(
    current: Neo4jQueryExecutorProtocol | None,
    *,
    session_factory: Callable[[], Any],
    settings: object,
) -> Neo4jQueryExecutorProtocol:
    """Return an existing query executor or build one from graph settings."""

    if current is not None:
        return current
    return Neo4jQueryExecutor(
        session_factory,
        retry_attempts=max(1, int(getattr(settings, "retry_attempts", 1))),
        log_latency=bool(getattr(settings, "log_latency", False)),
        operation_attempts=getattr(settings, "retry_attempts_by_operation", None),
    )


def is_retryable_write(request_id: str | None, operation: str) -> bool:
    """Retry writes only when a caller provided an idempotency/request id."""

    return bool(request_id and is_retry_safe_write(operation))


__all__ = ["ensure_query_executor", "is_retryable_write"]
