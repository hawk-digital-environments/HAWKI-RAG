"""Wire models for the durable source-ingestion pipeline."""

from __future__ import annotations

from collections.abc import Mapping
from enum import StrEnum

from pydantic import BaseModel, ConfigDict, Field, JsonValue, model_validator

from hawki_rag_contracts.pipeline.temporal import (
    CONVERTER_TASK_QUEUE,
    LEGACY_INGESTION_TASK_QUEUE,
    SCRAPER_TASK_QUEUE,
    WORKFLOW_TASK_QUEUE,
)
from hawki_rag_contracts.pipeline.artifacts import MarkdownArtifact, RawArtifact
from hawki_rag_contracts.pipeline.status import GraphFailure


class IngestionStatus(StrEnum):
    """Statuses currently exchanged by ingestion activities."""

    RUNNING = "running"
    SUCCESS = "success"
    FAILED = "failed"
    SKIPPED = "skipped"
    READY = "ready"


class TaskQueueConfig(BaseModel):
    """Queue overrides carried in a workflow input payload."""

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    workflow: str = Field(default=WORKFLOW_TASK_QUEUE, min_length=1, max_length=255)
    scraper: str = Field(default=SCRAPER_TASK_QUEUE, min_length=1, max_length=255)
    converter: str = Field(default=CONVERTER_TASK_QUEUE, min_length=1, max_length=255)
    indexer: str | None = Field(default=None, min_length=1, max_length=255)
    ingestion: str = Field(
        default=LEGACY_INGESTION_TASK_QUEUE, min_length=1, max_length=255
    )

    @property
    def resolved_indexer(self) -> str:
        """Prefer the new indexer queue and retain the ingestion fallback."""

        return self.indexer or self.ingestion


class StorageConfig(BaseModel):
    """Artifact storage locations supplied by Laravel."""

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    # Keep the retired fields deserializable while old workflow histories drain.
    # Worker code rejects a non-shared mode and ignores the old object prefix.
    mode: str = Field(default="shared", min_length=1, max_length=32)
    shared_root: str | None = Field(default=None, max_length=4096)
    object_prefix: str | None = Field(default=None, max_length=4096)


def shared_storage_root(workflow_input: Mapping[str, object]) -> str:
    """Return Laravel's shared root and reject unsupported storage modes."""

    storage_payload = workflow_input.get("storage")
    if not isinstance(storage_payload, Mapping):
        raise ValueError("workflow_input.storage must describe shared storage")

    storage = StorageConfig.model_validate(dict(storage_payload))
    if storage.mode.lower() != "shared":
        raise ValueError("Only shared local artifact storage is supported")
    if not storage.shared_root:
        raise ValueError("workflow_input.storage.shared_root is required")
    return storage.shared_root


class IngestionOptions(BaseModel):
    """Dataset-specific indexing options fixed at workflow start."""

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    provider: str = Field(min_length=1, max_length=80)
    embedding_model: str = Field(min_length=1, max_length=160)
    graph_model: str = Field(min_length=1, max_length=160)
    vision_model: str = Field(min_length=1, max_length=160)
    graph: bool = False
    collection: str | None = Field(default=None, min_length=1, max_length=191)
    neo4j_namespace: str | None = Field(default=None, max_length=191)
    chunk_chars: int = Field(default=1200, gt=0)
    chunk_overlap: int = Field(default=250, ge=0)
    batch_size: int = Field(default=64, gt=0)

    @model_validator(mode="after")
    def validate_chunk_overlap(self) -> "IngestionOptions":
        """Keep overlapping chunks smaller than their target size."""

        if self.chunk_overlap >= self.chunk_chars:
            raise ValueError("chunk_overlap must be smaller than chunk_chars")
        return self


