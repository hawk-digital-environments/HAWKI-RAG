"""Safe graph resource cleanup at indexer workflow boundaries."""

from __future__ import annotations

import logging
from typing import Protocol

from neo4j.exceptions import DriverError


class ClosableGraph(Protocol):
    """Graph resource that releases its driver connection."""

    def close(self) -> None:
        """Release graph resources."""


def close_graph_safely(
    graph: ClosableGraph,
    *,
    logger_obj: logging.Logger,
    operation: str,
) -> None:
    """Suppress a Neo4j close failure without masking workflow results."""

    try:
        graph.close()
    except DriverError as exc:
        logger_obj.warning(
            "graph:close failed operation=%s error=%s",
            operation,
            type(exc).__name__,
        )


__all__ = ["close_graph_safely"]
