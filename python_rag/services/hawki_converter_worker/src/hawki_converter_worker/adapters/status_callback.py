"""Build converter-owned status events and send them through the shared client."""

from __future__ import annotations

from collections.abc import Callable
from datetime import datetime, timezone
from typing import Any

from temporalio import activity

from hawki_rag_contracts.pipeline.artifacts import ArtifactReference
from hawki_rag_contracts.pipeline.status import (
    PipelineStage,
    PipelineStageStatus,
    PipelineWorkerEvent,
    StatusCounts,
    StatusError,
    WorkerProducer,
)
from hawki_rag_contracts.pipeline.temporal import CONVERT_FILES_ACTIVITY
from hawki_observability.redaction import sanitize_for_log
from hawki_pipeline_callbacks import (
    CallbackSender,
    LaravelCallbackClient,
    LaravelCallbackSettings,
    deterministic_event_id,
)

from hawki_converter_worker.settings import ConverterSettings


def report_status(
    settings: ConverterSettings,
    workflow_input: dict[str, Any],
    *,
    status: PipelineStageStatus,
    markdown_dir: str,
    processed: int = 0,
    artifacts: list[ArtifactReference] | None = None,
    error: Exception | None = None,
    sender: CallbackSender | None = None,
    clock: Callable[[], datetime] | None = None,
) -> dict[str, Any]:
    info = activity.info()
    workflow_id = str(info.workflow_id)
    run_id = str(info.workflow_run_id)
    attempt = int(info.attempt)
    safe_error = sanitize_for_log(error) if error is not None else None
    event = PipelineWorkerEvent(
        event_id=deterministic_event_id(
            workflow_id=workflow_id,
            run_id=run_id,
            activity_id=CONVERT_FILES_ACTIVITY,
            attempt=attempt,
            status=status.value,
            prefix="evt_",
        ),
        producer=WorkerProducer.CONVERTER,
        occurred_at=(clock or _utc_now)(),
        workflow_id=workflow_id,
        run_id=run_id,
        activity_id=CONVERT_FILES_ACTIVITY,
        attempt=attempt,
        job_id=str(workflow_input["job_id"]),
        task_id=str(workflow_input["task_id"])
        if workflow_input.get("task_id")
        else None,
        source_id=str(workflow_input["source_id"]),
        stage=PipelineStage.CONVERT,
        phase=CONVERT_FILES_ACTIVITY,
        status=status,
        counts=StatusCounts(total=processed, processed=processed),
        artifacts=artifacts
        or [ArtifactReference(uri=markdown_dir, media_type="inode/directory")],
        errors=(
            [
                StatusError(
                    code=type(error).__name__[:120],
                    message=safe_error or type(error).__name__,
                    retryable=True,
                )
            ]
            if error is not None
            else []
        ),
        error_details=safe_error,
    )
    if sender is not None:
        return sender.send(event)
    with create_callback_sender(settings) as client:
        return client.send(event)


def create_callback_sender(settings: ConverterSettings) -> LaravelCallbackClient:
    """Create a reusable sender from converter-owned callback settings."""

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
