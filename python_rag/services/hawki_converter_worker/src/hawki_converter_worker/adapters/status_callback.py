"""Build converter-owned status events and send them through the shared client."""

from __future__ import annotations

import hashlib
from datetime import datetime, timezone
from typing import Any

from temporalio import activity

from hawki_rag_contracts.artifacts import ArtifactReference
from hawki_rag_contracts.status import (
    PipelineStage,
    PipelineStageStatus,
    PipelineWorkerEvent,
    StatusCounts,
    StatusError,
    WorkerProducer,
)
from hawki_rag_contracts.temporal import CONVERT_FILES_ACTIVITY
from hawki_rag_resilience.redaction import sanitize_for_log
from hawki_worker_runtime.callbacks import (
    LaravelCallbackClient,
    LaravelCallbackSettings,
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
) -> dict[str, Any]:
    info = activity.info()
    workflow_id = str(info.workflow_id)
    run_id = str(info.workflow_run_id)
    attempt = int(info.attempt)
    event_key = "|".join(
        [workflow_id, run_id, CONVERT_FILES_ACTIVITY, str(attempt), status.value]
    )
    safe_error = sanitize_for_log(error) if error is not None else None
    event = PipelineWorkerEvent(
        event_id="evt_" + hashlib.sha256(event_key.encode("utf-8")).hexdigest(),
        producer=WorkerProducer.CONVERTER,
        occurred_at=datetime.now(timezone.utc),
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
    callback_settings = LaravelCallbackSettings(
        endpoint=settings.callback_url,
        secret=settings.callback_secret,
        timeout_seconds=settings.callback_timeout_seconds,
        retry_attempts=settings.callback_retry_attempts,
    )
    with LaravelCallbackClient(callback_settings) as client:
        return client.send(event)


__all__ = ["report_status"]
