from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _int_env(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


@dataclass(frozen=True)
class WorkerSettings:
    rabbitmq_url: str
    exchange: str
    retry_exchange: str
    failed_exchange: str
    main_queue: str
    retry_queue: str
    failed_queue: str
    main_routing_key: str
    retry_routing_key: str
    failed_routing_key: str
    queue_type: str
    retry_queue_type: str
    retry_delay_ms: int
    prefetch_count: int
    max_retries: int
    shutdown_grace_seconds: int
    idempotency_db_path: str

    @classmethod
    def from_env(cls) -> "WorkerSettings":
        project_root = Path(__file__).resolve().parents[2]
        default_db = project_root / "storage" / "app" / "private" / "rag_worker_jobs.sqlite"

        return cls(
            rabbitmq_url=os.environ.get("RABBITMQ_URL", "amqp://guest:guest@localhost/"),
            exchange=os.environ.get("RABBITMQ_EXCHANGE", "hawki.ingest"),
            retry_exchange=os.environ.get("RABBITMQ_RETRY_EXCHANGE", "hawki.ingest.retry"),
            failed_exchange=os.environ.get("RABBITMQ_FAILED_EXCHANGE", "hawki.ingest.failed"),
            main_queue=os.environ.get("RABBITMQ_MAIN_QUEUE", "hawki.ingest.main"),
            retry_queue=os.environ.get("RABBITMQ_RETRY_QUEUE", "hawki.ingest.retry"),
            failed_queue=os.environ.get("RABBITMQ_FAILED_QUEUE", "hawki.ingest.failed"),
            main_routing_key=os.environ.get("RABBITMQ_MAIN_ROUTING_KEY", "hawki.ingest.main"),
            retry_routing_key=os.environ.get("RABBITMQ_RETRY_ROUTING_KEY", "hawki.ingest.retry"),
            failed_routing_key=os.environ.get("RABBITMQ_FAILED_ROUTING_KEY", "hawki.ingest.failed"),
            queue_type=os.environ.get("RABBITMQ_QUEUE_TYPE", "classic").strip().lower(),
            retry_queue_type=os.environ.get("RABBITMQ_RETRY_QUEUE_TYPE", "classic").strip().lower(),
            retry_delay_ms=max(1, _int_env("RABBITMQ_RETRY_DELAY_MS", 30000)),
            prefetch_count=max(1, _int_env("RABBITMQ_PREFETCH_COUNT", 4)),
            max_retries=max(0, _int_env("RABBITMQ_MAX_RETRIES", 3)),
            shutdown_grace_seconds=max(1, _int_env("RABBITMQ_SHUTDOWN_GRACE_SECONDS", 30)),
            idempotency_db_path=os.environ.get("RABBITMQ_JOB_DB_PATH", str(default_db)),
        )
