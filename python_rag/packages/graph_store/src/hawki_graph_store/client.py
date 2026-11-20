"""Client-side policies for the Neo4j graph facade."""

from __future__ import annotations

from typing import Any, Callable

from hawki_graph_store.transport import (
    Neo4jQueryExecutor,
    Neo4jQueryExecutorProtocol,
)


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
        log_latency=bool(getattr(settings, "log_latency", False)),
    )


__all__ = ["ensure_query_executor"]
