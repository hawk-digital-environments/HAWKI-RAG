"""Managed Neo4j query execution."""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from typing import Any, Protocol, TypeVar

from hawki_observability.redaction import sanitize_for_log

from hawki_graph_store.logging_events import NEO4J_ADAPTER_QUERY
from hawki_graph_store.requests import Neo4jQueryRequest

SessionFactory = Callable[[], Any]
QueryResult = TypeVar("QueryResult")
QueryCallback = Callable[[Any], QueryResult]


class Neo4jQueryExecutorProtocol(Protocol):
    """Adapter contract for query execution."""

    def run_read(
        self, query: Neo4jQueryRequest, callback: QueryCallback
    ) -> QueryResult:
        """Execute a read query."""

    def run_write(
        self, query: Neo4jQueryRequest, callback: QueryCallback
    ) -> QueryResult:
        """Execute a write query."""


logger = logging.getLogger(__name__)


class Neo4jQueryExecutor:
    """Execute Neo4j query requests through an injected session factory.

    ``session.execute_read`` and ``session.execute_write`` own retries.  The
    driver can invoke a callback more than once, so callbacks must be
    idempotent and return materialized values rather than a live ``Result``.
    This adapter deliberately does not add a second retry loop.
    """

    def __init__(
        self,
        session_factory: SessionFactory,
        *,
        log_latency: bool = False,
    ) -> None:
        self._session_factory = session_factory
        self._log_latency = bool(log_latency)

    def run_read(
        self, query: Neo4jQueryRequest, callback: QueryCallback[QueryResult]
    ) -> QueryResult:
        """Execute a read transaction for the request callback."""
        return self._run(query, callback, write=False)

    def run_write(
        self, query: Neo4jQueryRequest, callback: QueryCallback[QueryResult]
    ) -> QueryResult:
        """Execute a write transaction for the request callback."""
        return self._run(query, callback, write=True)

    def _run(
        self,
        query: Neo4jQueryRequest,
        callback: QueryCallback[QueryResult],
        *,
        write: bool,
    ) -> QueryResult:
        op = query.operation or "neo4j.query"
        request_id = query.request_id or ""
        started = time.perf_counter()
        with self._session_factory() as session:
            run = session.execute_write if write else session.execute_read
            result = run(callback)

        if self._log_latency:
            logger.info(
                "event=%s operation=%s request_id=%s elapsed_ms=%.3f "
                "retry_owner=neo4j_driver_managed_transaction",
                NEO4J_ADAPTER_QUERY,
                op,
                sanitize_for_log(request_id),
                (time.perf_counter() - started) * 1000,
            )
        return result
