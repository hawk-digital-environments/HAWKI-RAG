"""New workflow type: existing ingestion histories are unchanged."""

from __future__ import annotations

from datetime import timedelta
from typing import Any

from temporalio import workflow
from temporalio.exceptions import ActivityError, ApplicationError

from hawki_rag_contracts.pipeline.deletion import (
    DELETE_MANAGED_DOCUMENT_WORKFLOW,
    DELETE_MANAGED_GRAPH_ACTIVITY,
    DELETE_MANAGED_VECTORS_ACTIVITY,
    REPORT_MANAGED_DELETION_ACTIVITY,
    VERIFY_DELETION_WRITERS_ACTIVITY,
    DeleteManagedDocumentInput,
)
from hawki_rag_contracts.pipeline.temporal import (
    ActivityQueueRole,
    resolve_activity_task_queue,
)
from hawki_workflow_worker.workflows.retry_policy import ingestion_activity_retry_policy


@workflow.defn(name=DELETE_MANAGED_DOCUMENT_WORKFLOW)
class DeleteManagedDocumentWorkflow:
    @workflow.run
    async def run(self, payload: dict[str, Any]) -> dict[str, Any]:
        body = DeleteManagedDocumentInput.model_validate(payload)
        options = {
            "task_queue": resolve_activity_task_queue(payload, ActivityQueueRole.INDEXER),
            "start_to_close_timeout": timedelta(minutes=5),
            "schedule_to_close_timeout": timedelta(hours=1),
            "retry_policy": ingestion_activity_retry_policy(),
        }
        results: list[dict[str, Any]] = []
        try:
            await workflow.execute_activity(
                VERIFY_DELETION_WRITERS_ACTIVITY, payload, **options
            )
            for target in body.targets:
                scoped = {
                    "operation_id": body.operation_id,
                    "dataset_id": body.dataset_id,
                    "target": target.model_dump(mode="json"),
                }
                vectors = await workflow.execute_activity(
                    DELETE_MANAGED_VECTORS_ACTIVITY, scoped, **options
                )
                graph = await workflow.execute_activity(
                    DELETE_MANAGED_GRAPH_ACTIVITY, scoped, **options
                )
                results.append({"output_id": target.output_id, "qdrant": vectors, "neo4j": graph})
        except ActivityError:
            await workflow.execute_activity(
                REPORT_MANAGED_DELETION_ACTIVITY,
                {"input": payload, "status": "failed", "results": results},
                **options,
            )
            raise ApplicationError("Managed deletion requires recovery.", non_retryable=True)

        result = {"input": payload, "status": "completed", "results": results}
        # Separate callback activity: retrying delivery never repeats acknowledged sinks.
        await workflow.execute_activity(REPORT_MANAGED_DELETION_ACTIVITY, result, **options)
        return result
