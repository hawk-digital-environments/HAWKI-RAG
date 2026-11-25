"""One managed multipart API and signed event/writer compatibility endpoints."""

import asyncio
import hashlib
import json
from pathlib import PurePosixPath
from urllib.parse import unquote
from typing import Annotated, Any

import sqlalchemy as sa
from fastapi import APIRouter, Depends, Request
from fastapi.responses import JSONResponse, StreamingResponse
from pydantic import BaseModel, Field, ValidationError
from starlette.datastructures import UploadFile
from starlette.concurrency import run_in_threadpool

from hawki_backend.auth.dependencies import require_scopes
from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization import AuthorizationUnavailableError
from hawki_backend.text_ingestion.api import _verify_signature
from hawki_backend.text_ingestion.writers import WriterEvent
from hawki_backend.workflow_control.schema import operations
from hawki_rag_contracts.pipeline.status import PipelineWorkerEvent, PipelineStageStatus
from hawki_rag_contracts.pipeline.temporal import MARK_SOURCE_READY_ACTIVITY

from .input import ManagedError, ManagedInput
from .service import ManagedService

router = APIRouter(tags=["managed-documents"])
internal_router = APIRouter(include_in_schema=False)
IngestPrincipal = Annotated[
    AuthenticatedPrincipal, Depends(require_scopes("rag:ingest"))
]
ReadPrincipal = Annotated[AuthenticatedPrincipal, Depends(require_scopes("rag:query"))]


class ReadFilters(BaseModel):
    dataset_id: str | None = Field(default=None, max_length=191)
    datasetId: str | None = Field(default=None, max_length=191)
    q: str | None = Field(default=None, max_length=255)
    search: str | None = Field(default=None, max_length=255)
    limit: int = Field(default=100, ge=1, le=250)


async def execute_read(
    request: Request, principal: AuthenticatedPrincipal, method: str, *args: Any
) -> JSONResponse:
    try:
        payload = await run_in_threadpool(
            getattr(service(request), method), principal, *args
        )
        return JSONResponse(content=payload)
    except ManagedError as error:
        return error_response(error)
    except AuthorizationUnavailableError:
        return error_response(
            ManagedError(503, "Managed document authorization is unavailable.")
        )


@router.get("/documents")
async def list_documents(
    request: Request,
    principal: ReadPrincipal,
    filters: Annotated[ReadFilters, Depends()],
) -> JSONResponse:
    return await execute_read(
        request, principal, "list_documents", filters.model_dump()
    )


@router.get("/documents/uploads/download", include_in_schema=False)
async def download(
    request: Request,
    principal: ReadPrincipal,
    document_id: str,
    source_url: str | None = None,
    content_hash: str | None = None,
):
    from urllib.parse import quote

    try:
        stream, filename = await run_in_threadpool(
            service(request).open_download,
            principal,
            document_id,
            source_url,
            content_hash,
        )
    except ManagedError as error:
        return error_response(error)
    except AuthorizationUnavailableError:
        return error_response(
            ManagedError(503, "Document authorization is unavailable.")
        )

    def chunks():
        with stream:
            while chunk := stream.read(64 * 1024):
                yield chunk

    return StreamingResponse(
        chunks(),
        media_type="application/octet-stream",
        headers={
            "Content-Disposition": "attachment; filename*=UTF-8''"
            + quote(filename, safe=""),
            "Cache-Control": "no-store",
            "X-Content-Type-Options": "nosniff",
        },
    )


@router.get("/documents/{document_id}")
async def read_document(
    document_id: str, request: Request, principal: ReadPrincipal
) -> JSONResponse:
    return await execute_read(request, principal, "read_document", document_id)


def service(request: Request) -> ManagedService:
    instance = getattr(request.app.state, "managed_document_service", None)
    if instance is None:
        raise ManagedError(503, "Managed document ingestion is unavailable.")
    return instance


def error_response(error: ManagedError) -> JSONResponse:
    return JSONResponse(
        status_code=error.status,
        content={"success": False, "message": error.message, **error.details},
    )


async def execute_mutation(instance: ManagedService, *args: Any) -> tuple[int, dict]:
    # SQLAlchemy, shared-file hashing and sink clients are synchronous. Keep them
    # off the ASGI event loop while reusing the durable async dispatcher.
    return await run_in_threadpool(lambda: asyncio.run(instance.mutate(*args)))


def original_filename(file: UploadFile) -> str:
    original = file.headers.get("X-Hawki-Original-Filename")
    name = unquote(original) if original is not None else (file.filename or "")
    return PurePosixPath(name.replace("\\", "/")).name


