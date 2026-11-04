"""Stable public error mapping for the direct-text ingestion boundary.

The bodies carry a code and a generic message only. They never carry a
fingerprint, a path, a collection, a namespace, a token or a database error.
The Laravel compatibility BFF maps these codes back onto its existing
browser contract.
"""

from __future__ import annotations

from fastapi import HTTPException

_REQUEST_TOO_LARGE = "The text ingestion request is too large."
_INVALID_REQUEST = "The given data was invalid."
_IDEMPOTENCY_CONFLICT = "The idempotency key was already used with a different request."
_SOURCE_BUSY = "The direct-text source is already being processed."
_DATASET_NOT_FOUND = "The requested dataset is not available."
_DATASET_NOT_READY = "The requested dataset is not ready for text ingestion."
_NOT_FOUND = "The requested direct-text ingestion is not available."
_WORKFLOW_UNCONFIRMED = (
    "The text ingestion workflow could not be confirmed. "
    "Retry with the same idempotency key."
)
_UNAVAILABLE = "Text ingestion is not available."
_DELETION_FAILED = (
    "Direct-text deletion could not be completed. Retry with the same idempotency key."
)
_AUTHORIZATION_UNAVAILABLE = "Authorization could not be completed."
_EVENT_TARGET_UNAVAILABLE = "The worker event target is not available."
_EVENT_COLLISION = "The worker event id was reused with a different payload."
_EVENT_INVALID = "The worker event is invalid."
_STORAGE_FAILED = "The direct-text artifact could not be stored."


def _error(status_code: int, error: str, message: str) -> HTTPException:
    return HTTPException(
        status_code=status_code,
        detail={"error": error, "message": message},
    )


def request_too_large() -> HTTPException:
    return _error(413, "text_ingestion_request_too_large", _REQUEST_TOO_LARGE)


def invalid_request() -> HTTPException:
    return _error(422, "text_ingestion_invalid_request", _INVALID_REQUEST)


def idempotency_conflict() -> HTTPException:
    return _error(
        409,
        "text_ingestion_idempotency_conflict",
        _IDEMPOTENCY_CONFLICT,
    )


def source_busy() -> HTTPException:
    return _error(409, "text_ingestion_source_busy", _SOURCE_BUSY)


def dataset_not_found() -> HTTPException:
    return _error(404, "dataset_not_found", _DATASET_NOT_FOUND)


def dataset_not_ready() -> HTTPException:
    return _error(409, "text_ingestion_dataset_not_ready", _DATASET_NOT_READY)


def not_found() -> HTTPException:
    return _error(404, "text_ingestion_not_found", _NOT_FOUND)


def workflow_unconfirmed() -> HTTPException:
    return _error(502, "text_ingestion_workflow_unconfirmed", _WORKFLOW_UNCONFIRMED)


def unavailable() -> HTTPException:
    return _error(503, "text_ingestion_unavailable", _UNAVAILABLE)


def deletion_failed() -> HTTPException:
    return _error(502, "text_ingestion_deletion_failed", _DELETION_FAILED)


def authorization_unavailable() -> HTTPException:
    return _error(503, "authorization_unavailable", _AUTHORIZATION_UNAVAILABLE)


def event_target_unavailable() -> HTTPException:
    return _error(
        409,
        "text_ingestion_event_target_unavailable",
        _EVENT_TARGET_UNAVAILABLE,
    )


def event_collision() -> HTTPException:
    return _error(409, "text_ingestion_event_id_collision", _EVENT_COLLISION)


def event_invalid() -> HTTPException:
    return _error(422, "text_ingestion_event_invalid", _EVENT_INVALID)


def storage_failed() -> HTTPException:
    return _error(500, "text_ingestion_storage_failed", _STORAGE_FAILED)


__all__ = [
    "authorization_unavailable",
    "dataset_not_found",
    "dataset_not_ready",
    "deletion_failed",
    "event_collision",
    "event_invalid",
    "event_target_unavailable",
    "idempotency_conflict",
    "invalid_request",
    "not_found",
    "request_too_large",
    "source_busy",
    "storage_failed",
    "unavailable",
    "workflow_unconfirmed",
]