class IngestSourceWorkflowInput(BaseModel):
    """Production payload used to start ``IngestSourceWorkflow``."""

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    source_id: str = Field(min_length=1, max_length=191)
    source_url: str = Field(min_length=1, max_length=4096)
    dataset_id: str = Field(min_length=1, max_length=191)
    task_id: str | None = Field(default=None, max_length=191)
    job_id: str | None = Field(default=None, max_length=191)
    managed_document_id: str | None = Field(default=None, max_length=191)
    upload: dict[str, JsonValue] | None = None
    converter_mode: str = Field(default="native", min_length=1, max_length=32)
    custom_converter_profile_path: str | None = Field(default=None, max_length=4096)
    refresh: dict[str, JsonValue] = Field(default_factory=dict)
    raw_output_path: str = Field(min_length=1, max_length=4096)
    markdown_output_path: str = Field(min_length=1, max_length=4096)
    ingest_manifest_path: str | None = Field(default=None, max_length=4096)
    metadata: dict[str, JsonValue] = Field(default_factory=dict)
    storage: StorageConfig
    task_queues: TaskQueueConfig = Field(default_factory=TaskQueueConfig)
    ingestion: IngestionOptions
    external_services: dict[str, JsonValue] = Field(default_factory=dict)


class ScrapeResult(BaseModel):
    """Result returned by the scraper activity."""

    model_config = ConfigDict(extra="allow", frozen=True, str_strip_whitespace=True)

    source_id: str = Field(min_length=1, max_length=191)
    status: IngestionStatus
    raw_dir: str | None = Field(default=None, max_length=4096)
    external_job_id: str | None = Field(default=None, max_length=191)
    files_created: int = Field(default=0, ge=0)
    artifacts: list[RawArtifact] = Field(default_factory=list)
    error_details: str | None = Field(default=None, max_length=2048)


class ConvertActivityInput(BaseModel):
    """Input passed from scrape to conversion."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    workflow_input: dict[str, JsonValue]
    scrape_result: dict[str, JsonValue]


class ConvertResult(BaseModel):
    """Result returned by the converter activity."""

    model_config = ConfigDict(extra="allow", frozen=True, str_strip_whitespace=True)

    source_id: str = Field(min_length=1, max_length=191)
    status: IngestionStatus
    markdown_dir: str | None = Field(default=None, max_length=4096)
    external_job_id: str | None = Field(default=None, max_length=191)
    markdown_files_created: int = Field(default=0, ge=0)
    artifacts: list[MarkdownArtifact] = Field(default_factory=list)
    error_details: str | None = Field(default=None, max_length=2048)


class IndexActivityInput(BaseModel):
    """Input passed from conversion to indexing."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    workflow_input: dict[str, JsonValue]
    scrape_result: dict[str, JsonValue] = Field(default_factory=dict)
    convert_result: dict[str, JsonValue]


class IndexResult(BaseModel):
    """Aggregate result returned by the indexer activity."""

    model_config = ConfigDict(extra="allow", frozen=True, str_strip_whitespace=True)

    source_id: str = Field(min_length=1, max_length=191)
    status: IngestionStatus
    documents_indexed: int = Field(default=0, ge=0)
    chunks_indexed: int = Field(default=0, ge=0)
    vectors_upserted: int = Field(default=0, ge=0)
    graph_records_updated: int = Field(default=0, ge=0)
    failed_documents: int = Field(default=0, ge=0)
    skipped_documents: int = Field(default=0, ge=0)
    new_documents: int = Field(default=0, ge=0)
    changed_documents: int = Field(default=0, ge=0)
    unchanged_documents: int = Field(default=0, ge=0)
    document_version: str | None = Field(default=None, max_length=191)
    error_details: str | None = Field(default=None, max_length=2048)
    ingestion_summary: dict[str, JsonValue] | None = None
    graph_preview: dict[str, JsonValue] | None = None
    graph_failures: list[GraphFailure] = Field(default_factory=list)


class ReadyActivityInput(BaseModel):
    """Input passed from indexing to source finalization."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    workflow_input: dict[str, JsonValue]
    scrape_result: dict[str, JsonValue] = Field(default_factory=dict)
    convert_result: dict[str, JsonValue]
    ingest_result: dict[str, JsonValue]


class FailedWorkflowResult(BaseModel):
    """Stable failure envelope returned when a workflow phase fails."""

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    source_id: str | None = Field(default=None, max_length=191)
    source_url: str | None = Field(default=None, max_length=4096)
    phase: str = Field(min_length=1, max_length=80)
    status: str = "failed"
    error_details: JsonValue


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
