"""Error contracts for API adapters."""
from __future__ import annotations

from typing import Any

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from requests import RequestException

from shared.reliability import API_REQUEST_ERROR_EVENT, log_redacted_value


def build_error_payload(request: Request, *, status: int, error_type: str, message: str | None) -> dict[str, Any]:
    """Build a standardized request error payload."""

    return {
        "error": {
            "type": error_type,
            "status": status,
            "message": log_redacted_value(message or ""),
            "path": str(getattr(request.url, "path", "")),
            "request_id": str(getattr(request.state, "request_id", "")),
        }
    }


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
            content=build_error_payload(
                request,
                status=status_code,
                error_type=exc.__class__.__name__,
                message=str(exc.detail),
            ),
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
    app.add_exception_handler(RuntimeError, handle_runtime_error)
    app.add_exception_handler(Exception, handle_unexpected)

