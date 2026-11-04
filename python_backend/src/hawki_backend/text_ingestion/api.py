"""Authenticated direct-text endpoints and the signed worker-event sink.

The public routes authenticate a bearer principal and require the coarse
``rag:ingest`` scope. The service then performs the final dataset decision.
The internal route accepts the existing worker event body and HMAC headers,
so the indexer needs no new protocol.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import re
import time
from typing import Annotated

from fastapi import APIRouter, Depends, Request
from fastapi.responses import JSONResponse
from pydantic import ValidationError

from hawki_backend.auth.dependencies import require_scopes
from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationUnavailableError,
)
from hawki_rag_contracts.pipeline.status import (
    PipelineStageStatus,
    PipelineWorkerEvent,
    WorkerProducer,
)
from hawki_rag_contracts.pipeline.temporal import MARK_SOURCE_READY_ACTIVITY

from . import errors
from .artifacts import TextArtifactError
from .repository import EventCollision
from .service import (
    Busy,
    DatasetNotFound,
    DatasetNotReady,
    DeletionFailed,
    IdempotencyConflict,
    NotFound,
    TextIngestionService,
    WorkflowUnavailable,
    WorkflowUncertain,
)
from .settings import MAX_REQUEST_BYTES
from .validation import TextIngestionRequest, validate_idempotency_key
from .writers import WriterConflict, WriterEvent

router = APIRouter(tags=["text-ingestion"])
internal_router = APIRouter(tags=["internal-text-ingestion"], include_in_schema=False)

_MAX_EVENT_BYTES = 8 * 1024 * 1024
_MAX_EVENT_AGE_SECONDS = 300
_SOURCE_ID = re.compile(r"\Asource_[0-9a-f]{32}\Z")

_INGEST_PRINCIPAL = require_scopes(AuthorizationAction.INGEST.required_scope)


async def _raw_body(request: Request) -> bytes:
    """Read the exact request bytes and enforce the inclusive public limit."""
    body = bytearray()
    async for chunk in request.stream():
        body.extend(chunk)
        if len(body) > MAX_REQUEST_BYTES:
            raise errors.request_too_large()
    return bytes(body)


def _service(request: Request) -> TextIngestionService:
    service = getattr(request.app.state, "text_ingestion_service", None)
    if service is None:
        raise errors.unavailable()
    return service


def _parse_body(raw: bytes) -> TextIngestionRequest:
    try:
        payload = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError, RecursionError):
        raise errors.invalid_request() from None
    if not isinstance(payload, dict):
        raise errors.invalid_request()
    try:
        return TextIngestionRequest.model_validate(payload)
    except (ValidationError, RecursionError):
        raise errors.invalid_request() from None


def _idempotency_key(request: Request) -> str:
    try:
        return validate_idempotency_key(request.headers.get("Idempotency-Key"))
    except ValueError:
        raise errors.invalid_request() from None


def _map_ingest_error(error: Exception) -> None:
    if isinstance(error, IdempotencyConflict):
        raise errors.idempotency_conflict() from None
    if isinstance(error, Busy):
        raise errors.source_busy() from None
    if isinstance(error, DatasetNotFound):
        raise errors.dataset_not_found() from None
    if isinstance(error, DatasetNotReady):
        raise errors.dataset_not_ready() from None
    if isinstance(error, WorkflowUncertain):
        raise errors.workflow_unconfirmed() from None
    if isinstance(error, WorkflowUnavailable):
        raise errors.unavailable() from None
    if isinstance(error, AuthorizationUnavailableError):
        raise errors.authorization_unavailable() from None
    if isinstance(error, TextArtifactError):
        raise errors.storage_failed() from None
    raise error


@router.post("/text-ingestions")
async def ingest_text(
    raw: Annotated[bytes, Depends(_raw_body)],
    principal: Annotated[AuthenticatedPrincipal, Depends(_INGEST_PRINCIPAL)],
    request: Request,
) -> JSONResponse:
    """Validate, authorize and dispatch one direct-text operation."""
    service = _service(request)
    body = _parse_body(raw)
    key = _idempotency_key(request)
    try:
        outcome = await service.ingest(principal, body, key)
    except Exception as error:  # noqa: BLE001 - mapped to the stable contract
        _map_ingest_error(error)
        raise
    return JSONResponse(
        status_code=200 if outcome.replayed else 202,
        content=outcome.to_payload(),
    )


@router.delete("/text-ingestions/{source_id}")
async def delete_text(
    source_id: str,
    principal: Annotated[AuthenticatedPrincipal, Depends(_INGEST_PRINCIPAL)],
    request: Request,
) -> JSONResponse:
    """Delete one owned direct-text source through the compatibility contract."""
    if _SOURCE_ID.fullmatch(source_id) is None:
        raise errors.not_found()
    service = _service(request)
    key = _idempotency_key(request)
    try:
        outcome = await service.delete(principal, source_id, key)
    except Exception as error:  # noqa: BLE001 - mapped to the stable contract
        if isinstance(error, NotFound):
            raise errors.not_found() from None
        if isinstance(error, DeletionFailed):
            raise errors.deletion_failed() from None
        _map_ingest_error(error)
        raise
    return JSONResponse(status_code=200, content=outcome.to_payload())


@internal_router.post("/internal/text-ingestion/events")
async def worker_event(request: Request) -> JSONResponse:
    """Accept the existing signed worker event for one Python-owned text run."""
    service = getattr(request.app.state, "text_ingestion_service", None)
    secret = getattr(request.app.state, "text_ingestion_secret", "")
    if service is None or not secret:
        raise errors.unavailable()

    body = await request.body()
    if len(body) > _MAX_EVENT_BYTES:
        raise errors.request_too_large()
    _verify_signature(request, body, secret)

    try:
        event = PipelineWorkerEvent.model_validate(json.loads(body))
    except (UnicodeDecodeError, json.JSONDecodeError, ValidationError):
        raise errors.event_invalid() from None
    if (
        not event.workflow_id.startswith("ingest-text-")
        or event.producer is not WorkerProducer.INDEXER
    ):
        raise errors.event_invalid()

    status = "running"
    if (
        event.activity_id == MARK_SOURCE_READY_ACTIVITY
        and event.status is PipelineStageStatus.COMPLETED
    ):
        status = "ready"
    elif event.status in {
        PipelineStageStatus.FAILED,
        PipelineStageStatus.SKIPPED,
    }:
        status = "failed"

    try:
        outcome = await service.complete(
            event_id=event.event_id,
            payload_hash=hashlib.sha256(body).hexdigest(),
            workflow_id=event.workflow_id,
            source_id=event.source_id,
            status=status,
            job_id=event.job_id,
            task_id=event.task_id,
            run_id=event.run_id,
        )
    except NotFound:
        raise errors.event_target_unavailable() from None
    except EventCollision:
        raise errors.event_collision() from None
    return JSONResponse(
        status_code=200 if outcome.duplicate else 202,
        content=outcome.to_payload(),
    )


def _verify_signature(request: Request, body: bytes, secret: str) -> None:
    stamp = request.headers.get("X-Hawki-Timestamp", "")
    signature = request.headers.get("X-Hawki-Signature", "")
    if (
        not stamp.isascii()
        or not stamp.isdigit()
        or not 10 <= len(stamp) <= 12
        or abs(time.time() - int(stamp)) > _MAX_EVENT_AGE_SECONDS
    ):
        raise errors.event_invalid()
    match = re.fullmatch(r"v1=([a-fA-F0-9]{64})", signature)
    if match is None:
        raise errors.event_invalid()
    expected = hmac.new(
        secret.encode("utf-8"),
        stamp.encode("ascii") + b"." + body,
        hashlib.sha256,
    ).hexdigest()
    if not hmac.compare_digest(expected, match.group(1).lower()):
        raise errors.event_invalid()


@internal_router.post("/internal/text-ingestion/writers")
async def writer_event(request: Request) -> JSONResponse:
    """Receive the unchanged, exact-body signed managed-writer protocol."""
    service = _service(request)
    secret = getattr(request.app.state, "text_ingestion_secret", "")
    if not secret:
        raise errors.unavailable()
    body = await request.body()
    if len(body) > _MAX_EVENT_BYTES:
        raise errors.request_too_large()
    _verify_signature(request, body, secret)
    try:
        event = WriterEvent.model_validate_json(body)
    except ValidationError:
        raise errors.event_invalid() from None
    try:
        receipt = service.record_writer(event)
    except WriterConflict:
        raise errors.source_busy() from None
    return JSONResponse(status_code=200, content=receipt)


__all__ = ["internal_router", "router"]
