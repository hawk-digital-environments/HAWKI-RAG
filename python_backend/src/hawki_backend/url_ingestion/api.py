"""Authenticated URL API and exact-body signed callback adapters."""

import asyncio
import hashlib
import json
from typing import Annotated

from fastapi import APIRouter, Depends, Request, Query
from fastapi.responses import JSONResponse
from pydantic import ValidationError
from starlette.concurrency import run_in_threadpool

from hawki_backend.auth.dependencies import require_scopes
from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization import AuthorizationUnavailableError
from hawki_backend.text_ingestion.api import _verify_signature
from hawki_backend.text_ingestion.writers import WriterEvent
from hawki_rag_contracts.pipeline.status import PipelineWorkerEvent, PipelineStageStatus
from hawki_rag_contracts.pipeline.temporal import MARK_SOURCE_READY_ACTIVITY

from .errors import UrlIngestionError
from .service import UrlIngestionService
from .validation import UrlStartInput

router = APIRouter(tags=["url-ingestion"])
internal_router = APIRouter(include_in_schema=False)
Principal = Annotated[AuthenticatedPrincipal, Depends(require_scopes("rag:ingest"))]


def service(request: Request) -> UrlIngestionService:
    instance = getattr(request.app.state, "url_ingestion_service", None)
    if instance is None:
        raise UrlIngestionError(503, "URL ingestion is unavailable.")
    return instance


def failure(error: UrlIngestionError) -> JSONResponse:
    return JSONResponse(
        status_code=error.status, content={"success": False, "message": error.message}
    )


async def execute(
    request: Request, principal: AuthenticatedPrincipal, method: str, *args: object
) -> JSONResponse:
    try:
        instance = service(request)
        status, body = await run_in_threadpool(
            lambda: asyncio.run(getattr(instance, method)(principal, *args))
        )
        return JSONResponse(status_code=status, content=body)
    except UrlIngestionError as error:
        return failure(error)
    except ValidationError:
        return failure(UrlIngestionError(422, "The given data was invalid."))
    except AuthorizationUnavailableError:
        return failure(UrlIngestionError(503, "URL authorization is unavailable."))


@router.post("/pipeline/tasks/start")
async def start(
    request: Request, principal: Principal, body: UrlStartInput
) -> JSONResponse:
    return await execute(
        request, principal, "start", body, request.headers.get("Idempotency-Key")
    )


async def read(request, principal, method, *args, **kwargs):
    from . import reads

    try:
        return JSONResponse(
            content=await run_in_threadpool(
                getattr(reads, method), service(request), principal, *args, **kwargs
            )
        )
    except UrlIngestionError as error:
        return failure(error)
    except AuthorizationUnavailableError:
        return failure(UrlIngestionError(503, "URL authorization is unavailable."))


@router.get("/pipeline/tasks", include_in_schema=False)
async def task_list(
    request: Request, principal: Principal, limit: int = Query(30, ge=1, le=250)
):
    return await read(request, principal, "tasks", limit=limit)


@router.get("/pipeline/recovery/failed-jobs", include_in_schema=False)
async def failed_jobs(
    request: Request, principal: Principal, limit: int = Query(100, ge=1, le=500)
):
    filters = dict(request.query_params) | {"limit": limit}
    filters["task_id"] = filters.get("task_id") or filters.get("taskId")
    filters["dataset_id"] = filters.get("dataset_id") or filters.get("datasetId")
    return await read(request, principal, "jobs", filters)


@router.get("/pipeline/recovery/candidates", include_in_schema=False)
async def recovery_candidates(request: Request, principal: Principal):
    # Mutation inventory is complete, never the first page of a display list.
    # The authenticated BFF partitions these IDs with its own legacy candidates.
    from . import reads

    try:
        filters = dict(request.query_params)
        filters["limit"] = None
        payload = await run_in_threadpool(
            reads.jobs, service(request), principal, filters
        )
        return JSONResponse(content=payload)
    except UrlIngestionError as error:
        return failure(error)
    except AuthorizationUnavailableError:
        return failure(UrlIngestionError(503, "URL authorization is unavailable."))


@router.get("/pipeline/status/{job_id}", include_in_schema=False)
async def job_status(job_id: str, request: Request, principal: Principal):
    return await read(request, principal, "status", job_id)


@router.get("/pipeline/tasks/{task_id}/stages/{stage}/logs", include_in_schema=False)
async def stage_logs(task_id: str, stage: str, request: Request, principal: Principal):
    return await read(request, principal, "logs", task_id, stage)


@router.post("/pipeline/legacy-access", include_in_schema=False)
async def legacy_access(request: Request, principal: Principal, body: dict):
    if principal.client_id != "hawki-bff":
        return failure(UrlIngestionError(403, "Forbidden."))
    from .ownership import classify
    from .reads import allowed

    try:
        candidates = body.get("candidates")
        if not isinstance(candidates, list) or len(candidates) > 250:
            raise UrlIngestionError(422, "Invalid compatibility candidates.")
        accepted = []
        for candidate in candidates:
            field = candidate["field"]
            if field not in {"task_id", "job_id"}:
                raise UrlIngestionError(422, "Invalid compatibility identity.")
            owner = await run_in_threadpool(
                classify, service(request).repository, {field: candidate["identity"]}
            )
            if owner in {"absent", "laravel"} and await run_in_threadpool(
                allowed, service(request), principal, candidate["dataset_id"]
            ):
                accepted.append(candidate["identity"])
        return JSONResponse(content={"success": True, "identities": accepted})
    except UrlIngestionError as error:
        return failure(error)
    except (KeyError, TypeError, ValueError):
        return failure(UrlIngestionError(422, "Invalid compatibility candidates."))
    except AuthorizationUnavailableError:
        return failure(UrlIngestionError(503, "URL authorization is unavailable."))


