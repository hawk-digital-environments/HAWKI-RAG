"""HTTP request middleware for observability and request correlation."""
from __future__ import annotations

import time
from typing import Any
from uuid import uuid4

from fastapi import FastAPI, Request

from common.reliability import (
    API_REQUEST_END_EVENT,
    API_REQUEST_ERROR_EVENT,
    API_REQUEST_START_EVENT,
    DEFAULT_REQUEST_BODY_SNIPPET_BYTES,
    pick_request_id,
    preview_request_body,
    preview_request_headers,
)

_MAX_BODY_SNIPPET = DEFAULT_REQUEST_BODY_SNIPPET_BYTES


def install_request_context_middleware(app: FastAPI, logger) -> None:
    """Register a correlation + request-body logging middleware."""

    @app.middleware("http")
    async def _request_context(request: Request, call_next) -> Any:
        request_id = pick_request_id(request.headers, fallback=str(uuid4()))
        request.state.request_id = request_id
        try:
            await request.body()
        except Exception:
            pass

        request_headers = preview_request_headers(request.headers)
        request_body = preview_request_body(
            getattr(request, "_body", None),
            content_type=request.headers.get("content-type"),
            max_length=_MAX_BODY_SNIPPET,
        )
        request_start = time.perf_counter()
        logger.info(
            "event=%s request_id=%s method=%s path=%s headers=%s body=%s",
            API_REQUEST_START_EVENT,
            request_id,
            request.method,
            request.url.path,
            request_headers,
            request_body,
        )
        try:
            response = await call_next(request)
        except Exception:
            elapsed_ms = (time.perf_counter() - request_start) * 1000
            logger.exception(
                "event=%s request_id=%s method=%s path=%s elapsed_ms=%.3f",
                API_REQUEST_ERROR_EVENT,
                request_id,
                request.method,
                request.url.path,
                elapsed_ms,
            )
            raise
        response.headers["X-Request-ID"] = request_id
        elapsed_ms = (time.perf_counter() - request_start) * 1000
        logger.info(
            "event=%s request_id=%s method=%s path=%s status=%s elapsed_ms=%.3f",
            API_REQUEST_END_EVENT,
            request_id,
            request.method,
            request.url.path,
            response.status_code,
            elapsed_ms,
        )
        return response
