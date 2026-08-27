"""Deterministic Temporal orchestration for source ingestion."""

from __future__ import annotations

from datetime import timedelta
from typing import Any

from temporalio import workflow

from hawki_rag_contracts.pipeline.temporal import (
    ActivityQueueRole,
    CONVERT_FILES_ACTIVITY,
    INDEXER_QUEUE_PATCH_ID,
    INDEXER_TERMINAL_CALLBACK_PATCH_ID,
    INDEX_MARKDOWN_ACTIVITY,
    INGEST_SOURCE_WORKFLOW,
    MARK_SOURCE_READY_ACTIVITY,
    SCRAPE_SOURCE_ACTIVITY,
    resolve_activity_task_queue,
    resolve_legacy_ingestion_task_queue,
)
from hawki_workflow_worker.workflows.retry_policy import ingestion_activity_retry_policy


_SCRAPE_START_TO_CLOSE_TIMEOUT = timedelta(hours=13)
_SCRAPE_SCHEDULE_TO_CLOSE_TIMEOUT = timedelta(hours=14)


def _failed_result(
    workflow_input: dict[str, Any],
    phase: str,
    result: dict[str, Any],
) -> dict[str, Any]:
    """Return the legacy failure envelope without raising a workflow error."""

    return {
        "source_id": workflow_input.get("source_id"),
        "source_url": workflow_input.get("source_url"),
        "phase": phase,
        "status": "failed",
        "error_details": result.get("error_details") or result.get("error") or result,
    }


@workflow.defn(name=INGEST_SOURCE_WORKFLOW)
class IngestSourceWorkflow:
    """Coordinate scrape, conversion, indexing, and readiness activities."""

    @workflow.run
    async def run(self, workflow_input: dict[str, Any]) -> dict[str, Any]:
        source_id = workflow_input.get("source_id")
        workflow.logger.info("IngestSourceWorkflow started source_id=%s", source_id)
        retry_policy = ingestion_activity_retry_policy()

        scrape_result = await workflow.execute_activity(
            SCRAPE_SOURCE_ACTIVITY,
            workflow_input,
            task_queue=resolve_activity_task_queue(
                workflow_input, ActivityQueueRole.SCRAPER
            ),
            start_to_close_timeout=_SCRAPE_START_TO_CLOSE_TIMEOUT,
            schedule_to_close_timeout=_SCRAPE_SCHEDULE_TO_CLOSE_TIMEOUT,
            heartbeat_timeout=timedelta(minutes=2),
            retry_policy=retry_policy,
        )
        if scrape_result.get("status") != "success":
            return _failed_result(workflow_input, SCRAPE_SOURCE_ACTIVITY, scrape_result)

        convert_result = await workflow.execute_activity(
            CONVERT_FILES_ACTIVITY,
            {
                "workflow_input": workflow_input,
                "scrape_result": scrape_result,
            },
            task_queue=resolve_activity_task_queue(
                workflow_input, ActivityQueueRole.CONVERTER
            ),
            start_to_close_timeout=timedelta(hours=2),
            schedule_to_close_timeout=timedelta(hours=3),
            retry_policy=retry_policy,
        )
        if convert_result.get("status") != "success":
            return _failed_result(
                workflow_input, CONVERT_FILES_ACTIVITY, convert_result
            )

        # Histories created before the indexer queue existed must continue to
        # emit the legacy task-queue command during replay. New executions
        # record the patch marker and may honor task_queues.indexer.
        if workflow.patched(INDEXER_QUEUE_PATCH_ID):
            indexer_task_queue = resolve_activity_task_queue(
                workflow_input,
                ActivityQueueRole.INDEXER,
            )
        else:
            indexer_task_queue = resolve_legacy_ingestion_task_queue(workflow_input)

        ingest_result = await workflow.execute_activity(
            INDEX_MARKDOWN_ACTIVITY,
            {
                "workflow_input": workflow_input,
                "scrape_result": scrape_result,
                "convert_result": convert_result,
            },
            task_queue=indexer_task_queue,
            start_to_close_timeout=timedelta(hours=4),
            schedule_to_close_timeout=timedelta(hours=6),
            retry_policy=retry_policy,
        )
        ingest_succeeded = ingest_result.get("status") == "success"
        if not ingest_succeeded and not workflow.patched(
            INDEXER_TERMINAL_CALLBACK_PATCH_ID
        ):
            return _failed_result(
                workflow_input, INDEX_MARKDOWN_ACTIVITY, ingest_result
            )

        ready_result = await workflow.execute_activity(
            MARK_SOURCE_READY_ACTIVITY,
            {
                "workflow_input": workflow_input,
                "scrape_result": scrape_result,
                "convert_result": convert_result,
                "ingest_result": ingest_result,
            },
            task_queue=indexer_task_queue,
            start_to_close_timeout=timedelta(minutes=5),
            schedule_to_close_timeout=timedelta(minutes=15),
            retry_policy=retry_policy,
        )
        if not ingest_succeeded:
            return _failed_result(
                workflow_input, INDEX_MARKDOWN_ACTIVITY, ingest_result
            )
        workflow.logger.info("IngestSourceWorkflow completed source_id=%s", source_id)
        return ready_result


__all__ = ["IngestSourceWorkflow"]
