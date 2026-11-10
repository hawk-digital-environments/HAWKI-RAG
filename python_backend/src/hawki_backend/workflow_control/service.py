"""Authoritative application decisions and durable dispatch, outside SQL locks."""

import logging
import os
from typing import Any, Protocol
from temporalio.exceptions import WorkflowAlreadyStartedError
from hawki_workflow_control.temporal_client import (
    TemporalExecution,
    TemporalUnavailable,
    WorkflowOwnershipConflict,
)

from hawki_rag_contracts.pipeline.deletion import DeleteManagedDocumentInput
from hawki_rag_contracts.pipeline.ingestion import (
    IngestSourceWorkflowInput,
    IngestTextWorkflowInput,
)

from .identity import source_schedule, text_workflow, validate_source_workflow
from .repository import OperationConflict, OperationRepository

logger = logging.getLogger(__name__)


class WorkflowPort(Protocol):
    async def start_ingest_workflow(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
        reuse_completed: bool,
        control_operation: str,
    ) -> TemporalExecution: ...
    async def start_text_ingest_workflow(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
        control_operation: str,
    ) -> TemporalExecution: ...
    async def start_managed_deletion(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
        control_operation: str,
    ) -> dict[str, Any]: ...
    async def upsert_ingest_schedule(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
        control_operation: str,
        cadence: str,
        schedule_id: str,
    ) -> TemporalExecution: ...
    async def reconcile_start(
        self,
        *,
        workflow_id: str,
        operation_id: str,
        workflow_input: dict[str, Any] | None = None,
        run_id: str | None = None,
    ) -> dict[str, Any] | None: ...
    async def workflow_status(
        self, *, workflow_id: str, run_id: str, include_result: bool = False
    ) -> dict[str, Any]: ...
    async def acknowledge_url_terminal(
        self,
        *,
        workflow_id: str,
        run_id: str,
        operation_id: str,
        workflow_input: dict[str, Any],
    ) -> None: ...
    async def cancel_workflow(
        self, *, workflow_id: str, run_id: str | None
    ) -> None: ...
    async def cancel_workflow_and_wait(
        self, *, workflow_id: str, run_id: str | None
    ) -> None: ...
    async def delete_schedule(self, *, schedule_id: str) -> None: ...
    async def current_execution(self, *, workflow_id: str) -> dict[str, str] | None: ...


class DispatchUncertain(Exception):
    """Retry the same prepared operation; never blindly create another execution."""


class DispatchUnavailable(Exception):
    """No dispatch RPC was submitted; the prepared operation is safe to retry."""


