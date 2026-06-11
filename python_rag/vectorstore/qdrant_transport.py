"""Low-level HTTP transport adapter for Qdrant."""
from __future__ import annotations

import logging
import time
from typing import Any, Dict, Optional

import requests
from requests import RequestException, Response

from vectorstore.qdrant_requests import QdrantRequest

logger = logging.getLogger(__name__)


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
        session: Optional[Any] = None,
    ) -> None:
        self.base_url = base_url
        self.api_key = api_key
        self.default_timeout = default_timeout
        self.max_attempts = max(1, int(max_attempts))
        self.log_latency = log_latency
        self._session = session or requests.Session()

    def _headers(self) -> Dict[str, str]:
        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["api-key"] = self.api_key
        return headers

    def send(self, request: QdrantRequest) -> Response:
        """Execute one HTTP request with retry and optional latency logging."""
        url = f"{self.base_url}{request.path}"
        payload_kwargs: Dict[str, Any] = {
            "headers": self._headers(),
            "timeout": self.default_timeout if request.timeout is None else request.timeout,
        }
        if request.json_body is not None:
            payload_kwargs["json"] = request.json_body

        backoff = 0.5
        attempt = 0
        while True:
            attempt += 1
            try:
                start = time.perf_counter()
                response = self._session.request(request.method, url, **payload_kwargs)
                elapsed = time.perf_counter() - start
                if self.log_latency:
                    logger.info(
                        "Qdrant %s %s succeeded in %.3fs",
                        request.method.upper(),
                        request.path,
                        elapsed,
                    )
                if response.status_code in {429, 500, 502, 503, 504}:
                    logger.warning(
                        "Qdrant %s %s failed with %s",
                        request.method.upper(),
                        request.path,
                        response.status_code,
                    )
                    if attempt >= self.max_attempts:
                        response.raise_for_status()
                    if request.timeout is not None and request.timeout > 0:
                        backoff = min(backoff * 2, 5.0)
                        time.sleep(backoff)
                    else:
                        time.sleep(backoff)
                    continue
                return response
            except RequestException as exc:
                if attempt >= self.max_attempts:
                    raise
                logger.warning("Qdrant request error (%s). Retrying attempt %s/%s", exc, attempt, self.max_attempts)
                time.sleep(backoff)
                backoff = min(backoff * 2, 5.0)