@router.get("/pipeline/tasks/{task_id}")
async def task(task_id: str, request: Request, principal: Principal) -> JSONResponse:
    return await read(request, principal, "task", task_id)


@router.delete("/pipeline/sources/{source_id}")
async def delete(
    source_id: str, request: Request, principal: Principal
) -> JSONResponse:
    return await execute(
        request, principal, "delete", source_id, request.headers.get("Idempotency-Key")
    )


@router.get("/pipeline/tasks/{task_id}/{view}", include_in_schema=False)
async def task_jobs(
    task_id: str, view: str, request: Request, principal: Principal
) -> JSONResponse:
    try:
        from . import reads

        payload = await run_in_threadpool(
            reads.task, service(request), principal, task_id
        )
        if view == "events":
            filters = dict(request.query_params)
            try:
                filters["limit"] = max(1, min(250, int(filters.get("limit", 50))))
            except ValueError:
                raise UrlIngestionError(422, "Invalid event limit.") from None
            filters["job_id"] = filters.get("job_id") or filters.get("jobId")
            filters["event_type"] = filters.get("event_type") or filters.get(
                "eventType"
            )
            return await read(request, principal, "timeline", task_id, filters)
        if view not in {"jobs", "failed-jobs"}:
            raise UrlIngestionError(409, "This URL task projection is not available.")
        jobs = payload["task"]["jobs"]
        if view == "failed-jobs":
            jobs = [job for job in jobs if job["status"] == "failed"]
        return JSONResponse(content={"success": True, "task_id": task_id, "jobs": jobs})
    except UrlIngestionError as error:
        return failure(error)
    except AuthorizationUnavailableError:
        return failure(UrlIngestionError(503, "URL authorization is unavailable."))


@router.post("/pipeline/sources/{source_id}/recrawl")
async def recrawl(
    source_id: str, request: Request, principal: Principal, body: dict
) -> JSONResponse:
    return await execute(
        request,
        principal,
        "recrawl",
        source_id,
        body,
        request.headers.get("Idempotency-Key"),
    )


@router.post("/pipeline/recovery/{path:path}")
async def recovery(
    path: str, request: Request, principal: Principal, body: dict | None = None
) -> JSONResponse:
    parts = path.split("/")
    if len(parts) == 3 and parts[0] == "jobs" and parts[2] == "retry":
        from .ownership import classify

        try:
            owner = await run_in_threadpool(
                classify, service(request).repository, {"job_id": parts[1]}
            )
            if owner == "managed":
                from .recovery import recover_managed
                from hawki_backend.managed_documents.input import ManagedError

                try:
                    status, payload = await run_in_threadpool(
                        lambda: asyncio.run(
                            recover_managed(
                                getattr(
                                    request.app.state, "managed_document_service", None
                                ),
                                principal,
                                parts[1],
                                request.headers.get("Idempotency-Key"),
                            )
                        )
                    )
                    return JSONResponse(status_code=status, content=payload)
                except ManagedError as error:
                    return failure(UrlIngestionError(error.status, error.message))
            if owner == "text":
                # Earlier epochs expose their own document/text mutation APIs.
                # Pipeline recovery cannot invent an unfenced retry protocol.
                return failure(
                    UrlIngestionError(
                        409,
                        "Use the owning document or text API to retry this operation.",
                    )
                )
        except UrlIngestionError as error:
            return failure(error)
    return await execute(
        request,
        principal,
        "recovery",
        path,
        body or {},
        request.headers.get("Idempotency-Key"),
    )


async def signed_body(request: Request) -> bytes:
    secret = getattr(request.app.state, "url_ingestion_secret", "")
    if not secret:
        raise UrlIngestionError(503, "URL callbacks are unavailable.")
    body = bytearray()
    async for chunk in request.stream():
        body.extend(chunk)
        if len(body) > 8 * 1024 * 1024:
            raise UrlIngestionError(413, "Worker event is too large.")
    raw = bytes(body)
    _verify_signature(request, raw, secret)
    return raw


@internal_router.post("/internal/url-ingestion/events")
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
    except UrlIngestionError as error:
        return failure(error)
    except ValidationError:
        return failure(UrlIngestionError(422, "Invalid worker event."))


@internal_router.post("/internal/url-ingestion/writers")
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
    except UrlIngestionError as error:
        return failure(error)
    except ValidationError:
        return failure(UrlIngestionError(422, "Invalid URL writer event."))


@internal_router.post("/internal/url-ingestion/ownership")
async def ownership(request: Request) -> JSONResponse:
    try:
        raw = await signed_body(request)
        body = json.loads(raw)
        instance = service(request)

        from .ownership import classify

        owner = await run_in_threadpool(classify, instance.repository, body)
        return JSONResponse(content={"owner": owner})
    except UrlIngestionError as error:
        return failure(error)
    except (ValueError, KeyError, TypeError):
        return failure(UrlIngestionError(422, "Invalid ownership request."))


@internal_router.get("/internal/url-ingestion/readiness")
async def readiness(request: Request) -> JSONResponse:
    instance = getattr(request.app.state, "url_ingestion_service", None)
    return JSONResponse(
        content={
            "enabled": instance is not None and instance.settings.enabled,
            "scraper_configured": instance is not None
            and bool(instance.settings.scraper_url),
            "workflow_control_configured": instance is not None
            and instance.control is not None,
            "scraper_healthy": None,
            "safe_fetch_supported": False,
            "schedule_generations_supported": True,
            "ready": False,
        }
    )
