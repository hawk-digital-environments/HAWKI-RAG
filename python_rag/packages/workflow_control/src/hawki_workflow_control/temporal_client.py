"""Temporal SDK adapter for the Python workflow-control boundary."""

from __future__ import annotations

import asyncio
from hawki_rag_contracts.pipeline.deletion import DELETE_MANAGED_DOCUMENT_WORKFLOW
from temporalio.exceptions import WorkflowAlreadyStartedError

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
    WorkflowHistoryEventFilterType,
    WorkflowExecutionStatus,
    WorkflowFailureError,
)
from temporalio.common import (
    WorkflowIDConflictPolicy,
    WorkflowIDReusePolicy,
)
from temporalio.api.failure.v1 import Failure
from temporalio.service import RPCError, RPCStatusCode

from hawki_workflow_control.settings import WorkflowControlSettings
from hawki_rag_contracts.pipeline.temporal import INGEST_TEXT_WORKFLOW


def _failure_chain_message(failure: Failure) -> str:
    """Join a failure with its cause chain, truncated for error reporting."""

    messages: list[str] = []
    while failure:
        if failure.message:
            messages.append(failure.message)
        if not failure.HasField("cause"):
            break
        failure = failure.cause
    return ": ".join(messages)[:2048]


logger = logging.getLogger(__name__)


class TemporalUnavailable(Exception):
    """Connection failed before a workflow-control RPC could be submitted."""


class WorkflowOwnershipConflict(Exception):
    """USE_EXISTING returned a different prepared operation or legacy execution."""


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


