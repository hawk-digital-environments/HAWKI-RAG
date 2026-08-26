"""Narrow environment-backed settings for the scraper worker."""

from __future__ import annotations

from dataclasses import dataclass
import os

from hawki_pipeline_callbacks import LaravelCallbackSettings
from hawki_rag_contracts.temporal import SCRAPER_TASK_QUEUE
from hawki_worker_runtime.settings import (
    WorkerRuntimeSettings,
    load_worker_runtime_settings,
)


def _environment_value(name: str, default: str) -> str:
    value = os.environ.get(name)
    if isinstance(value, str) and value.strip():
        return value.strip()
    return default


def _environment_int(name: str, default: int) -> int:
    try:
        return int(_environment_value(name, str(default)))
    except ValueError:
        return default


def _environment_float(name: str, default: float) -> float:
    try:
        return float(_environment_value(name, str(default)))
    except ValueError:
        return default


@dataclass(frozen=True, slots=True)
class ScraperWorkerSettings:
    """All and only settings required by the scraper worker."""

    runtime: WorkerRuntimeSettings
    callback: LaravelCallbackSettings
    task_queue: str
    scraper_url: str
    scraper_start_path: str
    scraper_status_path: str
    scraper_token: str
    request_timeout_seconds: float
    poll_interval_seconds: float
    poll_timeout_seconds: float
    http_retry_attempts: int

    @classmethod
    def from_environment(cls) -> "ScraperWorkerSettings":
        """Load and validate settings before the worker starts polling."""

        runtime = load_worker_runtime_settings()
        callback = LaravelCallbackSettings(
            endpoint=_environment_value("HAWKI_RAG_WORKER_CALLBACK_URL", ""),
            secret=os.environ.get("HAWKI_RAG_WORKER_CALLBACK_SECRET", ""),
            timeout_seconds=max(
                0.1,
                _environment_float("HAWKI_RAG_WORKER_CALLBACK_TIMEOUT_SECONDS", 10.0),
            ),
            retry_attempts=max(
                1,
                _environment_int("HAWKI_RAG_WORKER_CALLBACK_RETRY_ATTEMPTS", 3),
            ),
        )
        return cls(
            runtime=runtime,
            callback=callback,
            task_queue=_environment_value(
                "TEMPORAL_RAG_SCRAPER_TASK_QUEUE",
                SCRAPER_TASK_QUEUE,
            ),
            scraper_url=_environment_value(
                "EXTERNAL_SCRAPER_URL",
                _environment_value("CUSTOM_CRAWLER_URL", "http://crawl4ai-service"),
            ),
            scraper_start_path=_environment_value(
                "EXTERNAL_SCRAPER_START_PATH", "/crawl"
            ),
            scraper_status_path=_environment_value(
                "EXTERNAL_SCRAPER_STATUS_PATH",
                "/status/{job_id}",
            ),
            scraper_token=_environment_value(
                "EXTERNAL_SCRAPER_TOKEN",
                _environment_value("CUSTOM_CRAWLER_API_KEY", ""),
            ),
            request_timeout_seconds=max(
                0.1,
                _environment_float("TEMPORAL_RAG_HTTP_TIMEOUT_SECONDS", 1800.0),
            ),
            poll_interval_seconds=max(
                0.5,
                _environment_float("TEMPORAL_RAG_EXTERNAL_POLL_INTERVAL_SECONDS", 5.0),
            ),
            poll_timeout_seconds=max(
                0.5,
                _environment_float(
                    "TEMPORAL_RAG_EXTERNAL_POLL_TIMEOUT_SECONDS", 43200.0
                ),
            ),
            http_retry_attempts=max(
                1,
                _environment_int("TEMPORAL_RAG_HTTP_RETRY_ATTEMPTS", 3),
            ),
        )


__all__ = ["ScraperWorkerSettings"]
