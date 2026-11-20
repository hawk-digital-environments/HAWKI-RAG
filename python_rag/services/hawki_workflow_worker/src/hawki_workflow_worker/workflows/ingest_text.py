"""Temporal orchestration for indexing an existing Markdown artifact."""

from __future__ import annotations

from datetime import timedelta
from typing import Any

from temporalio import workflow

from hawki_rag_contracts.pipeline.identity import document_id
from hawki_rag_contracts.pipeline.temporal import (
    ActivityQueueRole,
    INDEX_MARKDOWN_ACTIVITY,
    INGEST_TEXT_WORKFLOW,
    MARK_SOURCE_READY_ACTIVITY,
    resolve_activity_task_queue,
)
from hawki_workflow_worker.workflows.retry_policy import ingestion_activity_retry_policy


@workflow.defn(name=INGEST_TEXT_WORKFLOW)
class IngestTextWorkflow:
    """Index prepared Markdown, then project the source as ready."""

    @workflow.run
    async def run(self, workflow_input: dict[str, Any]) -> dict[str, Any]:
        source_id = str(workflow_input["source_id"])
        markdown_path = str(workflow_input["markdown_path"])
        relative_path = markdown_path.rsplit("/", 1)[-1]
        content_hash = str(workflow_input["content_hash"])
        indexer_queue = resolve_activity_task_queue(
            workflow_input,
            ActivityQueueRole.INDEXER,
        )
        convert_result = {
            "source_id": source_id,
            "status": "success",
            "markdown_dir": workflow_input["markdown_output_path"],
            "markdown_files_created": 1,
            "artifacts": [
                {
                    "uri": markdown_path,
                    "relative_path": relative_path,
                    "sha256": content_hash,
                    "media_type": "text/markdown",
                    "source_id": source_id,
                    "document_id": document_id(source_id, relative_path),
                    "content_hash": content_hash,
                }
            ],
        }
        retry_policy = ingestion_activity_retry_policy()

        ingest_result = await workflow.execute_activity(
            INDEX_MARKDOWN_ACTIVITY,
            {
                "workflow_input": workflow_input,
                "scrape_result": {},
                "convert_result": convert_result,
            },
            task_queue=indexer_queue,
            start_to_close_timeout=timedelta(hours=4),
            schedule_to_close_timeout=timedelta(hours=6),
            retry_policy=retry_policy,
        )
        return await workflow.execute_activity(
            MARK_SOURCE_READY_ACTIVITY,
            {
                "workflow_input": workflow_input,
                "scrape_result": {},
                "convert_result": convert_result,
                "ingest_result": ingest_result,
            },
            task_queue=indexer_queue,
            start_to_close_timeout=timedelta(minutes=5),
            schedule_to_close_timeout=timedelta(minutes=15),
            retry_policy=retry_policy,
        )


__all__ = ["IngestTextWorkflow"]
