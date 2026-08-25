"""Managed Neo4j query execution with final-failure telemetry."""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from typing import Any, Protocol, TypeVar

from neo4j.exceptions import DriverError, Neo4jError

from hawki_rag_stores.neo4j.errors import classify_neo4j_error
from hawki_rag_stores.neo4j.requests import Neo4jQueryRequest
from hawki_rag_resilience.reliability import (
    NEO4J_ADAPTER_EVENT,
    sanitize_for_log,
)

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
    This adapter deliberately does not add a second retry loop; it records only
    the final ``Neo4jError`` or ``DriverError`` after driver retries are done.
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
        try:
            with self._session_factory() as session:
                run = session.execute_write if write else session.execute_read
                result = run(callback)
        except (Neo4jError, DriverError) as exc:
            policy = classify_neo4j_error(exc)
            logger.warning(
                "event=%s operation=%s request_id=%s elapsed_ms=%.3f "
                "reason=%s family=%s retryable=%s "
                "retry_owner=neo4j_driver_managed_transaction "
                "commit_outcome_unknown=%s",
                NEO4J_ADAPTER_EVENT,
                op,
                sanitize_for_log(request_id),
                (time.perf_counter() - started) * 1000,
                type(exc).__name__,
                policy.family,
                policy.retryable,
                policy.commit_outcome_unknown,
            )
            raise

        if self._log_latency:
            logger.info(
                "event=%s operation=%s request_id=%s elapsed_ms=%.3f "
                "retry_owner=neo4j_driver_managed_transaction",
                NEO4J_ADAPTER_EVENT,
                op,
                sanitize_for_log(request_id),
                (time.perf_counter() - started) * 1000,
            )
        return result
