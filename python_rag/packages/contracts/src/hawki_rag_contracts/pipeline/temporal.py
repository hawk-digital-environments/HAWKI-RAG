"""Stable Temporal names and deterministic task-queue resolution."""

from __future__ import annotations

from collections.abc import Mapping
from enum import StrEnum


INGEST_SOURCE_WORKFLOW = "IngestSourceWorkflow"
SCRAPE_SOURCE_ACTIVITY = "scrape_source"
CONVERT_FILES_ACTIVITY = "inspect_and_convert_files"
INDEX_MARKDOWN_ACTIVITY = "ingest_markdown_files"
MARK_SOURCE_READY_ACTIVITY = "mark_source_ready"

WORKFLOW_TASK_QUEUE = "rag-workflow-task-queue"
SCRAPER_TASK_QUEUE = "rag-scraper-task-queue"
CONVERTER_TASK_QUEUE = "rag-converter-task-queue"
LEGACY_INGESTION_TASK_QUEUE = "rag-ingestion-task-queue"
INDEXER_TASK_QUEUE = LEGACY_INGESTION_TASK_QUEUE
INDEXER_QUEUE_PATCH_ID = "hawki-rag-indexer-task-queue-v1"
INDEXER_TERMINAL_CALLBACK_PATCH_ID = "hawki-rag-indexer-terminal-callback-v1"


class ActivityQueueRole(StrEnum):
    """Logical activity worker roles used in workflow input."""

    SCRAPER = "scraper"
    CONVERTER = "converter"
    INDEXER = "indexer"


_DEFAULT_ACTIVITY_QUEUES = {
    ActivityQueueRole.SCRAPER: SCRAPER_TASK_QUEUE,
    ActivityQueueRole.CONVERTER: CONVERTER_TASK_QUEUE,
    ActivityQueueRole.INDEXER: INDEXER_TASK_QUEUE,
}


def resolve_activity_task_queue(
    workflow_input: Mapping[str, object],
    role: ActivityQueueRole,
) -> str:
    """Resolve a queue without changing legacy ingestion workflow payloads.

    Indexer deployments accept the new ``indexer`` key first and then the
    existing ``ingestion`` key. The default intentionally remains the legacy
    queue while existing workflow executions drain.
    """

    queues = workflow_input.get("task_queues")
    if isinstance(queues, Mapping):
        keys = (
            (role.value, "ingestion")
            if role == ActivityQueueRole.INDEXER
            else (role.value,)
        )
        for key in keys:
            value = queues.get(key)
            if isinstance(value, str) and value.strip():
                return value
    return _DEFAULT_ACTIVITY_QUEUES[role]


def resolve_legacy_ingestion_task_queue(workflow_input: Mapping[str, object]) -> str:
    """Resolve the queue exactly as the pre-refactor workflow did."""

    queues = workflow_input.get("task_queues")
    if isinstance(queues, Mapping):
        value = queues.get("ingestion")
        if isinstance(value, str) and value.strip():
            return value
    return LEGACY_INGESTION_TASK_QUEUE


__all__ = [
    "ActivityQueueRole",
    "CONVERTER_TASK_QUEUE",
    "CONVERT_FILES_ACTIVITY",
    "INDEXER_TASK_QUEUE",
    "INDEXER_QUEUE_PATCH_ID",
    "INDEXER_TERMINAL_CALLBACK_PATCH_ID",
    "INDEX_MARKDOWN_ACTIVITY",
    "INGEST_SOURCE_WORKFLOW",
    "LEGACY_INGESTION_TASK_QUEUE",
    "MARK_SOURCE_READY_ACTIVITY",
    "SCRAPER_TASK_QUEUE",
    "SCRAPE_SOURCE_ACTIVITY",
    "WORKFLOW_TASK_QUEUE",
    "resolve_activity_task_queue",
    "resolve_legacy_ingestion_task_queue",
]
