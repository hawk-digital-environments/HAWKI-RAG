"""Route unchanged signed worker messages to their durable Python owner.

Ownership comes from persisted preparations, never workflow prefixes or a
caller-supplied owner hint. Family handlers retain their own replay and identity
checks and receive the original request bytes without an HTTP relay.
"""

from fastapi import APIRouter, HTTPException, Request
from pydantic import ValidationError
from starlette.concurrency import run_in_threadpool

from hawki_backend.managed_documents import api as managed
from hawki_backend.text_ingestion import api as text
from hawki_backend.text_ingestion.writers import WriterEvent
from hawki_backend.url_ingestion import api as url
from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.ownership import classify
from hawki_backend.url_ingestion.repository import UrlRepository
from hawki_rag_contracts.pipeline.status import PipelineWorkerEvent
from hawki_backend.historical_archive import require_unreserved

router = APIRouter(prefix="/internal/pipeline", include_in_schema=False)


async def signed_body(request: Request) -> bytes:
    secret = request.app.state.workflow_control_secret
    if not secret:
        raise HTTPException(503, "callbacks_unavailable")
    body = bytearray()
    async for chunk in request.stream():
        body.extend(chunk)
        if len(body) > 8 * 1024 * 1024:
            raise HTTPException(413, "callback_too_large")
    raw = bytes(body)
    text._verify_signature(request, raw, secret)
    # Family adapters consume the exact same bytes, including whitespace.
    request._body = raw
    return raw


async def owner(request: Request, workflow_id: str) -> str:
    require_unreserved({"workflow_id": workflow_id})
    control = request.app.state.workflow_control
    if control is None:
        raise HTTPException(503, "callbacks_unavailable")
    try:
        value = await run_in_threadpool(
            classify,
            UrlRepository(control.repository.engine),
            {"workflow_id": workflow_id},
        )
    except UrlIngestionError:
        raise HTTPException(409, "callback_ownership_unconfirmed") from None
    if value not in {"text", "managed", "url"}:
        # Historical work requires a separate ownership handoff. Absence is not proof
        # of ownership, and must never create or adopt a Python operation.
        raise HTTPException(409, "callback_ownership_unconfirmed")
    return value


@router.post("/worker-events")
async def worker_event(request: Request):
    raw = await signed_body(request)
    try:
        event = PipelineWorkerEvent.model_validate_json(raw)
    except ValidationError:
        raise HTTPException(422, "invalid_callback") from None
    family = await owner(request, event.workflow_id)
    handler = {"text": text.worker_event, "managed": managed.event, "url": url.event}
    return await handler[family](request)


@router.post("/managed-writers")
async def writer_event(request: Request):
    raw = await signed_body(request)
    try:
        event = WriterEvent.model_validate_json(raw)
    except ValidationError:
        raise HTTPException(422, "invalid_callback") from None
    family = await owner(request, event.workflow_id)
    handler = {"text": text.writer_event, "managed": managed.writer, "url": url.writer}
    return await handler[family](request)


@router.post("/managed-deletion-events")
@router.post("/managed-deletion-writer-proof")
async def legacy_deletion(request: Request):
    await signed_body(request)
    # Python-owned deletion already runs through the family services with
    # frozen targets, quiescence and verified sink cleanup. These Temporal
    # receipts belong to the historical deletion ledger. Acknowledge
    # neither completion nor writer release without that durable authority.
    raise HTTPException(409, "legacy_deletion_requires_drain")
