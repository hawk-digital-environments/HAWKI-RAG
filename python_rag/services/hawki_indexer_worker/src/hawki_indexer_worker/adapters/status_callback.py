"""Build indexer-owned status events and send them to Laravel."""

from __future__ import annotations

import hashlib
from datetime import datetime, timezone
from typing import Any

from temporalio import activity

from hawki_rag_contracts.artifacts import ArtifactReference
from hawki_rag_contracts.status import (
    MonitorArtifacts,
    PipelineStage,
    PipelineStageStatus,
    PipelineWorkerEvent,
    StatusCounts,
    StatusError,
    WorkerProducer,
)
from hawki_observability.redaction import sanitize_for_log
from hawki_pipeline_callbacks import (
    LaravelCallbackClient,
    LaravelCallbackSettings,
)

from hawki_indexer_worker.settings import IndexerSettings


def report_status(
    settings: IndexerSettings,
    workflow_input: dict[str, Any],
    *,
    activity_id: str,
    status: PipelineStageStatus,
    phase: str | None = None,
    total: int = 0,
    processed: int = 0,
    failed: int = 0,
    skipped: int = 0,
    metrics: dict[str, Any] | None = None,
    artifacts: list[ArtifactReference] | None = None,
    manifest: ArtifactReference | None = None,
    document_version: str | None = None,
    monitor_artifacts: MonitorArtifacts | None = None,
    error: Exception | None = None,
    activity_info: Any | None = None,
) -> dict[str, Any]:
    info = activity_info or activity.info()
    workflow_id = str(info.workflow_id)
    run_id = str(info.workflow_run_id)
    attempt = int(info.attempt)
    event_key = "|".join([workflow_id, run_id, activity_id, str(attempt), status.value])
    safe_error = sanitize_for_log(error) if error is not None else None
    event = PipelineWorkerEvent(
        event_id="evt_" + hashlib.sha256(event_key.encode("utf-8")).hexdigest(),
        producer=WorkerProducer.INDEXER,
        occurred_at=datetime.now(timezone.utc),
        workflow_id=workflow_id,
        run_id=run_id,
        activity_id=activity_id,
        attempt=attempt,
        job_id=str(workflow_input.get("job_id") or workflow_input["source_id"]),
        task_id=(
            str(workflow_input["task_id"]) if workflow_input.get("task_id") else None
        ),
        source_id=str(workflow_input["source_id"]),
        stage=PipelineStage.INGEST,
        phase=phase or activity_id,
        status=status,
        counts=StatusCounts(
            total=max(0, int(total)),
            processed=max(0, int(processed)),
            failed=max(0, int(failed)),
            skipped=max(0, int(skipped)),
        ),
        metrics=dict(metrics or {}),
        artifacts=list(artifacts or []),
        manifest=manifest,
        errors=(
            [
                StatusError(
                    code=type(error).__name__,
                    message=safe_error or type(error).__name__,
                    retryable=True,
                )
            ]
            if error is not None
            else []
        ),
        error_details=safe_error,
        document_version=document_version,
        monitor_artifacts=monitor_artifacts,
    )
    callback_settings = LaravelCallbackSettings(
        endpoint=settings.callback_url,
        secret=settings.callback_secret,
        timeout_seconds=settings.callback_timeout_seconds,
        retry_attempts=settings.callback_retry_attempts,
    )
    with LaravelCallbackClient(callback_settings) as client:
        return client.send(event)


__all__ = ["report_status"]
