"""Error contracts for API adapters."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from requests import RequestException

from hawki_rag_resilience.reliability import API_REQUEST_ERROR_EVENT, log_redacted_value
from hawki_bridge.adapters.neo4j_reader import (
    DriverError,
    NEO4J_ERRORS,
    NEO4J_UNAVAILABLE_ERRORS,
    Neo4jError,
)


def build_error_payload(
    request: Request,
    *,
    status: int,
    error_type: str,
    message: str | None,
    error_code: str | None = None,
) -> dict[str, Any]:
    """Build a standardized request error payload."""

    error: dict[str, Any] = {
        "type": error_type,
        "status": status,
        "message": log_redacted_value(message or ""),
        "path": str(getattr(request.url, "path", "")),
        "request_id": str(getattr(request.state, "request_id", "")),
    }
    if error_code:
        error["code"] = error_code

    return {"error": error}


def build_http_error_payload(request: Request, exc: HTTPException) -> dict[str, Any]:
    """Preserve stable public contracts for structured domain HTTP errors."""

    detail = exc.detail
    if isinstance(detail, Mapping):
        error_code = detail.get("code")
        message = detail.get("message")
        if isinstance(error_code, str) and isinstance(message, str):
            return build_error_payload(
                request,
                status=exc.status_code,
                error_type=exc.__class__.__name__,
                message=message,
                error_code=error_code,
            )

    return build_error_payload(
        request,
        status=exc.status_code,
        error_type=exc.__class__.__name__,
        message=str(detail),
    )


def install_exception_handlers(app: FastAPI, logger) -> None:
    """Register request-level exception handlers for API boundaries."""

    def handle_http_error(request: Request, exc: HTTPException) -> JSONResponse:
        status_code = exc.status_code
        logger.warning(
            "event=%s type=http_exception path=%s status=%s detail=%s",
            API_REQUEST_ERROR_EVENT,
            getattr(request.url, "path", ""),
            status_code,
            log_redacted_value(exc.detail),
        )
        return JSONResponse(
            status_code=status_code,
            content=build_http_error_payload(request, exc),
        )

    def handle_value_error(request: Request, exc: ValueError) -> JSONResponse:
        status_code = 400
        logger.warning(
            "event=%s type=value_error path=%s status=%s detail=%s",
            API_REQUEST_ERROR_EVENT,
            getattr(request.url, "path", ""),
            status_code,
            log_redacted_value(exc),
        )
        return JSONResponse(
            status_code=status_code,
            content=build_error_payload(
                request,
                status=status_code,
                error_type=exc.__class__.__name__,
                message=str(exc),
            ),
        )

    def handle_request_error(request: Request, exc: RequestException) -> JSONResponse:
        status_code = 503
        logger.error(
            "event=%s type=request_error path=%s status=%s detail=%s",
            API_REQUEST_ERROR_EVENT,
            getattr(request.url, "path", ""),
            status_code,
            log_redacted_value(exc),
        )
        return JSONResponse(
            status_code=status_code,
            content=build_error_payload(
                request,
                status=status_code,
                error_type="ServiceUnavailable",
                message="Upstream request failed or timed out.",
            ),
        )

    def handle_neo4j_error(
        request: Request, exc: Neo4jError | DriverError
    ) -> JSONResponse:
        status_code = 503 if isinstance(exc, NEO4J_UNAVAILABLE_ERRORS) else 500
        logger.error(
            "event=%s type=neo4j_error exception=%s path=%s status=%s retryable=%s",
            API_REQUEST_ERROR_EVENT,
            type(exc).__name__,
            getattr(request.url, "path", ""),
            status_code,
            exc.is_retryable(),
        )
        return JSONResponse(
            status_code=status_code,
            content=build_error_payload(
                request,
                status=status_code,
                error_type="Neo4jUnavailable"
                if status_code == 503
                else "GraphStorageError",
                message="Graph storage is temporarily unavailable."
                if status_code == 503
                else "Graph storage rejected the operation.",
            ),
        )

    def handle_runtime_error(request: Request, exc: RuntimeError) -> JSONResponse:
        status_code = 502
        logger.error(
            "event=%s type=runtime_error path=%s status=%s detail=%s",
            API_REQUEST_ERROR_EVENT,
            getattr(request.url, "path", ""),
            status_code,
            log_redacted_value(exc),
        )
        return JSONResponse(
            status_code=status_code,
            content=build_error_payload(
                request,
                status=status_code,
                error_type=exc.__class__.__name__,
                message=str(exc),
            ),
        )

    def handle_unexpected(request: Request, exc: Exception) -> JSONResponse:
        status_code = 500
        logger.exception(
            "event=%s type=unhandled_exception path=%s status=%s",
            API_REQUEST_ERROR_EVENT,
            getattr(request.url, "path", ""),
            status_code,
        )
        return JSONResponse(
            status_code=status_code,
            content=build_error_payload(
                request,
                status=status_code,
                error_type="InternalServerError",
                message="Unhandled server error.",
            ),
        )

    app.add_exception_handler(HTTPException, handle_http_error)
    app.add_exception_handler(ValueError, handle_value_error)
    app.add_exception_handler(RequestException, handle_request_error)
    for neo4j_error_type in NEO4J_ERRORS:
        app.add_exception_handler(neo4j_error_type, handle_neo4j_error)
    app.add_exception_handler(RuntimeError, handle_runtime_error)
    app.add_exception_handler(Exception, handle_unexpected)
