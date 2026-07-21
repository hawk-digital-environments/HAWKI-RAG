"""Temporal workflow definitions for RAG source ingestion."""

from __future__ import annotations

from datetime import timedelta
from typing import Any

from temporalio import workflow
from temporalio.common import RetryPolicy


_SCRAPE_START_TO_CLOSE_TIMEOUT = timedelta(hours=13)
_SCRAPE_SCHEDULE_TO_CLOSE_TIMEOUT = timedelta(hours=14)


def _task_queue(workflow_input: dict[str, Any], key: str, default: str) -> str:
    queues = workflow_input.get("task_queues")
    if isinstance(queues, dict):
        value = queues.get(key)
        if isinstance(value, str) and value.strip():
            return value
    return default


def _retry_policy() -> RetryPolicy:
    return RetryPolicy(
        initial_interval=timedelta(seconds=5),
        backoff_coefficient=2.0,
        maximum_interval=timedelta(minutes=5),
        maximum_attempts=5,
    )


def _deduplication_retry_policy() -> RetryPolicy:
    return RetryPolicy(
        initial_interval=timedelta(seconds=10),
        backoff_coefficient=2.0,
        maximum_interval=timedelta(minutes=5),
        maximum_attempts=0,
    )


def _failed_result(workflow_input: dict[str, Any], phase: str, result: dict[str, Any]) -> dict[str, Any]:
    return {
        "source_id": workflow_input.get("source_id"),
        "source_url": workflow_input.get("source_url"),
        "phase": phase,
        "status": "failed",
        "error_details": result.get("error_details") or result.get("error") or result,
    }


@workflow.defn(name="IngestSourceWorkflow")
class IngestSourceWorkflow:
    """Coordinate source scrape, conversion, ingestion, and readiness marking."""

    @workflow.run
    async def run(self, workflow_input: dict[str, Any]) -> dict[str, Any]:
        source_id = workflow_input.get("source_id")
        workflow.logger.info("IngestSourceWorkflow started source_id=%s", source_id)
        retry_policy = _retry_policy()

        scrape_result = await workflow.execute_activity(
            "scrape_source",
            workflow_input,
            task_queue=_task_queue(workflow_input, "scraper", "rag-scraper-task-queue"),
            start_to_close_timeout=_SCRAPE_START_TO_CLOSE_TIMEOUT,
            schedule_to_close_timeout=_SCRAPE_SCHEDULE_TO_CLOSE_TIMEOUT,
            heartbeat_timeout=timedelta(minutes=2),
            retry_policy=retry_policy,
        )
        if scrape_result.get("status") != "success":
            return _failed_result(workflow_input, "scrape_source", scrape_result)

        deduplication_result = await workflow.execute_activity(
            "classify_source_documents",
            {
                "workflow_input": workflow_input,
                "scrape_result": scrape_result,
            },
            task_queue=_task_queue(workflow_input, "converter", "rag-converter-task-queue"),
            start_to_close_timeout=timedelta(minutes=30),
            schedule_to_close_timeout=timedelta(hours=8),
            heartbeat_timeout=timedelta(minutes=2),
            retry_policy=_deduplication_retry_policy(),
        )
        if deduplication_result.get("status") != "success":
            return _failed_result(workflow_input, "classify_source_documents", deduplication_result)

        if deduplication_result.get("skip_processing") is True:
            duplicate_documents = int(deduplication_result.get("duplicate_documents") or 0)
            skipped_ingest_result = {
                "source_id": source_id,
                "status": "success",
                "documents_indexed": 0,
                "chunks_indexed": 0,
                "vectors_upserted": 0,
                "graph_records_updated": 0,
                "failed_documents": 0,
                "skipped_documents": duplicate_documents,
                "new_documents": 0,
                "changed_documents": 0,
                "unchanged_documents": duplicate_documents,
                "document_version": deduplication_result.get("document_version"),
                "deduplication": deduplication_result,
                "error_details": None,
            }
            return await workflow.execute_activity(
                "mark_source_ready",
                {
                    "workflow_input": workflow_input,
                    "scrape_result": scrape_result,
                    "deduplication_result": deduplication_result,
                    "convert_result": {
                        "status": "skipped",
                        "reason": "duplicate_source_content",
                    },
                    "ingest_result": skipped_ingest_result,
                },
                task_queue=_task_queue(workflow_input, "ingestion", "rag-ingestion-task-queue"),
                start_to_close_timeout=timedelta(minutes=5),
                schedule_to_close_timeout=timedelta(minutes=15),
                retry_policy=retry_policy,
            )

        convert_result = await workflow.execute_activity(
            "inspect_and_convert_files",
            {
                "workflow_input": workflow_input,
                "scrape_result": scrape_result,
                "deduplication_result": deduplication_result,
            },
            task_queue=_task_queue(workflow_input, "converter", "rag-converter-task-queue"),
            start_to_close_timeout=timedelta(hours=2),
            schedule_to_close_timeout=timedelta(hours=3),
            retry_policy=retry_policy,
        )
        if convert_result.get("status") != "success":
            return _failed_result(workflow_input, "inspect_and_convert_files", convert_result)

        ingest_result = await workflow.execute_activity(
            "ingest_markdown_files",
            {
                "workflow_input": workflow_input,
                "scrape_result": scrape_result,
                "deduplication_result": deduplication_result,
                "convert_result": convert_result,
            },
            task_queue=_task_queue(workflow_input, "ingestion", "rag-ingestion-task-queue"),
            start_to_close_timeout=timedelta(hours=4),
            schedule_to_close_timeout=timedelta(hours=6),
            retry_policy=retry_policy,
        )
        if ingest_result.get("status") != "success":
            return _failed_result(workflow_input, "ingest_markdown_files", ingest_result)

        ready_result = await workflow.execute_activity(
            "mark_source_ready",
            {
                "workflow_input": workflow_input,
                "scrape_result": scrape_result,
                "deduplication_result": deduplication_result,
                "convert_result": convert_result,
                "ingest_result": ingest_result,
            },
            task_queue=_task_queue(workflow_input, "ingestion", "rag-ingestion-task-queue"),
            start_to_close_timeout=timedelta(minutes=5),
            schedule_to_close_timeout=timedelta(minutes=15),
            retry_policy=retry_policy,
        )
        workflow.logger.info("IngestSourceWorkflow completed source_id=%s", source_id)
        return ready_result


__all__ = ["IngestSourceWorkflow"]