class WorkflowControl:
    def __init__(self, repository: OperationRepository, port: WorkflowPort) -> None:
        self.repository = repository
        self.port = port

    async def authorize_source_retry(
        self, previous: str, job_id: str, selected: str
    ) -> None:
        # A stale application predecessor must not hide an unresolved retry.
        unresolved = self.repository.unconfirmed_for_job(job_id)
        if unresolved:
            if any(row["workflow_id"] != selected for row in unresolved):
                raise DispatchUncertain()
            return  # Reissue the same identity so its exact preparation can reconcile.
        if self.repository.has_unconfirmed(previous):
            raise DispatchUncertain()
        current = await self.port.current_execution(workflow_id=previous)
        if current is not None and current["status"] not in {
            "COMPLETED",
            "FAILED",
            "CANCELED",
            "TERMINATED",
            "TIMED_OUT",
        }:
            raise OperationConflict()

    def trusted_input(self, action: str, raw: dict) -> dict:
        model = {
            "source": IngestSourceWorkflowInput,
            "text": IngestTextWorkflowInput,
            "schedule": IngestSourceWorkflowInput,
            "deletion": DeleteManagedDocumentInput,
        }[action]
        value = model.model_validate(raw).model_dump(mode="json", exclude_unset=True)
        # Signed application preparation supplies dataset/artifact ownership until
        # Epochs 9–11. Queue authority already belongs entirely to this deployment.
        defaults = {
            "workflow": os.environ.get(
                "TEMPORAL_RAG_WORKFLOW_TASK_QUEUE", "rag-workflow-task-queue"
            ),
            "scraper": os.environ.get(
                "TEMPORAL_RAG_SCRAPER_TASK_QUEUE", "rag-scraper-task-queue"
            ),
            "converter": os.environ.get(
                "TEMPORAL_RAG_CONVERTER_TASK_QUEUE", "rag-converter-task-queue"
            ),
            "ingestion": os.environ.get(
                "TEMPORAL_RAG_INGESTION_TASK_QUEUE", "rag-ingestion-task-queue"
            ),
            "indexer": os.environ.get(
                "TEMPORAL_RAG_INDEXER_TASK_QUEUE",
                os.environ.get(
                    "TEMPORAL_RAG_INGESTION_TASK_QUEUE", "rag-ingestion-task-queue"
                ),
            ),
        }
        supplied = value.get("task_queues", {})
        if any(
            key not in defaults or entry != defaults[key]
            for key, entry in supplied.items()
        ):
            raise ValueError("Prepared queues differ from deployed control settings.")
        # Preserve supplied legacy wire shape; no changes to worker history input.
        if not supplied:
            roles = (
                ("workflow", "indexer", "ingestion")
                if action == "text"
                else tuple(defaults)
            )
            value["task_queues"] = {key: defaults[key] for key in roles}
        return value

    async def start(
        self,
        action: str,
        workflow_id: str,
        raw: dict,
        *,
        replacement: bool = False,
        recovery_attempt: int = 0,
        cadence: str | None = None,
        schedule_id: str | None = None,
    ) -> dict:
        value = self.trusted_input(action, raw)
        if action != "text" and recovery_attempt != 0:
            raise ValueError("Recovery attempt is only valid for direct text.")
        if action != "schedule" and (cadence is not None or schedule_id is not None):
            raise ValueError("Refresh schedule fields require a schedule operation.")
        if action != "deletion" and not (value.get("task_id") and value.get("job_id")):
            raise ValueError("Persisted application task and job identity required.")
        if action in {"source", "schedule"}:
            validate_source_workflow(value["source_id"], workflow_id)
        elif action == "text":
            if workflow_id != text_workflow(value["task_id"]):
                raise ValueError(
                    "Text workflow identity differs from its prepared task."
                )
        elif workflow_id != "managed-" + value["operation_id"]:
            raise ValueError(
                "Deletion workflow identity differs from its prepared operation."
            )
        if replacement and (action != "source" or not value.get("managed_document_id")):
            raise ValueError("Replacement requires a prepared managed source.")
        if action == "schedule" and (
            schedule_id != source_schedule(value["source_id"])
            or cadence not in {"daily", "weekly", "monthly"}
        ):
            raise ValueError("Invalid prepared refresh schedule.")
        payload = {
            "workflow_input": value,
            "replacement": replacement,
            "cadence": cadence,
            "schedule_id": schedule_id,
        }
        row = self.repository.prepare(
            action,
            workflow_id,
            payload,
            [value.get("task_id"), value.get("job_id"), recovery_attempt],
        )
        operation_id = row["operation_id"]
        payload = row["payload"]
        if row["status"] == "confirmed" and action != "deletion":
            return row["receipt"]
        url_generation = (
            action == "source" and payload["workflow_input"].get("owner") == "url"
        )
        claimed = self.repository.claim(
            operation_id, **({"url_dispatch_protocol": 1} if url_generation else {})
        )
        # No repository transaction/connection is retained across any await.
        try:
            if not claimed and row["status"] != "confirmed":
                if action == "schedule":
                    raise DispatchUncertain()
                receipt = await self.port.reconcile_start(
                    workflow_id=workflow_id,
                    operation_id=operation_id,
                    **(
                        {"workflow_input": payload["workflow_input"]}
                        if url_generation
                        else {}
                    ),
                )
                if receipt is not None:
                    if receipt.get("workflow_id") != workflow_id or not receipt.get(
                        "run_id"
                    ):
                        raise DispatchUncertain()
                    if action == "deletion":
                        receipt["status"] = "pending"
                    self.repository.finish(operation_id, receipt)
                    return receipt
                # Only a definitive NOT_FOUND permits a URL retry. Temporal's
                # REJECT_DUPLICATE also fences a delayed or concurrent first RPC,
                # even if it finishes between this lookup and the next start.
                # Legacy ALLOW_DUPLICATE families must remain reconciliation-only.
                if not url_generation or row["status"] not in {
                    "dispatching",
                    "uncertain",
                }:
                    raise DispatchUncertain()
                if payload.get("url_dispatch_protocol") != 1:
                    # An old unacknowledged closed run may already have expired.
                    # NOT_FOUND cannot distinguish that from never submitted.
                    raise DispatchUncertain()
            kwargs = {
                "workflow_id": workflow_id,
                "workflow_input": payload["workflow_input"],
                "control_operation": operation_id,
            }
            if action == "source":
                result = await self.port.start_ingest_workflow(
                    **kwargs, reuse_completed=payload["replacement"]
                )
                receipt = result.to_payload()
            elif action == "text":
                result = await self.port.start_text_ingest_workflow(**kwargs)
                receipt = result.to_payload()
            elif action == "deletion":
                receipt = await self.port.start_managed_deletion(**kwargs)
            else:
                result = await self.port.upsert_ingest_schedule(
                    **kwargs,
                    cadence=payload["cadence"],
                    schedule_id=payload["schedule_id"],
                )
                receipt = result.to_payload()
            if receipt.get("workflow_id") != workflow_id or (
                action != "schedule" and not receipt.get("run_id")
            ):
                raise DispatchUncertain()
            self.repository.finish(operation_id, receipt)
            return receipt
        except TemporalUnavailable:
            if claimed:
                self.repository.release_unsubmitted(operation_id)
            raise DispatchUnavailable() from None
        except WorkflowOwnershipConflict:
            if url_generation:
                self.repository.reject_url_ownership(operation_id)
            elif claimed:
                self.repository.release_unsubmitted(operation_id)
            raise OperationConflict() from None
        except DispatchUncertain:
            raise
        except WorkflowAlreadyStartedError:
            self.repository.finish(operation_id, None)
            raise
        except Exception as error:
            self.repository.finish(operation_id, None)
            logger.warning(
                "Workflow dispatch unconfirmed operation_id=%s workflow_id=%s exception_class=%s",
                operation_id,
                workflow_id,
                type(error).__name__,
            )
            raise DispatchUncertain() from None
