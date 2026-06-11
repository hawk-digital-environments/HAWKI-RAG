from __future__ import annotations

import logging
import time
from collections.abc import Callable
from typing import Any, Protocol, TypeVar

from neo4j import exceptions as neo4j_exceptions  # type: ignore[reportMissingImports]

from graph.neo4j_requests import Neo4jQueryRequest

SessionFactory = Callable[[], Any]
QueryResult = TypeVar("QueryResult")
QueryCallback = Callable[[Any], QueryResult]


class Neo4jQueryExecutorProtocol(Protocol):
    """Adapter contract for query execution."""

    def run_read(self, query: Neo4jQueryRequest, callback: QueryCallback) -> QueryResult:
        """Execute a read query."""

    def run_write(self, query: Neo4jQueryRequest, callback: QueryCallback) -> QueryResult:
        """Execute a write query."""

logger = logging.getLogger(__name__)


class Neo4jQueryExecutor:
    """Execute Neo4j query requests through an injected session factory.

    This adapter owns retry and latency instrumentation for all graph reads/writes.
    """

    def __init__(
        self,
        session_factory: SessionFactory,
        *,
        retry_attempts: int = 3,
        log_latency: bool = False,
        backoff_seconds: float = 0.5,
        backoff_cap_seconds: float = 5.0,
    ) -> None:
        self._session_factory = session_factory
        self._retry_attempts = max(1, int(retry_attempts))
        self._log_latency = bool(log_latency)
        self._backoff_seconds = max(0.0, float(backoff_seconds))
        self._backoff_cap_seconds = max(0.0, float(backoff_cap_seconds))

    def run_read(self, query: Neo4jQueryRequest, callback: QueryCallback[QueryResult]) -> QueryResult:
        """Execute a read transaction for the request callback."""
        return self._run(query, callback, write=False)

    def run_write(self, query: Neo4jQueryRequest, callback: QueryCallback[QueryResult]) -> QueryResult:
        """Execute a write transaction for the request callback."""
        return self._run(query, callback, write=True)

    def _run(self, query: Neo4jQueryRequest, callback: QueryCallback[QueryResult], *, write: bool) -> QueryResult:
        attempt = 0
        backoff = self._backoff_seconds
        while True:
            attempt += 1
            try:
                start = time.perf_counter()
                with self._session_factory() as session:
                    run = session.execute_write if write else session.execute_read
                    result = run(callback)
                if self._log_latency:
                    elapsed = time.perf_counter() - start
                    op = "write" if write else "query"
                    logger.info("Neo4j %s in %.3fs: %s", op, elapsed, query.statement)
                return result
            except neo4j_exceptions.Neo4jError as exc:
                if attempt >= self._retry_attempts:
                    logger.warning("Neo4j %s failed: %s", "write" if write else "query", exc)
                    raise
                logger.warning(
                    "Neo4j %s failed (%s). Retrying attempt %s/%s",
                    "write" if write else "query",
                    exc,
                    attempt,
                    self._retry_attempts,
                )
                if backoff > 0:
                    time.sleep(backoff)
                backoff = min(backoff * 2, self._backoff_cap_seconds)
