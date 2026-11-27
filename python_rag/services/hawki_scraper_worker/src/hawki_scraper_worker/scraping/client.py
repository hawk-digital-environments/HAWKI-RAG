"""Compose the shared external-job client for the scraper service."""

from __future__ import annotations

from hashlib import sha256
from typing import Any
from urllib.parse import urlsplit

from hawki_external_jobs import ExternalJobClient
from hawki_external_jobs.crawler import CustomCrawlerClient

from hawki_scraper_worker.settings import ScraperWorkerSettings


def crawler_endpoint_id(url: str) -> str:
    parts = urlsplit(url)
    if (
        parts.scheme not in {"http", "https"}
        or not parts.hostname
        or parts.username is not None
        or parts.password is not None
        or parts.query
        or parts.fragment
    ):
        raise ValueError("The scraper worker requires a server-owned HTTP endpoint.")
    return sha256(url.rstrip("/").encode()).hexdigest()


def create_scraper_client(
    workflow_input: dict[str, Any],
    settings: ScraperWorkerSettings,
) -> ExternalJobClient:
    """Create a crawler client while preserving per-workflow overrides."""

    external = workflow_input.get("external_services")
    overrides = dict(external) if isinstance(external, dict) else {}
    owned = workflow_input.get("owner") == "url"
    base_url = str(overrides.get("scraper_url") or settings.scraper_url).rstrip("/")
    token = str(overrides.get("scraper_token") or settings.scraper_token)
    if owned:
        endpoint_id = crawler_endpoint_id(base_url)
        configuration = workflow_input.get("metadata", {}).get(
            "scraper_configuration", {}
        )
        if configuration.get("endpoint_id", endpoint_id) != endpoint_id:
            raise ValueError("The scraper worker endpoint differs from the owned job.")
        if overrides.get("scraper_url"):
            # Use the frozen credential even when the old crawler used no token.
            token = str(overrides.get("scraper_token") or "")
    client_type = CustomCrawlerClient if owned else ExternalJobClient
    return client_type(
        **({"workflow_input": workflow_input} if owned else {}),
        base_url=base_url,
        start_path=str(
            "/crawl"
            if owned
            else overrides.get("scraper_start_path") or settings.scraper_start_path
        ),
        status_path=str(
            "/status/{job_id}"
            if owned
            else overrides.get("scraper_status_path") or settings.scraper_status_path
        ),
        token=token,
        timeout_seconds=settings.request_timeout_seconds,
        retry_attempts=settings.http_retry_attempts,
        poll_interval_seconds=settings.poll_interval_seconds,
        poll_timeout_seconds=settings.poll_timeout_seconds,
    )


__all__ = ["create_scraper_client"]
