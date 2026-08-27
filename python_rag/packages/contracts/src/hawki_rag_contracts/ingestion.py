"""Compatibility imports for pipeline ingestion contracts."""

from hawki_rag_contracts.pipeline.ingestion import (
    ConvertActivityInput,
    ConvertResult,
    FailedWorkflowResult,
    IndexActivityInput,
    IndexResult,
    IngestSourceWorkflowInput,
    IngestionOptions,
    IngestionStatus,
    ReadyActivityInput,
    ScrapeResult,
    StorageConfig,
    TaskQueueConfig,
    shared_storage_root,
)

__all__ = [
    "ConvertActivityInput",
    "ConvertResult",
    "FailedWorkflowResult",
    "IndexActivityInput",
    "IndexResult",
    "IngestSourceWorkflowInput",
    "IngestionOptions",
    "IngestionStatus",
    "ReadyActivityInput",
    "ScrapeResult",
    "StorageConfig",
    "TaskQueueConfig",
    "shared_storage_root",
]