def mutation_failure(
    instance: ManagedService,
    principal: AuthenticatedPrincipal,
    purpose: str,
    document_id: str,
    error: ManagedError,
) -> dict:
    doc = instance.repository.document(principal.tenant_id, document_id)
    instance.authorize(principal, doc["dataset_id"])
    revision_id = doc["current_revision_id"] or doc["pending_revision_id"]
    revision = instance.repository.revision(revision_id) if revision_id else None
    return {
        "success": False,
        "operation": {"type": purpose, "status": "failed"},
        "message": error.message,
        "error": None,
        "document": instance.document_payload(doc, revision, doc["attributes"]),
    }


async def mutation(
    request: Request,
    principal: AuthenticatedPrincipal,
    purpose: str,
    document_id: str | None = None,
    batch: bool = False,
) -> JSONResponse:
    form = None
    try:
        instance = service(request)
        if purpose == "delete":
            status, payload = await execute_mutation(
                instance,
                principal,
                purpose,
                ManagedInput(),
                None,
                None,
                request.headers.get("Idempotency-Key"),
                document_id,
            )
            return JSONResponse(status_code=status, content=payload)
        form = await request.form(
            max_files=1000, max_fields=100, max_part_size=100 * 1024 * 1024
        )
        raw = {
            key: value
            for key, value in form.items()
            if not isinstance(value, UploadFile)
        }
        if raw.get("converter_mode") == "custom" and not raw.get("converter_url"):
            import os

            raw["converter_url"] = os.environ.get("EXTERNAL_CONVERTER_URL")
            raw["converter_token"] = os.environ.get("EXTERNAL_CONVERTER_TOKEN", "")
            raw["converter_start_path"] = os.environ.get(
                "EXTERNAL_CONVERTER_START_PATH", "/extract"
            )
        # The public request uses the canonical normalized upload form.
        body = ManagedInput.model_validate(raw)
        key = request.headers.get("Idempotency-Key")
        if not batch:
            file = form.get("file")
            if not isinstance(file, UploadFile):
                raise ManagedError(422, "The file field is required.")
            status, payload = await execute_mutation(
                instance,
                principal,
                purpose,
                body,
                original_filename(file),
                file.file,
                key,
                document_id,
                file.filename,
            )
            return JSONResponse(status_code=status, content=payload)
        files = form.getlist("files") or form.getlist("files[]")
        if not files or any(not isinstance(file, UploadFile) for file in files):
            raise ManagedError(422, "The files field is required.")
        # Authorize the whole dataset before any item can produce a side effect.
        if not body.dataset_id:
            raise ManagedError(422, "The dataset_id field is required.")
        instance.authorize(principal, body.dataset_id)
        items = []
        accepted = 0
        for index, file in enumerate(files):
            try:
                status, payload = await execute_mutation(
                    instance,
                    principal,
                    "create",
                    body,
                    original_filename(file),
                    file.file,
                    f"{key}:{index}" if key else None,
                    None,
                    file.filename,
                )
            except ManagedError as error:
                status, payload = (
                    error.status,
                    {"success": False, "message": error.message, **error.details},
                )
            success = payload.get("success") is True
            accepted += int(success)
            items.append(
                {
                    "index": index,
                    "file_name": original_filename(file),
                    "http_status": status,
                    "success": success,
                    "result": payload,
                }
            )
        failed = len(items) - accepted
        return JSONResponse(
            status_code=202 if not failed else 207,
            content={
                "success": not failed,
                "operation": {
                    "type": "batch_create",
                    "status": "accepted" if not failed else "partial_failure",
                },
                "summary": {
                    "total": len(items),
                    "accepted": accepted,
                    "failed": failed,
                },
                "items": items,
            },
        )
    except ManagedError as error:
        if document_id is not None and error.status in {409, 502}:
            try:
                payload = await run_in_threadpool(
                    mutation_failure, instance, principal, purpose, document_id, error
                )
                return JSONResponse(status_code=error.status, content=payload)
            except ManagedError as authorization_error:
                return error_response(authorization_error)
            except AuthorizationUnavailableError:
                return error_response(
                    ManagedError(503, "Managed document authorization is unavailable.")
                )
        return error_response(error)
    except (ValidationError, ValueError):
        return error_response(ManagedError(422, "The given data was invalid."))
    except AuthorizationUnavailableError:
        return error_response(
            ManagedError(503, "Managed document authorization is unavailable.")
        )
    finally:
        if form is not None:
            await form.close()


