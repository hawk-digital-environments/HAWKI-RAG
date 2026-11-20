"""Translate scraper outcomes into signed Laravel callback events."""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass
from datetime import datetime, timezone
import re
from typing import Any

from hawki_rag_contracts.pipeline.artifacts import ArtifactReference
from hawki_rag_contracts.pipeline.status import (
    PipelineStage,
    PipelineStageStatus,
    PipelineWorkerEvent,
    StatusCounts,
    StatusError,
    WorkerProducer,
)
from hawki_rag_contracts.pipeline.temporal import SCRAPE_SOURCE_ACTIVITY
from hawki_observability.redaction import sanitize_for_log
from hawki_pipeline_callbacks import (
    CallbackSender,
    LaravelCallbackClient,
    LaravelCallbackSettings,
    deterministic_event_id,
)


@dataclass(frozen=True, slots=True)
class ActivityExecution:
    """Temporal execution identity required for idempotent callbacks."""

    workflow_id: str
    run_id: str
    temporal_activity_id: str
    attempt: int


class ScraperStatusReporter:
    """Build typed events and send Laravel-compatible signed payloads."""

    def __init__(
        self,
        sender: CallbackSender,
        *,
        clock: Callable[[], datetime] | None = None,
    ) -> None:
        self.sender = sender
        self.clock = clock or (lambda: datetime.now(timezone.utc))

    @classmethod
    def from_settings(
        cls, settings: LaravelCallbackSettings
    ) -> "ScraperStatusReporter":
        return cls(LaravelCallbackClient(settings))

    def close(self) -> None:
        self.sender.close()

    def report_running(
        self,
        workflow_input: dict[str, Any],
        execution: ActivityExecution,
        *,
        raw_dir: str,
    ) -> dict[str, Any]:
        total = _requested_page_limit(workflow_input)
        return self._send(
            workflow_input,
            execution,
            status=PipelineStageStatus.RUNNING,
            counts=StatusCounts(total=total),
            metrics={},
            raw_dir=raw_dir,
        )

    def report_result(
        self,
        workflow_input: dict[str, Any],
        execution: ActivityExecution,
        result: dict[str, Any],
    ) -> dict[str, Any]:
        pages = _nonnegative_int(
            result.get("pages_crawled") or result.get("files_found")
        )
        total = _nonnegative_int(result.get("max_pages")) or pages
        succeeded = result.get("status") == "success"
        error_message = (
            None
            if succeeded
            else _sanitize_error(result.get("error_details") or "Scrape failed")
        )
        return self._send(
            workflow_input,
            execution,
            status=(
                PipelineStageStatus.COMPLETED
                if succeeded
                else PipelineStageStatus.FAILED
            ),
            counts=StatusCounts(
                total=total,
                processed=pages,
                failed=0 if succeeded else 1,
            ),
            metrics={
                "pages_crawled": pages,
                "files_found": _nonnegative_int(result.get("files_found")),
            },
            raw_dir=str(
                result.get("raw_dir") or workflow_input.get("raw_output_path") or ""
            ),
            error_message=error_message,
        )

    def report_exception(
        self,
        workflow_input: dict[str, Any],
        execution: ActivityExecution,
        exc: Exception,
    ) -> dict[str, Any]:
        return self._send(
            workflow_input,
            execution,
            status=PipelineStageStatus.FAILED,
            counts=StatusCounts(total=_requested_page_limit(workflow_input), failed=1),
            metrics={},
            raw_dir=str(workflow_input.get("raw_output_path") or ""),
            error_message=_sanitize_error(exc),
            error_code=type(exc).__name__,
        )

    def _send(
        self,
        workflow_input: dict[str, Any],
        execution: ActivityExecution,
        *,
        status: PipelineStageStatus,
        counts: StatusCounts,
        metrics: dict[str, int],
        raw_dir: str,
        error_message: str | None = None,
        error_code: str = "scrape_failed",
    ) -> dict[str, Any]:
        source_id = _required_identity(workflow_input, "source_id")
        job_id = _required_identity(workflow_input, "job_id")
        task_id = _optional_identity(workflow_input, "task_id")
        errors = (
            [StatusError(code=error_code[:120], message=error_message, retryable=True)]
            if error_message
            else []
        )
        artifacts = (
            [ArtifactReference(uri=raw_dir, media_type="inode/directory")]
            if raw_dir.strip()
            else []
        )
        event = PipelineWorkerEvent(
            event_id=_event_id(execution, status),
            producer=WorkerProducer.SCRAPER,
            occurred_at=self.clock(),
            workflow_id=execution.workflow_id,
            run_id=execution.run_id,
            activity_id=SCRAPE_SOURCE_ACTIVITY,
            attempt=execution.attempt,
            job_id=job_id,
            task_id=task_id,
            source_id=source_id,
            stage=PipelineStage.SCRAPE,
            phase=SCRAPE_SOURCE_ACTIVITY,
            status=status,
            counts=counts,
            metrics=metrics,
            artifacts=artifacts,
            errors=errors,
            error_details=error_message,
        )
        return self.sender.send(event.model_dump(mode="json", by_alias=True))


def _event_id(execution: ActivityExecution, status: PipelineStageStatus) -> str:
    return deterministic_event_id(
        workflow_id=execution.workflow_id,
        run_id=execution.run_id,
        activity_id=execution.temporal_activity_id,
        attempt=execution.attempt,
        status=status.value,
        prefix="scraper.",
    )


def _required_identity(payload: dict[str, Any], key: str) -> str:
    value = payload.get(key)
    if not isinstance(value, (str, int)) or not str(value).strip():
        raise ValueError(f"{key} is required for signed worker callbacks")
    return str(value).strip()


def _optional_identity(payload: dict[str, Any], key: str) -> str | None:
    value = payload.get(key)
    if value is None:
        return None
    if not isinstance(value, (str, int)) or not str(value).strip():
        raise ValueError(f"{key} must be a non-empty identifier when present")
    return str(value).strip()


def _requested_page_limit(workflow_input: dict[str, Any]) -> int:
    metadata = workflow_input.get("metadata")
    request = workflow_input.get("request")
    if not isinstance(request, dict) and isinstance(metadata, dict):
        request = metadata.get("request")
    request_metadata = request.get("metadata") if isinstance(request, dict) else None
    if not isinstance(request_metadata, dict):
        return 0
    return _nonnegative_int(request_metadata.get("max_pages"))


def _nonnegative_int(value: object) -> int:
    if isinstance(value, bool):
        return 0
    try:
        return max(0, int(value or 0))
    except (TypeError, ValueError):
        return 0


def _sanitize_error(value: object) -> str:
    """Remove bearer credentials before applying shared log redaction."""

    without_authorization = re.sub(
        r"(?i)(authorization\s*[:=]\s*)(?:bearer\s+)?\S+",
        r"\1<redacted>",
        str(value),
    )
    return sanitize_for_log(without_authorization)


__all__ = ["ActivityExecution", "CallbackSender", "ScraperStatusReporter"]
