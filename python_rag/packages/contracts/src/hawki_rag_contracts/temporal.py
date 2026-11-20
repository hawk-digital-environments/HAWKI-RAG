"""Compatibility imports for pipeline Temporal names and queue resolution."""

from hawki_rag_contracts.pipeline.temporal import (
    ActivityQueueRole,
    CONVERTER_TASK_QUEUE,
    CONVERT_FILES_ACTIVITY,
    INDEXER_QUEUE_PATCH_ID,
    INDEXER_TASK_QUEUE,
    INDEXER_TERMINAL_CALLBACK_PATCH_ID,
    INDEX_MARKDOWN_ACTIVITY,
    INGEST_SOURCE_WORKFLOW,
    LEGACY_INGESTION_TASK_QUEUE,
    MARK_SOURCE_READY_ACTIVITY,
    SCRAPER_TASK_QUEUE,
    SCRAPE_SOURCE_ACTIVITY,
    WORKFLOW_TASK_QUEUE,
    resolve_activity_task_queue,
    resolve_legacy_ingestion_task_queue,
)

__all__ = [
    "ActivityQueueRole",
    "CONVERTER_TASK_QUEUE",
    "CONVERT_FILES_ACTIVITY",
    "INDEXER_QUEUE_PATCH_ID",
    "INDEXER_TASK_QUEUE",
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