class TemporalControlClient:
    """Async Temporal operations exposed to the authorized control plane."""

    def __init__(self, settings: WorkflowControlSettings) -> None:
        self.settings = settings

    async def workflow_status(
        self, *, workflow_id: str, run_id: str
    ) -> dict[str, str | None]:
        """Read a specific execution; never wait for a running workflow's result."""
        client = await self.connect_temporal()
        handle = client.get_workflow_handle(workflow_id, run_id=run_id)
        description = await handle.describe(rpc_timeout=timedelta(seconds=5))
        status = description.status.name if description.status else "UNKNOWN"
        error = None
        if status == "FAILED":
            history = await handle.fetch_history(
                event_filter_type=WorkflowHistoryEventFilterType.CLOSE_EVENT,
                rpc_timeout=timedelta(seconds=5),
            )
            for event in history.events:
                if event.HasField("workflow_execution_failed_event_attributes"):
                    failure = event.workflow_execution_failed_event_attributes.failure
                    error = _failure_chain_message(failure)
        return {
            "workflow_id": workflow_id,
            "run_id": run_id,
            "status": status,
            "error": error,
        }

    async def start_ingest_workflow(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
        reuse_completed: bool = False,
        control_operation: str | None = None,
    ) -> TemporalExecution:
        """Start or reuse an ingest workflow and return its execution identity.

        Temporal allows workflow-ID reuse and returns an existing execution on
        an active-ID conflict. Configured workflow timeouts remain attached.
        """
        client = await self.connect_temporal()
        try:
            handle = await client.start_workflow(
                self.settings.workflow_type,
                workflow_input,
                id=workflow_id,
                task_queue=self.settings.workflow_task_queue,
                id_reuse_policy=(
                    WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY
                    if reuse_completed
                    else WorkflowIDReusePolicy.ALLOW_DUPLICATE
                ),
                id_conflict_policy=WorkflowIDConflictPolicy.USE_EXISTING,
                execution_timeout=self.settings.workflow_execution_timeout,
                run_timeout=self.settings.workflow_run_timeout,
                task_timeout=self.settings.workflow_task_timeout,
                **(
                    {"memo": {"hawki_control_operation": control_operation}}
                    if control_operation
                    else {}
                ),
            )
        except WorkflowAlreadyStartedError:
            if not reuse_completed:
                raise
            handle = client.get_workflow_handle(workflow_id)
        run_id = self.resolve_run_id(handle)
        if (reuse_completed or control_operation) and not run_id:
            run_id = (await handle.describe(rpc_timeout=timedelta(seconds=5))).run_id
        if control_operation:
            await self.verify_start_owner(handle, control_operation)
        logger.info(
            "temporal_bridge:start workflow_id=%s run_id=%s", workflow_id, run_id
        )
        return TemporalExecution(workflow_id=workflow_id, run_id=run_id)

    async def start_managed_deletion(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
        control_operation: str | None = None,
    ) -> dict[str, Any]:
        """Reuse active/completed deletion; only failed runs may restart under the same ID."""
        client = await self.connect_temporal()
        try:
            handle = await client.start_workflow(
                DELETE_MANAGED_DOCUMENT_WORKFLOW,
                workflow_input,
                id=workflow_id,
                task_queue=self.settings.workflow_task_queue,
                id_reuse_policy=WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY,
                id_conflict_policy=WorkflowIDConflictPolicy.USE_EXISTING,
                execution_timeout=self.settings.workflow_execution_timeout,
                run_timeout=self.settings.workflow_run_timeout,
                task_timeout=self.settings.workflow_task_timeout,
                **(
                    {"memo": {"hawki_control_operation": control_operation}}
                    if control_operation
                    else {}
                ),
            )
        except WorkflowAlreadyStartedError:
            handle = client.get_workflow_handle(workflow_id)
        description = await handle.describe(rpc_timeout=timedelta(seconds=5))
        receipt = {
            "workflow_id": workflow_id,
            "run_id": description.run_id,
            "status": "pending",
        }
        try:
            result = await asyncio.wait_for(handle.result(), timeout=3)
        except TimeoutError:
            return receipt
        except WorkflowFailureError:
            return {**receipt, "status": "failed"}
        return {**receipt, "status": "completed", "result": result}

    async def start_text_ingest_workflow(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
        control_operation: str | None = None,
    ) -> TemporalExecution:
        """Reuse an active run or restart only a failed direct indexing run."""

        client = await self.connect_temporal()
        handle = await client.start_workflow(
            INGEST_TEXT_WORKFLOW,
            workflow_input,
            id=workflow_id,
            task_queue=self.settings.workflow_task_queue,
            id_reuse_policy=WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY,
            id_conflict_policy=WorkflowIDConflictPolicy.USE_EXISTING,
            execution_timeout=self.settings.workflow_execution_timeout,
            run_timeout=self.settings.workflow_run_timeout,
            task_timeout=self.settings.workflow_task_timeout,
            **(
                {"memo": {"hawki_control_operation": control_operation}}
                if control_operation
                else {}
            ),
        )
        run_id = self.resolve_run_id(handle)
        if control_operation and not run_id:
            run_id = (await handle.describe(rpc_timeout=timedelta(seconds=5))).run_id
        if control_operation:
            await self.verify_start_owner(handle, control_operation)
        logger.info(
            "temporal_bridge:start_text workflow_id=%s run_id=%s",
            workflow_id,
            run_id,
        )
        return TemporalExecution(workflow_id=workflow_id, run_id=run_id)

    async def upsert_ingest_schedule(
        self,
        *,
        schedule_id: str,
        workflow_id: str,
        cadence: str,
        workflow_input: dict[str, Any],
        control_operation: str | None = None,
    ) -> TemporalExecution:
        """Replace an ingest schedule and return its stable schedule identity.

        Deletion failures are ignored before creating the replacement schedule;
        the creation error, if any, still propagates.
        """
        client = await self.connect_temporal()
        schedule = self.build_ingest_schedule(
            workflow_id, cadence, workflow_input, control_operation=control_operation
        )
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
        """An absent schedule is idempotent; an unavailable delete is unconfirmed."""
        client = await self.connect_temporal()
        handle = client.get_schedule_handle(schedule_id)
        try:
            await handle.delete()
        except RPCError as error:
            if error.status != RPCStatusCode.NOT_FOUND:
                raise

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

    async def cancel_workflow_and_wait(
        self, *, workflow_id: str, run_id: str | None = None
    ) -> None:
        """Wait until an active workflow is closed before destructive cleanup."""

        client = await self.connect_temporal()
        handle = client.get_workflow_handle(workflow_id, run_id=run_id)
        try:
            description = await handle.describe()
        except RPCError as exc:
            if exc.status != RPCStatusCode.NOT_FOUND:
                raise
            logger.info(
                "temporal_bridge:cancel_wait already_absent workflow_id=%s run_id=%s",
                workflow_id,
                run_id,
            )
            return

        if description.status != WorkflowExecutionStatus.RUNNING:
            return

        try:
            await handle.cancel()
        except RPCError as exc:
            if exc.status not in {
                RPCStatusCode.NOT_FOUND,
                RPCStatusCode.FAILED_PRECONDITION,
            }:
                raise
            return

        try:
            await handle.result()
        except WorkflowFailureError:
            # Cancellation and failed executions are both safely closed.
            pass

        logger.info(
            "temporal_bridge:cancel_wait closed workflow_id=%s run_id=%s",
            workflow_id,
            run_id,
        )

    async def reconcile_start(
        self, *, workflow_id: str, operation_id: str
    ) -> dict[str, Any] | None:
        """Adopt only a start bearing this ledger operation's memo.

        Missing or unrelated history is never permission to blindly redispatch
        an uncertain ALLOW_DUPLICATE start. Old histories remain readable but
        require explicit reconciliation when provenance cannot be proved.
        """
        client = await self.connect_temporal()
        handle = client.get_workflow_handle(workflow_id)
        try:
            description = await handle.describe(rpc_timeout=timedelta(seconds=5))
        except RPCError as exc:
            if exc.status == RPCStatusCode.NOT_FOUND:
                return None
            raise
        memo = await description.memo()
        if memo.get("hawki_control_operation") != operation_id:
            return None
        return {
            "workflow_id": workflow_id,
            "run_id": description.run_id,
            "schedule_id": None,
        }

    @staticmethod
    async def verify_start_owner(handle: WorkflowHandle, operation_id: str) -> None:
        # USE_EXISTING preserves the Temporal policy but cannot grant a different
        # application task ownership of an already-running source workflow.
        description = await handle.describe(rpc_timeout=timedelta(seconds=5))
        if (await description.memo()).get("hawki_control_operation") != operation_id:
            raise WorkflowOwnershipConflict()

    async def current_execution(self, *, workflow_id: str) -> dict[str, str] | None:
        """Inspect a predecessor before authorizing a new source retry identity."""
        client = await self.connect_temporal()
        try:
            description = await client.get_workflow_handle(workflow_id).describe(
                rpc_timeout=timedelta(seconds=5)
            )
        except RPCError as exc:
            if exc.status == RPCStatusCode.NOT_FOUND:
                return None
            raise
        return {
            "run_id": description.run_id,
            "status": description.status.name if description.status else "UNKNOWN",
        }

    async def connect_temporal(self) -> Client:
        """Open a Temporal SDK connection for the configured namespace."""
        try:
            return await Client.connect(
                self.settings.temporal_address,
                namespace=self.settings.temporal_namespace,
            )
        except Exception:
            raise TemporalUnavailable() from None

    def build_ingest_schedule(
        self,
        workflow_id: str,
        cadence: str,
        workflow_input: dict[str, Any],
        *,
        control_operation: str | None = None,
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
                **(
                    {"memo": {"hawki_control_operation": control_operation}}
                    if control_operation
                    else {}
                ),
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


__all__ = ["TemporalControlClient", "TemporalExecution"]
