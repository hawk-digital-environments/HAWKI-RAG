"""Internal signed application operations, distinct from public JWT APIs."""

import hashlib
import hmac
import json
import time
from typing import Literal

from fastapi import APIRouter, HTTPException, Request
from pydantic import BaseModel, ConfigDict, Field, ValidationError
from temporalio.exceptions import WorkflowAlreadyStartedError
from hawki_workflow_control.temporal_client import TemporalUnavailable

from .identity import source_retry, source_workflow, validate_owned_workflow
from .repository import OperationConflict
from .service import DispatchUncertain, DispatchUnavailable

router = APIRouter(
    prefix="/internal/workflow-control",
    tags=["internal-workflow-control"],
    include_in_schema=False,
)

CONTROL_AUDIENCE = "hawki-workflow-control-v1"
CONTROL_PREFIX = "/internal/workflow-control/"


def signing_input(method: str, operation: str, stamp: str, body: bytes) -> bytes:
    """Fixed audience and routed operation; exact body bytes follow the header."""
    return (
        f"{CONTROL_AUDIENCE}\n{method}\n{CONTROL_PREFIX}{operation}\n{stamp}\n".encode()
        + body
    )


class Start(BaseModel):
    model_config = ConfigDict(extra="forbid")
    workflow_id: str = Field(min_length=1, max_length=255)
    workflow_input: dict
    managed_replacement: bool = False
    recovery_attempt: int = Field(default=0, ge=0)
    cadence: Literal["daily", "weekly", "monthly"] | None = None
    schedule_id: str | None = Field(default=None, max_length=255)


class Execution(BaseModel):
    model_config = ConfigDict(extra="forbid")
    workflow_id: str = Field(min_length=1, max_length=255)
    run_id: str | None = Field(default=None, min_length=1, max_length=255)


class ScheduleIdentity(BaseModel):
    model_config = ConfigDict(extra="forbid")
    schedule_id: str = Field(min_length=1, max_length=255)


class RetryIdentity(BaseModel):
    model_config = ConfigDict(extra="forbid")
    source_id: str = Field(min_length=1, max_length=191)
    previous_workflow_id: str | None = Field(default=None, max_length=255)
    attempt: int = Field(ge=1)
    job_id: str = Field(min_length=1, max_length=191)


async def authorized_body(request: Request, operation: str) -> dict:
    secret = request.app.state.workflow_control_secret
    if not secret or request.app.state.workflow_control is None:
        raise HTTPException(503, "Workflow control is not configured.")
    stamp = request.headers.get("X-Hawki-Timestamp", "")
    signature = request.headers.get("X-Hawki-Signature", "")
    if (
        not stamp.isascii()
        or not stamp.isdigit()
        or not 10 <= len(stamp) <= 12
        or abs(time.time() - int(stamp)) > 300
    ):
        raise HTTPException(401, "Invalid control-plane signature.")
    body = await request.body()
    expected = (
        "v1="
        + hmac.new(
            secret.encode(),
            signing_input(request.method, operation, stamp, body),
            hashlib.sha256,
        ).hexdigest()
    )
    if not signature.isascii() or not hmac.compare_digest(signature, expected):
        raise HTTPException(401, "Invalid control-plane signature.")
    if len(body) > 8 * 1024 * 1024:
        raise HTTPException(413, "Workflow preparation is too large.")
    try:
        value = json.loads(body)
        if not isinstance(value, dict):
            raise ValueError()
        return value
    except ValueError:
        raise HTTPException(422, "Invalid prepared operation.") from None


@router.post("/{operation:path}")
async def control(operation: str, request: Request) -> dict:
    body = await authorized_body(request, operation)
    service = request.app.state.workflow_control
    try:
        if operation == "source/retry-identity":
            value = RetryIdentity.model_validate(body)
            identity = source_retry(
                value.source_id, value.previous_workflow_id, value.attempt, value.job_id
            )
            if len(identity) > 255:
                raise ValueError()
            await service.authorize_source_retry(
                value.previous_workflow_id or source_workflow(value.source_id),
                value.job_id,
                identity,
            )
            return {"workflow_id": identity}
        if operation in {
            "source/start",
            "text/start",
            "deletion/start",
            "schedule/upsert",
        }:
            value = Start.model_validate(body)
            return await service.start(
                operation.split("/")[0],
                value.workflow_id,
                value.workflow_input,
                replacement=value.managed_replacement,
                recovery_attempt=value.recovery_attempt,
                cadence=value.cadence,
                schedule_id=value.schedule_id,
            )
        if operation == "schedule/delete":
            value = ScheduleIdentity.model_validate(body)
            if not value.schedule_id.startswith("schedule-ingest-source-"):
                raise ValueError()
            await service.port.delete_schedule(schedule_id=value.schedule_id)
            return {"ok": True}
        value = Execution.model_validate(body)
        validate_owned_workflow(value.workflow_id)
        if operation == "execution/status":
            if not value.run_id:
                raise ValueError()
            result = await service.port.workflow_status(
                workflow_id=value.workflow_id, run_id=value.run_id
            )
            if result.get("error"):
                result["error"] = "Workflow execution failed."
            return result
        if operation == "execution/cancel":
            await service.port.cancel_workflow(
                workflow_id=value.workflow_id, run_id=value.run_id
            )
            return {"ok": True, "state": "cancellation_requested"}
        if operation == "execution/stop-for-deletion":
            await service.port.cancel_workflow_and_wait(
                workflow_id=value.workflow_id, run_id=value.run_id
            )
            return {"ok": True}
        raise HTTPException(404, "Unknown workflow-control operation.")
    except OperationConflict:
        raise HTTPException(
            409, "Prepared operation changed; reconciliation is required."
        ) from None
    except WorkflowAlreadyStartedError:
        raise HTTPException(
            409, "Workflow already exists; reconcile its existing execution."
        ) from None
    except TemporalUnavailable:
        raise HTTPException(
            503, "Temporal inspection is unavailable; reconciliation is still required."
        ) from None
    except DispatchUnavailable:
        raise HTTPException(
            503,
            "Temporal control is unavailable before dispatch; retry the same preparation.",
        ) from None
    except DispatchUncertain:
        raise HTTPException(
            502, "Workflow dispatch is unconfirmed; reconcile the same operation."
        ) from None
    except (ValidationError, ValueError):
        raise HTTPException(422, "Invalid prepared operation.") from None
    except HTTPException:
        raise
    except Exception:
        if operation in {"execution/cancel", "execution/stop-for-deletion"}:
            raise HTTPException(
                502,
                "Workflow cancellation is unavailable; inspect the existing execution.",
            ) from None
        raise HTTPException(
            502, "Workflow control is unavailable; reconciliation may be required."
        ) from None
