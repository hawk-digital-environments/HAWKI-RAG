"""Temporal control endpoints used by Laravel."""

from __future__ import annotations

import logging
from collections.abc import Callable

from fastapi import APIRouter, HTTPException

from hawki_bridge.adapters.temporal_client import TemporalBridgeClient
from hawki_bridge.http.schemas import (
    CancelWorkflowRequest,
    DeleteScheduleRequest,
    StartIngestWorkflowRequest,
    UpsertIngestScheduleRequest,
)


def build_temporal_router(
    *,
    settings,
    logger: logging.Logger,
    client_factory: Callable = TemporalBridgeClient,
) -> APIRouter:
    router = APIRouter(prefix="/temporal", tags=["temporal"])

    def client() -> TemporalBridgeClient:
        return client_factory(settings)

    @router.post("/workflows/ingest")
    async def start(body: StartIngestWorkflowRequest) -> dict[str, str | None]:
        try:
            result = await client().start_ingest_workflow(
                workflow_id=body.workflow_id,
                workflow_input=body.workflow_input.model_dump(
                    mode="json", exclude_unset=True
                ),
            )
            return result.to_payload()
        except Exception as exc:
            logger.exception("temporal:start failed")
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    @router.post("/schedules/ingest")
    async def upsert(body: UpsertIngestScheduleRequest) -> dict[str, str | None]:
        try:
            result = await client().upsert_ingest_schedule(
                schedule_id=body.schedule_id,
                workflow_id=body.workflow_id,
                cadence=body.cadence,
                workflow_input=body.workflow_input.model_dump(
                    mode="json", exclude_unset=True
                ),
            )
            return result.to_payload()
        except Exception as exc:
            logger.exception("temporal:schedule_upsert failed")
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    @router.post("/schedules/delete")
    async def delete(body: DeleteScheduleRequest) -> dict[str, bool]:
        try:
            await client().delete_schedule(schedule_id=body.schedule_id)
            return {"ok": True}
        except Exception as exc:
            logger.exception("temporal:schedule_delete failed")
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    @router.post("/workflows/cancel")
    async def cancel(body: CancelWorkflowRequest) -> dict[str, bool]:
        try:
            await client().cancel_workflow(
                workflow_id=body.workflow_id,
                run_id=body.run_id,
            )
            return {"ok": True}
        except Exception as exc:
            logger.exception("temporal:cancel failed")
            raise HTTPException(status_code=502, detail=str(exc)) from exc

    return router


__all__ = ["build_temporal_router"]
