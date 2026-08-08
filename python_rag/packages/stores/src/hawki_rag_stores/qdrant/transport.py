"""Low-level HTTP transport adapter for Qdrant."""

from __future__ import annotations

import logging
import time
from collections.abc import Mapping
from typing import Any

from hawki_rag_stores.qdrant.requests import QdrantRequest
from hawki_rag_resilience.reliability import (
    QDRANT_ADAPTER_EVENT,
    QDRANT_RETRYABLE_STATUS_CODES,
    normalize_retry_attempt_limit,
    is_retryable_http_exception,
    sanitize_for_log,
)
from hawki_rag_resilience.optional_imports import import_required_module

logger = logging.getLogger(__name__)


class _UnavailableRequestsError(Exception):
    """Internal sentinel used when requests is not installed."""


def _requests_module() -> Any:
    return import_required_module(
        "requests",
        install_hint="Install hawki-rag-stores to use Qdrant HTTP transport.",
    )


def _requests_session() -> Any:
    return _requests_module().Session()


def _request_exception_type() -> type[BaseException]:
    try:
        return _requests_module().exceptions.RequestException
    except RuntimeError:
        return _UnavailableRequestsError


class QdrantHTTPTransport:
    """Reusable transport for request execution with retry and latency logging."""

    def __init__(
        self,
        *,
        base_url: str,
        api_key: str | None,
        default_timeout: float,
        max_attempts: int = 3,
        log_latency: bool = False,
        operation_attempts: Mapping[str, int] | None = None,
        backoff_cap_seconds: float = 5.0,
        backoff_seconds: float = 0.5,
        default_retryable: bool = True,
        session: Any | None = None,
    ) -> None:
        self.base_url = base_url
        self.api_key = api_key
        self.default_timeout = default_timeout
        self._default_attempts = max(1, int(max_attempts))
        self._operation_attempts = dict(operation_attempts or {})
        self.log_latency = log_latency
        self._backoff_cap_seconds = max(0.0, float(backoff_cap_seconds))
        self._backoff_seconds = max(0.0, float(backoff_seconds))
        self._session = session or _requests_session()
        self._default_retryable = bool(default_retryable)

    def _headers(self) -> dict[str, str]:
        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["api-key"] = self.api_key
        return headers

    def _attempt_budget(self, request: QdrantRequest) -> int:
        operation = request.operation
        if operation is None:
            return self._default_attempts
        return normalize_retry_attempt_limit(
            int(self._operation_attempts.get(operation, self._default_attempts)),
            minimum=1,
        )

    def _is_retryable_exception(self, exc: Exception) -> bool:
        return self._default_retryable and is_retryable_http_exception(exc)

    def send(self, request: QdrantRequest) -> Any:
        """Execute one HTTP request with retry and optional latency logging."""
        url = f"{self.base_url}{request.path}"
        operation = request.operation or "qdrant.request"
        timeout = self.default_timeout if request.timeout is None else request.timeout
        payload_kwargs: dict[str, Any] = {
            "headers": self._headers(),
            "timeout": timeout,
        }
        if request.json_body is not None:
            payload_kwargs["json"] = request.json_body

        max_attempts = self._attempt_budget(request)
        backoff = self._backoff_seconds
        attempt = 0
        while True:
            attempt += 1
            started = time.perf_counter()
            timeout_ms = float(timeout) * 1000
            try:
                response = self._session.request(request.method, url, **payload_kwargs)
                elapsed_ms = (time.perf_counter() - started) * 1000
                logger.info(
                    "event=%s operation=%s request_id=%s attempt=%s/%s status=%s elapsed_ms=%.3f timeout_ms=%.2f backoff_ms=%.2f retryable=%s",
                    QDRANT_ADAPTER_EVENT,
                    operation,
                    sanitize_for_log(request.operation_id or ""),
                    attempt,
                    max_attempts,
                    response.status_code,
                    elapsed_ms,
                    timeout_ms,
                    backoff * 1000,
                    request.retryable,
                )
                should_retry = (
                    request.retryable
                    and response.status_code in QDRANT_RETRYABLE_STATUS_CODES
                    and attempt < max_attempts
                )
                if should_retry:
                    logger.warning(
                        "event=%s operation=%s request_id=%s attempt=%s/%s elapsed_ms=%.3f retry_after_ms=%.2f reason=status=%s timeout_ms=%.2f",
                        QDRANT_ADAPTER_EVENT,
                        operation,
                        sanitize_for_log(request.operation_id or ""),
                        attempt,
                        max_attempts,
                        elapsed_ms,
                        backoff * 1000,
                        response.status_code,
                        timeout_ms,
                    )
                    time.sleep(backoff)
                    backoff = min(backoff * 2, self._backoff_cap_seconds)
                    continue
                return response
            except Exception as exc:
                request_exception_type = _request_exception_type()
                if not isinstance(exc, request_exception_type):
                    raise
                elapsed_ms = (time.perf_counter() - started) * 1000
                if (
                    attempt >= max_attempts
                    or not self._is_retryable_exception(exc)
                    or not request.retryable
                ):
                    logger.error(
                        "event=%s operation=%s request_id=%s attempt=%s/%s elapsed_ms=%.3f timeout_ms=%.2f reason=%s",
                        QDRANT_ADAPTER_EVENT,
                        operation,
                        sanitize_for_log(request.operation_id or ""),
                        attempt,
                        max_attempts,
                        elapsed_ms,
                        timeout_ms,
                        sanitize_for_log(type(exc).__name__, max_length=120),
                    )
                    raise
                logger.warning(
                    "event=%s operation=%s request_id=%s attempt=%s/%s elapsed_ms=%.3f retry_after_ms=%.2f timeout_ms=%.2f reason=%s",
                    QDRANT_ADAPTER_EVENT,
                    operation,
                    sanitize_for_log(request.operation_id or ""),
                    attempt,
                    max_attempts,
                    elapsed_ms,
                    backoff * 1000,
                    timeout_ms,
                    type(exc).__name__,
                )
                time.sleep(backoff)
                backoff = min(backoff * 2, self._backoff_cap_seconds)
