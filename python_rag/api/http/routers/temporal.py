"""Temporal orchestration router owned by the Python bridge."""

from __future__ import annotations

import logging
from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from temporal_rag.client import TemporalBridgeClient
from temporal_rag.settings import TemporalRagSettings


class StartIngestWorkflowRequest(BaseModel):
    workflow_id: str
    workflow_input: dict[str, Any] = Field(default_factory=dict)


class UpsertIngestScheduleRequest(BaseModel):
    schedule_id: str
    workflow_id: str
    cadence: str
    workflow_input: dict[str, Any] = Field(default_factory=dict)


class DeleteScheduleRequest(BaseModel):
    schedule_id: str


class CancelWorkflowRequest(BaseModel):
    workflow_id: str
    run_id: str | None = None


def build_temporal_router(*, logger: logging.Logger) -> APIRouter:
    """Build routes that let Laravel delegate Temporal client work to Python."""

    router = APIRouter(prefix="/temporal", tags=["temporal"])

    def client() -> TemporalBridgeClient:
        return TemporalBridgeClient(TemporalRagSettings.from_env())

    @router.post("/workflows/ingest")
    async def start_ingest_workflow(body: StartIngestWorkflowRequest) -> dict[str, str | None]:
        try:
            execution = await client().start_ingest_workflow(
                workflow_id=body.workflow_id,
                workflow_input=body.workflow_input,
            )
            return execution.to_payload()
        except Exception as exc:
            logger.exception("temporal:start_ingest_workflow failed")
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    @router.post("/schedules/ingest")
    async def upsert_ingest_schedule(body: UpsertIngestScheduleRequest) -> dict[str, str | None]:
        try:
            execution = await client().upsert_ingest_schedule(
                schedule_id=body.schedule_id,
                workflow_id=body.workflow_id,
                cadence=body.cadence,
                workflow_input=body.workflow_input,
            )
            return execution.to_payload()
        except Exception as exc:
            logger.exception("temporal:upsert_ingest_schedule failed")
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    @router.post("/schedules/delete")
    async def delete_schedule(body: DeleteScheduleRequest) -> dict[str, bool]:
        try:
            await client().delete_schedule(schedule_id=body.schedule_id)
            return {"ok": True}
        except Exception as exc:
            logger.exception("temporal:delete_schedule failed")
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    @router.post("/workflows/cancel")
    async def cancel_workflow(body: CancelWorkflowRequest) -> dict[str, bool]:
        try:
            await client().cancel_workflow(workflow_id=body.workflow_id, run_id=body.run_id)
            return {"ok": True}
        except Exception as exc:
            logger.exception("temporal:cancel_workflow failed")
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    return router


__all__ = ["build_temporal_router"]
