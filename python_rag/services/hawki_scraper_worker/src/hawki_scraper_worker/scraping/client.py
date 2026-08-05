"""Compose the shared external-job client for the scraper service."""

from __future__ import annotations

from typing import Any

from hawki_worker_runtime.external_jobs import ExternalJobClient

from hawki_scraper_worker.settings import ScraperWorkerSettings


def create_scraper_client(
    workflow_input: dict[str, Any],
    settings: ScraperWorkerSettings,
) -> ExternalJobClient:
    """Create a crawler client while preserving per-workflow overrides."""

    external = workflow_input.get("external_services")
    overrides = dict(external) if isinstance(external, dict) else {}
    return ExternalJobClient(
        base_url=str(overrides.get("scraper_url") or settings.scraper_url),
        start_path=str(
            overrides.get("scraper_start_path") or settings.scraper_start_path
        ),
        status_path=str(
            overrides.get("scraper_status_path") or settings.scraper_status_path
        ),
        token=str(overrides.get("scraper_token") or settings.scraper_token),
        timeout_seconds=settings.request_timeout_seconds,
        retry_attempts=settings.http_retry_attempts,
        poll_interval_seconds=settings.poll_interval_seconds,
        poll_timeout_seconds=settings.poll_timeout_seconds,
    )


__all__ = ["create_scraper_client"]
