"""Temporal entrypoint for the scraper activity."""

from __future__ import annotations

import logging
from typing import Any

from temporalio import activity

from hawki_rag_contracts.artifacts import RawArtifact
from hawki_rag_contracts.ingestion import ScrapeResult
from hawki_rag_contracts.temporal import SCRAPE_SOURCE_ACTIVITY
from hawki_worker_runtime.logging import log_event

from hawki_scraper_worker.adapters.status_callback import (
    ActivityExecution,
    ScraperStatusReporter,
)
from hawki_scraper_worker.scraping.service import ScraperService
from hawki_scraper_worker.settings import ScraperWorkerSettings


logger = logging.getLogger(__name__)


@activity.defn(name=SCRAPE_SOURCE_ACTIVITY)
def scrape_source(workflow_input: dict[str, Any]) -> dict[str, Any]:
    """Run one scrape activity with production adapters."""

    settings = ScraperWorkerSettings.from_environment()
    reporter = ScraperStatusReporter.from_settings(settings.callback)
    try:
        return run_scrape_activity(
            workflow_input,
            service=ScraperService(settings),
            reporter=reporter,
            activity_info=activity.info(),
            heartbeat_sender=activity.heartbeat,
            task_queue=settings.task_queue,
        )
    finally:
        reporter.close()


def run_scrape_activity(
    workflow_input: dict[str, Any],
    *,
    service: ScraperService,
    reporter: ScraperStatusReporter,
    activity_info: object,
    heartbeat_sender,
    task_queue: str,
) -> dict[str, Any]:
    """Testable scrape activity orchestration around injected adapters."""

    source_id = _required_string(workflow_input, "source_id")
    raw_dir = _required_string(workflow_input, "raw_output_path")
    execution = activity_execution(activity_info)
    resume_job_id = heartbeat_external_job_id(
        getattr(activity_info, "heartbeat_details", ())
    )

    reporter.report_running(workflow_input, execution, raw_dir=raw_dir)
    log_event(
        logger,
        "scrape_source:start",
        source_id=source_id,
        raw_dir=raw_dir,
        task_queue=task_queue,
        attempt=execution.attempt,
    )

    try:
        service_result = service.scrape(
            workflow_input,
            resume_external_job_id=resume_job_id,
            progress_callback=lambda job_id: heartbeat_sender(
                {"external_job_id": job_id}
            ),
        )
        raw_dir_result = str(service_result.get("raw_dir") or raw_dir)
        result = ScrapeResult.model_validate(
            {
                **service_result,
                "source_id": source_id,
                "raw_dir": raw_dir_result,
                "artifacts": [
                    RawArtifact(
                        uri=raw_dir_result,
                        media_type="inode/directory",
                        source_id=source_id,
                        source_url=str(workflow_input.get("source_url") or "") or None,
                    )
                ],
            }
        ).model_dump(mode="json")
    except Exception as exc:
        reporter.report_exception(workflow_input, execution, exc)
        log_event(
            logger,
            "scrape_source:error",
            source_id=source_id,
            raw_dir=raw_dir,
            task_queue=task_queue,
            error_type=type(exc).__name__,
        )
        raise

    reporter.report_result(workflow_input, execution, result)
    log_event(
        logger,
        "scrape_source:end",
        source_id=source_id,
        raw_dir=result.get("raw_dir") or raw_dir,
        status=result.get("status"),
        files_found=result.get("files_found"),
        pages_crawled=result.get("pages_crawled"),
        task_queue=task_queue,
    )
    return result


def heartbeat_external_job_id(details: object) -> str | None:
    """Recover the crawler job recorded by a previous activity attempt."""

    if not isinstance(details, (list, tuple)):
        return None
    for detail in details:
        if isinstance(detail, str) and detail.strip():
            return detail.strip()
        if isinstance(detail, dict):
            job_id = detail.get("external_job_id")
            if isinstance(job_id, (str, int)) and str(job_id).strip():
                return str(job_id).strip()
    return None


def activity_execution(info: object) -> ActivityExecution:
    """Extract the stable Temporal identity used for callback idempotency."""

    return ActivityExecution(
        workflow_id=_required_attribute(info, "workflow_id"),
        run_id=_required_attribute(info, "workflow_run_id"),
        temporal_activity_id=_required_attribute(info, "activity_id"),
        attempt=max(1, int(getattr(info, "attempt", 1))),
    )


def _required_attribute(value: object, name: str) -> str:
    attribute = getattr(value, name, None)
    if not isinstance(attribute, (str, int)) or not str(attribute).strip():
        raise RuntimeError(f"Temporal activity info is missing {name}.")
    return str(attribute).strip()


def _required_string(payload: dict[str, Any], key: str) -> str:
    value = payload.get(key)
    if not isinstance(value, (str, int)) or not str(value).strip():
        raise ValueError(f"{key} is required for scrape_source")
    return str(value).strip()


__all__ = [
    "activity_execution",
    "heartbeat_external_job_id",
    "run_scrape_activity",
    "scrape_source",
]
