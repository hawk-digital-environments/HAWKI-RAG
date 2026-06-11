"""Query execution adapter for Neo4j with bounded retry + telemetry."""
from __future__ import annotations

import logging
import time
from collections.abc import Callable, Mapping
from typing import Any, Protocol, TypeVar

try:
    from neo4j import exceptions as neo4j_exceptions
except Exception:  # pragma: no cover - optional dependency
    neo4j_exceptions = None

from infrastructure.graph.neo4j_requests import Neo4jQueryRequest
from common.reliability import NEO4J_ADAPTER_EVENT, is_retryable_neo4j_exception, sanitize_for_log

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
        operation_attempts: Mapping[str, int] | None = None,
    ) -> None:
        self._session_factory = session_factory
        self._retry_attempts = max(1, int(retry_attempts))
        self._log_latency = bool(log_latency)
        self._backoff_seconds = max(0.0, float(backoff_seconds))
        self._backoff_cap_seconds = max(0.0, float(backoff_cap_seconds))
        self._operation_attempts = dict(operation_attempts or {})

    def run_read(self, query: Neo4jQueryRequest, callback: QueryCallback[QueryResult]) -> QueryResult:
        """Execute a read transaction for the request callback."""
        return self._run(query, callback, write=False)

    def run_write(self, query: Neo4jQueryRequest, callback: QueryCallback[QueryResult]) -> QueryResult:
        """Execute a write transaction for the request callback."""
        return self._run(query, callback, write=True)

    def _attempt_budget(self, query: Neo4jQueryRequest) -> int:
        operation = query.operation
        if operation is None:
            return self._retry_attempts
        return max(1, int(self._operation_attempts.get(operation, self._retry_attempts)))

    def _is_retryable_exception(self, exc: Exception) -> bool:
        return is_retryable_neo4j_exception(exc)

    def _run(
        self,
        query: Neo4jQueryRequest,
        callback: QueryCallback[QueryResult],
        *,
        write: bool,
    ) -> QueryResult:
        attempt = 0
        backoff = self._backoff_seconds
        max_attempts = self._attempt_budget(query)
        op = query.operation or "neo4j.query"
        request_id = query.request_id or ""

        while True:
            attempt += 1
            started = time.perf_counter()
            try:
                with self._session_factory() as session:
                    run = session.execute_write if write else session.execute_read
                    result = run(callback)
                elapsed_ms = (time.perf_counter() - started) * 1000
                if self._log_latency:
                    logger.info(
                        "event=%s operation=%s request_id=%s attempt=%s/%s elapsed_ms=%.3f backoff_ms=%.2f timeout_ms=%.2f",
                        NEO4J_ADAPTER_EVENT,
                        op,
                        sanitize_for_log(request_id),
                        attempt,
                        max_attempts,
                        elapsed_ms,
                        backoff * 1000,
                        0.0,
                    )
                return result
            except Exception as exc:
                if neo4j_exceptions is None or not isinstance(exc, neo4j_exceptions.Neo4jError):
                    raise
                elapsed_ms = (time.perf_counter() - started) * 1000
                if attempt >= max_attempts or not query.retryable or not self._is_retryable_exception(exc):
                    logger.warning(
                        "event=%s operation=%s request_id=%s attempt=%s/%s elapsed_ms=%.3f timeout_ms=%.2f reason=%s retryable=%s",
                        NEO4J_ADAPTER_EVENT,
                        op,
                        sanitize_for_log(request_id),
                        attempt,
                        max_attempts,
                        elapsed_ms,
                        0.0,
                        sanitize_for_log(type(exc).__name__, max_length=120),
                        query.retryable,
                    )
                    raise
                logger.warning(
                    "event=%s operation=%s request_id=%s attempt=%s/%s elapsed_ms=%.3f retry_after_ms=%.2f timeout_ms=%.2f reason=%s",
                    NEO4J_ADAPTER_EVENT,
                    op,
                    sanitize_for_log(request_id),
                    attempt,
                    max_attempts,
                    elapsed_ms,
                    backoff * 1000,
                    0.0,
                    type(exc).__name__,
                )
                if backoff > 0:
                    time.sleep(backoff)
                backoff = min(backoff * 2, self._backoff_cap_seconds)
