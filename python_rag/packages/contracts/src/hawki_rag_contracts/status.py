"""Signed worker-status callback contracts owned by Laravel."""

from __future__ import annotations

from enum import StrEnum
from typing import Literal

from pydantic import (
    AliasChoices,
    AwareDatetime,
    BaseModel,
    ConfigDict,
    Field,
    JsonValue,
    model_validator,
)

from hawki_rag_contracts.artifacts import ArtifactReference
from hawki_rag_contracts.temporal import (
    CONVERT_FILES_ACTIVITY,
    INDEX_MARKDOWN_ACTIVITY,
    MARK_SOURCE_READY_ACTIVITY,
    SCRAPE_SOURCE_ACTIVITY,
)


class WorkerProducer(StrEnum):
    """Worker allowed to produce a pipeline status event."""

    SCRAPER = "scraper"
    CONVERTER = "converter"
    INDEXER = "indexer"


class PipelineStage(StrEnum):
    """Laravel-owned persisted pipeline stages."""

    SCRAPE = "scrape"
    CONVERT = "convert"
    INGEST = "ingest"


class PipelineStageStatus(StrEnum):
    """Monotonic statuses accepted by the callback endpoint."""

    RUNNING = "running"
    COMPLETED = "completed"
    FAILED = "failed"
    SKIPPED = "skipped"


_PRODUCER_STAGES = {
    WorkerProducer.SCRAPER: PipelineStage.SCRAPE,
    WorkerProducer.CONVERTER: PipelineStage.CONVERT,
    WorkerProducer.INDEXER: PipelineStage.INGEST,
}

_ACTIVITY_STAGES = {
    SCRAPE_SOURCE_ACTIVITY: PipelineStage.SCRAPE,
    CONVERT_FILES_ACTIVITY: PipelineStage.CONVERT,
    INDEX_MARKDOWN_ACTIVITY: PipelineStage.INGEST,
    MARK_SOURCE_READY_ACTIVITY: PipelineStage.INGEST,
}


class StatusCounts(BaseModel):
    """Allowlisted aggregate counters reported by a worker."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    total: int = Field(default=0, ge=0)
    processed: int = Field(default=0, ge=0)
    failed: int = Field(default=0, ge=0)
    skipped: int = Field(default=0, ge=0)


class StatusError(BaseModel):
    """Sanitized failure detail safe to persist and show to operators."""

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    code: str = Field(min_length=1, max_length=120)
    message: str = Field(min_length=1, max_length=2048)
    retryable: bool = False


class PipelineWorkerEvent(BaseModel):
    """Versioned, idempotent worker callback payload."""

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    schema_version: Literal[1] = 1
    event_id: str = Field(min_length=1, max_length=191)
    event_type: Literal["pipeline.stage.status"] = "pipeline.stage.status"
    producer: WorkerProducer
    occurred_at: AwareDatetime = Field(
        validation_alias=AliasChoices("occurred_at", "timestamp"),
        serialization_alias="timestamp",
    )
    workflow_id: str = Field(min_length=1, max_length=255)
    run_id: str = Field(min_length=1, max_length=255)
    activity_id: str = Field(min_length=1, max_length=255)
    attempt: int = Field(ge=1)
    job_id: str = Field(min_length=1, max_length=191)
    task_id: str | None = Field(default=None, max_length=191)
    source_id: str = Field(min_length=1, max_length=191)
    stage: PipelineStage
    phase: str = Field(min_length=1, max_length=120)
    status: PipelineStageStatus
    counts: StatusCounts = Field(default_factory=StatusCounts)
    metrics: dict[str, JsonValue] = Field(default_factory=dict)
    artifacts: list[ArtifactReference] = Field(default_factory=list)
    manifest: ArtifactReference | None = None
    errors: list[StatusError] = Field(default_factory=list)
    warnings: list[str] = Field(default_factory=list)
    error_details: str | None = Field(default=None, max_length=2048)
    document_version: str | None = Field(default=None, max_length=191)

    @model_validator(mode="after")
    def enforce_producer_and_activity_stage(self) -> "PipelineWorkerEvent":
        """Prevent one worker or activity from mutating another stage."""

        if _PRODUCER_STAGES[self.producer] != self.stage:
            raise ValueError("producer is not allowed to report this pipeline stage")
        activity_stage = _ACTIVITY_STAGES.get(self.activity_id)
        if activity_stage != self.stage:
            raise ValueError(
                "activity_id does not belong to the reported pipeline stage"
            )
        return self


__all__ = [
    "PipelineStage",
    "PipelineStageStatus",
    "PipelineWorkerEvent",
    "StatusCounts",
    "StatusError",
    "WorkerProducer",
]
