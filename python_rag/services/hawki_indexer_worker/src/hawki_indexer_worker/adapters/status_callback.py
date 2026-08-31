"""Build indexer-owned status events and send them to Laravel."""

from __future__ import annotations

from collections.abc import Callable
from datetime import datetime, timezone
from typing import Any

from temporalio import activity

from hawki_rag_contracts.pipeline.artifacts import ArtifactReference
from hawki_rag_contracts.pipeline.status import (
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
    CallbackSender,
    LaravelCallbackClient,
    LaravelCallbackSettings,
    deterministic_event_id,
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
    sender: CallbackSender | None = None,
    clock: Callable[[], datetime] | None = None,
) -> dict[str, Any]:
    info = activity_info or activity.info()
    workflow_id = str(info.workflow_id)
    run_id = str(info.workflow_run_id)
    attempt = int(info.attempt)
    safe_error = sanitize_for_log(error) if error is not None else None
    event = PipelineWorkerEvent(
        event_id=deterministic_event_id(
            workflow_id=workflow_id,
            run_id=run_id,
            activity_id=activity_id,
            attempt=attempt,
            status=status.value,
            prefix="evt_",
        ),
        producer=WorkerProducer.INDEXER,
        occurred_at=(clock or _utc_now)(),
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
    if sender is not None:
        return sender.send(event)
    with create_callback_sender(settings) as client:
        return client.send(event)


def create_callback_sender(settings: IndexerSettings) -> LaravelCallbackClient:
    """Create a reusable sender from indexer-owned callback settings."""

    callback_settings = LaravelCallbackSettings(
        endpoint=settings.callback_url,
        secret=settings.callback_secret,
        timeout_seconds=settings.callback_timeout_seconds,
        retry_attempts=settings.callback_retry_attempts,
    )
    return LaravelCallbackClient(callback_settings)


def _utc_now() -> datetime:
    return datetime.now(timezone.utc)


__all__ = ["create_callback_sender", "report_status"]