def multipart_document_schema(*, batch: bool = False, update: bool = False) -> dict:
    """Describe the existing manual form parser without changing admission."""
    schema = ManagedInput.model_json_schema()
    schema["properties"]["metadata_json"] = {
        "type": "string",
        "description": "JSON-encoded metadata object or array.",
    }
    schema["properties"]["converter_token"]["format"] = "password"
    file_schema = {"type": "string", "format": "binary"}
    field = "files" if batch else "file"
    schema["properties"][field] = (
        {"type": "array", "items": file_schema, "minItems": 1} if batch else file_schema
    )
    schema["required"] = [field] if update else ["dataset_id", field]
    return {
        "requestBody": {
            "required": True,
            "content": {"multipart/form-data": {"schema": schema}},
        },
        "parameters": [
            {
                "name": "Idempotency-Key",
                "in": "header",
                "required": False,
                "description": "Reuse the same key and input when retrying an upload.",
                "schema": {"type": "string", "maxLength": 255},
            }
        ],
    }


@router.post(
    "/documents",
    summary="Upload a file for ingestion",
    description="Upload to an active dataset with an exact ingest grant. The browser gateway supplies service authentication.",
    openapi_extra=multipart_document_schema(),
)
async def create(request: Request, principal: IngestPrincipal) -> JSONResponse:
    return await mutation(request, principal, "create")


@router.post(
    "/documents/batch",
    summary="Upload multiple files for ingestion",
    openapi_extra=multipart_document_schema(batch=True),
)
async def batch(request: Request, principal: IngestPrincipal) -> JSONResponse:
    return await mutation(request, principal, "create", batch=True)


@router.put(
    "/documents/{document_id}",
    summary="Replace a document with an uploaded file",
    openapi_extra=multipart_document_schema(update=True),
)
async def update(
    document_id: str, request: Request, principal: IngestPrincipal
) -> JSONResponse:
    return await mutation(request, principal, "update", document_id)


@router.delete("/documents/{document_id}")
async def delete(
    document_id: str, request: Request, principal: IngestPrincipal
) -> JSONResponse:
    return await mutation(request, principal, "delete", document_id)


async def signed_body(request: Request) -> bytes:
    secret = getattr(request.app.state, "managed_document_secret", "")
    if not secret:
        raise ManagedError(503, "Managed callbacks are unavailable.")
    body = bytearray()
    async for chunk in request.stream():
        body.extend(chunk)
        if len(body) > 8 * 1024 * 1024:
            raise ManagedError(413, "The worker request is too large.")
    raw = bytes(body)
    _verify_signature(request, raw, secret)
    return raw


@internal_router.post("/internal/managed-documents/events")
async def event(request: Request) -> JSONResponse:
    try:
        raw = await signed_body(request)
        value = PipelineWorkerEvent.model_validate_json(raw)
        state = "running"
        if (
            value.activity_id == MARK_SOURCE_READY_ACTIVITY
            and value.status is PipelineStageStatus.COMPLETED
        ):
            state = "ready"
        elif value.status in {PipelineStageStatus.FAILED, PipelineStageStatus.SKIPPED}:
            state = "failed"
        receipt = await run_in_threadpool(
            service(request).repository.complete,
            value,
            hashlib.sha256(raw).hexdigest(),
            state,
        )
        return JSONResponse(
            status_code=200 if receipt["duplicate"] else 202, content=receipt
        )
    except ManagedError as error:
        return error_response(error)
    except ValidationError:
        return error_response(ManagedError(422, "The worker event is invalid."))


@internal_router.post("/internal/managed-documents/writers")
async def writer(request: Request) -> JSONResponse:
    try:
        raw = await signed_body(request)
        value = WriterEvent.model_validate_json(raw)
        return JSONResponse(
            content=await run_in_threadpool(
                service(request).repository.record_writer,
                value,
                hashlib.sha256(raw).hexdigest(),
            )
        )
    except ManagedError as error:
        return error_response(error)
    except ValidationError:
        return error_response(ManagedError(422, "The worker event is invalid."))


@internal_router.post("/internal/managed-documents/ownership")
async def ownership(request: Request) -> JSONResponse:
    """Classify from a unique durable operation and its expected revision.

    Historical preparations without a managed revision remain outside current authority.
    """
    try:
        raw = await signed_body(request)
        workflow_id = json.loads(raw)["workflow_id"]
        instance = service(request)
        with instance.repository.engine.connect() as connection:
            rows = list(
                connection.execute(
                    sa.select(operations).where(
                        operations.c.workflow_id == workflow_id,
                        operations.c.action == "source",
                    )
                ).mappings()
            )
            if len(rows) != 1:
                raise ManagedError(409, "Workflow ownership is unknown or ambiguous.")
            wire = rows[0]["payload"]["workflow_input"]
            if "managed_document_id" not in wire or wire["managed_document_id"] is None:
                owner = "laravel"
            else:
                instance.repository.owner(connection, workflow_id)
                owner = "python"
        return JSONResponse(content={"owner": owner})
    except ManagedError as error:
        return error_response(error)
    except (KeyError, ValueError, TypeError):
        return error_response(ManagedError(422, "Invalid ownership request."))
