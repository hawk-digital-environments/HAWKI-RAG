"""Bootstrap the model warmup workflow: daily schedule plus one startup run.

Called from the worker process entrypoint before the worker starts polling.
Any failure is logged and swallowed: warmup must never keep the ingestion
worker from starting.
"""

from __future__ import annotations

import logging
from datetime import datetime, timezone
from typing import Any

from temporalio.client import (
    Client,
    Schedule,
    ScheduleActionStartWorkflow,
    ScheduleOverlapPolicy,
    SchedulePolicy,
    ScheduleSpec,
)

from hawki_rag_contracts.pipeline.temporal import (
    MODEL_WARMUP_SCHEDULE_ID,
    MODEL_WARMUP_WORKFLOW,
)
from hawki_workflow_worker.settings import ModelWarmupSettings, WorkflowWorkerSettings

logger = logging.getLogger(__name__)


def build_warmup_workflow_input(warmup: ModelWarmupSettings) -> dict[str, Any]:
    """Build the deterministic workflow input from the warmup settings."""

    return {
        "provider": warmup.provider,
        "query": warmup.query,
        "models": [dict(model) for model in warmup.models],
    }


def build_warmup_schedule(
    settings: WorkflowWorkerSettings, warmup: ModelWarmupSettings
) -> Schedule:
    """Build the daily warmup schedule for the workflow task queue."""

    return Schedule(
        action=ScheduleActionStartWorkflow(
            MODEL_WARMUP_WORKFLOW,
            build_warmup_workflow_input(warmup),
            id=f"{MODEL_WARMUP_SCHEDULE_ID}-run",
            task_queue=settings.workflow_task_queue,
        ),
        spec=ScheduleSpec(
            cron_expressions=[warmup.schedule_cron],
            time_zone_name=warmup.schedule_timezone,
        ),
        policy=SchedulePolicy(overlap=ScheduleOverlapPolicy.SKIP),
    )


async def ensure_model_warmup_schedule(
    client: Client,
    *,
    settings: WorkflowWorkerSettings,
    warmup: ModelWarmupSettings,
) -> str:
    """Replace the daily warmup schedule so config changes apply on boot."""

    schedule = build_warmup_schedule(settings, warmup)
    handle = client.get_schedule_handle(MODEL_WARMUP_SCHEDULE_ID)
    try:
        await handle.delete()
        logger.info("model_warmup:schedule replaced id=%s", MODEL_WARMUP_SCHEDULE_ID)
    except Exception:
        logger.info(
            "model_warmup:schedule created id=%s", MODEL_WARMUP_SCHEDULE_ID
        )
    await client.create_schedule(MODEL_WARMUP_SCHEDULE_ID, schedule)
    return MODEL_WARMUP_SCHEDULE_ID


async def start_model_warmup_once(
    client: Client,
    *,
    settings: WorkflowWorkerSettings,
    warmup: ModelWarmupSettings,
) -> str:
    """Start one warmup run for this worker boot; the id stays unique."""

    workflow_id = (
        "model-warmup-startup-"
        + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S")
    )
    handle = await client.start_workflow(
        MODEL_WARMUP_WORKFLOW,
        build_warmup_workflow_input(warmup),
        id=workflow_id,
        task_queue=settings.workflow_task_queue,
    )
    logger.info("model_warmup:startup run started workflow_id=%s", handle.id)
    return handle.id


async def bootstrap_model_warmup(
    client: Client,
    *,
    settings: WorkflowWorkerSettings,
    warmup: ModelWarmupSettings,
) -> None:
    """Register the schedule and fire one startup run; never raise."""

    try:
        await ensure_model_warmup_schedule(client, settings=settings, warmup=warmup)
        await start_model_warmup_once(client, settings=settings, warmup=warmup)
    except Exception:
        logger.exception("model_warmup:bootstrap failed; continuing without warmup")


__all__ = [
    "bootstrap_model_warmup",
    "build_warmup_schedule",
    "build_warmup_workflow_input",
    "ensure_model_warmup_schedule",
    "start_model_warmup_once",
]
