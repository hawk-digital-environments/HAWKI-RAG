"""Environment-backed settings for Temporal RAG workers."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import timedelta
import os


def _env(name: str, default: str) -> str:
    value = os.environ.get(name)
    return value.strip() if isinstance(value, str) and value.strip() else default


def _env_int(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


def _env_float(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return float(raw)
    except ValueError:
        return default


@dataclass(frozen=True, slots=True)
class TemporalRagSettings:
    temporal_address: str
    temporal_namespace: str
    workflow_type: str
    workflow_task_queue: str
    scraper_task_queue: str
    converter_task_queue: str
    ingestion_task_queue: str
    workflow_execution_timeout_seconds: int
    workflow_run_timeout_seconds: int
    workflow_task_timeout_seconds: int
    scraper_url: str
    scraper_start_path: str
    scraper_status_path: str
    scraper_token: str
    converter_url: str
    converter_start_path: str
    converter_status_path: str
    converter_token: str
    bridge_url: str
    request_timeout_seconds: float
    poll_interval_seconds: float
    poll_timeout_seconds: float
    http_retry_attempts: int
    activity_worker_threads: int
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str

    @classmethod
    def from_env(cls) -> "TemporalRagSettings":
        return cls(
            temporal_address=_env("TEMPORAL_ADDRESS", "temporal:7233"),
            temporal_namespace=_env("TEMPORAL_NAMESPACE", "default"),
            workflow_type=_env("TEMPORAL_INGEST_WORKFLOW_TYPE", "IngestSourceWorkflow"),
            workflow_task_queue=_env("TEMPORAL_RAG_WORKFLOW_TASK_QUEUE", "rag-workflow-task-queue"),
            scraper_task_queue=_env("TEMPORAL_RAG_SCRAPER_TASK_QUEUE", "rag-scraper-task-queue"),
            converter_task_queue=_env("TEMPORAL_RAG_CONVERTER_TASK_QUEUE", "rag-converter-task-queue"),
            ingestion_task_queue=_env("TEMPORAL_RAG_INGESTION_TASK_QUEUE", "rag-ingestion-task-queue"),
            workflow_execution_timeout_seconds=_duration_seconds("TEMPORAL_WORKFLOW_EXECUTION_TIMEOUT", 172800),
            workflow_run_timeout_seconds=_duration_seconds("TEMPORAL_WORKFLOW_RUN_TIMEOUT", 86400),
            workflow_task_timeout_seconds=_duration_seconds("TEMPORAL_WORKFLOW_TASK_TIMEOUT", 30),
            scraper_url=_env("EXTERNAL_SCRAPER_URL", _env("CUSTOM_CRAWLER_URL", "http://crawler:8000")),
            scraper_start_path=_env("EXTERNAL_SCRAPER_START_PATH", "/api/scrape/start"),
            scraper_status_path=_env("EXTERNAL_SCRAPER_STATUS_PATH", "/api/scrape/jobs/{job_id}"),
            scraper_token=_env("EXTERNAL_SCRAPER_TOKEN", _env("CUSTOM_CRAWLER_API_KEY", "")),
            converter_url=_env("EXTERNAL_CONVERTER_URL", _env("FILE_CONVERTER_BASE_URL", "http://file-converter:8000")),
            converter_start_path=_env("EXTERNAL_CONVERTER_START_PATH", "/extract"),
            converter_status_path=_env("EXTERNAL_CONVERTER_STATUS_PATH", ""),
            converter_token=_env("EXTERNAL_CONVERTER_TOKEN", _env("FILE_CONVERTER_TOKEN", "")),
            bridge_url=_env("HAWKI_RAG_BRIDGE_URL", "http://hawki_rag_bridge:8000"),
            request_timeout_seconds=_env_float("TEMPORAL_RAG_HTTP_TIMEOUT_SECONDS", 1800.0),
            poll_interval_seconds=_env_float("TEMPORAL_RAG_EXTERNAL_POLL_INTERVAL_SECONDS", 5.0),
            poll_timeout_seconds=_env_float("TEMPORAL_RAG_EXTERNAL_POLL_TIMEOUT_SECONDS", 43200.0),
            http_retry_attempts=_env_int("TEMPORAL_RAG_HTTP_RETRY_ATTEMPTS", 3),
            activity_worker_threads=max(1, _env_int("TEMPORAL_RAG_ACTIVITY_WORKER_THREADS", 4)),
            db_host=_env("DB_HOST", "postgres"),
            db_port=_env_int("DB_PORT", 5432),
            db_name=_env("DB_DATABASE", "hawki_rag"),
            db_user=_env("DB_USERNAME", "rag_user"),
            db_password=_env("DB_PASSWORD", ""),
        )

    @property
    def workflow_execution_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_execution_timeout_seconds)

    @property
    def workflow_run_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_run_timeout_seconds)

    @property
    def workflow_task_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_task_timeout_seconds)

    def cron_for_cadence(self, cadence: str) -> str:
        key = cadence.strip().lower()
        if key == "daily":
            return _env("TEMPORAL_RAG_DAILY_CRON", "0 2 * * *")
        if key == "weekly":
            return _env("TEMPORAL_RAG_WEEKLY_CRON", "0 2 * * 0")
        if key == "monthly":
            return _env("TEMPORAL_RAG_MONTHLY_CRON", "0 2 1 * *")
        raise ValueError(f"Unsupported Temporal refresh cadence [{cadence}].")


def _duration_seconds(name: str, default: int) -> int:
    raw = _env(name, str(default)).lower().strip()
    parts = raw.split()
    if len(parts) == 2 and parts[0].isdigit():
        value = int(parts[0])
        unit = parts[1].rstrip("s")
        if unit == "second":
            return value
        if unit == "minute":
            return value * 60
        if unit == "hour":
            return value * 3600
        if unit == "day":
            return value * 86400
    try:
        return int(raw)
    except ValueError:
        return default


__all__ = ["TemporalRagSettings"]
