"""Temporal client operations exposed through the Python bridge API."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import timedelta
import logging
from typing import Any

from temporalio.client import (
    Client,
    Schedule,
    ScheduleActionStartWorkflow,
    ScheduleOverlapPolicy,
    SchedulePolicy,
    ScheduleSpec,
    WorkflowHandle,
)
from temporalio.common import (
    WorkflowIDConflictPolicy,
    WorkflowIDReusePolicy,
)
from temporalio.service import RPCError, RPCStatusCode

from hawki_bridge.settings import BridgeSettings

logger = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True)
class TemporalExecution:
    workflow_id: str
    run_id: str | None = None
    schedule_id: str | None = None

    def to_payload(self) -> dict[str, str | None]:
        return {
            "workflow_id": self.workflow_id,
            "run_id": self.run_id,
            "schedule_id": self.schedule_id,
        }


class TemporalBridgeClient:
    """Async Temporal operations exposed to the authorized control plane."""

    def __init__(self, settings: BridgeSettings) -> None:
        self.settings = settings

    async def start_ingest_workflow(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
    ) -> TemporalExecution:
        """Start or reuse an ingest workflow and return its execution identity.

        Temporal allows workflow-ID reuse and returns an existing execution on
        an active-ID conflict. Configured workflow timeouts remain attached.
        """
        client = await self.connect_temporal()
        handle = await client.start_workflow(
            self.settings.workflow_type,
            workflow_input,
            id=workflow_id,
            task_queue=self.settings.workflow_task_queue,
            id_reuse_policy=WorkflowIDReusePolicy.ALLOW_DUPLICATE,
            id_conflict_policy=WorkflowIDConflictPolicy.USE_EXISTING,
            execution_timeout=self.settings.workflow_execution_timeout,
            run_timeout=self.settings.workflow_run_timeout,
            task_timeout=self.settings.workflow_task_timeout,
        )

        run_id = self.resolve_run_id(handle)
        logger.info(
            "temporal_bridge:start workflow_id=%s run_id=%s", workflow_id, run_id
        )
        return TemporalExecution(workflow_id=workflow_id, run_id=run_id)

    async def upsert_ingest_schedule(
        self,
        *,
        schedule_id: str,
        workflow_id: str,
        cadence: str,
        workflow_input: dict[str, Any],
    ) -> TemporalExecution:
        """Replace an ingest schedule and return its stable schedule identity.

        Deletion failures are ignored before creating the replacement schedule;
        the creation error, if any, still propagates.
        """
        client = await self.connect_temporal()
        schedule = self.build_ingest_schedule(workflow_id, cadence, workflow_input)
        handle = client.get_schedule_handle(schedule_id)

        try:
            await handle.delete()
        except Exception:
            pass

        await client.create_schedule(schedule_id, schedule)
        logger.info(
            "temporal_bridge:schedule_upsert workflow_id=%s schedule_id=%s cadence=%s",
            workflow_id,
            schedule_id,
            cadence,
        )
        return TemporalExecution(workflow_id=workflow_id, schedule_id=schedule_id)

    async def delete_schedule(self, *, schedule_id: str) -> None:
        """Delete a schedule while logging and suppressing deletion failures."""
        client = await self.connect_temporal()
        handle = client.get_schedule_handle(schedule_id)
        try:
            await handle.delete()
        except Exception:
            logger.warning(
                "temporal_bridge:schedule_delete_ignored schedule_id=%s", schedule_id
            )

    async def cancel_workflow(
        self, *, workflow_id: str, run_id: str | None = None
    ) -> None:
        """Cancel a workflow, treating an already-absent execution as success."""
        client = await self.connect_temporal()
        handle = client.get_workflow_handle(workflow_id, run_id=run_id)
        try:
            await handle.cancel()
        except RPCError as exc:
            if exc.status != RPCStatusCode.NOT_FOUND:
                raise

            logger.info(
                "temporal_bridge:cancel already_absent workflow_id=%s run_id=%s",
                workflow_id,
                run_id,
            )
            return

        logger.info(
            "temporal_bridge:cancel workflow_id=%s run_id=%s", workflow_id, run_id
        )

    async def connect_temporal(self) -> Client:
        """Open a Temporal SDK connection for the configured namespace."""
        return await Client.connect(
            self.settings.temporal_address,
            namespace=self.settings.temporal_namespace,
        )

    def build_ingest_schedule(
        self, workflow_id: str, cadence: str, workflow_input: dict[str, Any]
    ) -> Schedule:
        """Build a UTC cron schedule with skip-overlap and one-hour catch-up.

        The scheduled workflow retains the configured task queue plus execution,
        run, and task timeouts.
        """
        cron = self.settings.cron_for_cadence(cadence)
        return Schedule(
            action=ScheduleActionStartWorkflow(
                self.settings.workflow_type,
                workflow_input,
                id=workflow_id,
                task_queue=self.settings.workflow_task_queue,
                execution_timeout=self.settings.workflow_execution_timeout,
                run_timeout=self.settings.workflow_run_timeout,
                task_timeout=self.settings.workflow_task_timeout,
            ),
            spec=ScheduleSpec(cron_expressions=[cron], time_zone_name="UTC"),
            policy=SchedulePolicy(
                overlap=ScheduleOverlapPolicy.SKIP,
                catchup_window=timedelta(hours=1),
            ),
        )

    @staticmethod
    def resolve_run_id(handle: WorkflowHandle[Any, Any]) -> str | None:
        """Resolve a run ID across supported Temporal handle attributes."""
        for attribute in ("first_execution_run_id", "result_run_id", "run_id"):
            value = getattr(handle, attribute, None)
            if isinstance(value, str) and value.strip():
                return value
        return None


__all__ = ["TemporalBridgeClient", "TemporalExecution"]
