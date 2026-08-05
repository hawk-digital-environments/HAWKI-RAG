"""Environment settings owned by the indexer activity worker."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from hawki_rag_contracts.temporal import LEGACY_INGESTION_TASK_QUEUE


def _env(name: str, default: str) -> str:
    value = os.getenv(name)
    return value.strip() if value and value.strip() else default


def _positive_int(name: str, default: int) -> int:
    try:
        return max(1, int(_env(name, str(default))))
    except ValueError as exc:
        raise RuntimeError(f"{name} must be an integer") from exc


@dataclass(frozen=True, slots=True)
class IndexerSettings:
    temporal_address: str
    temporal_namespace: str
    task_queue: str
    activity_worker_threads: int
    callback_url: str
    callback_secret: str
    callback_timeout_seconds: float
    callback_retry_attempts: int
    rag_working_dir: Path
    legacy_task_queue: str = LEGACY_INGESTION_TASK_QUEUE

    @classmethod
    def from_env(cls) -> "IndexerSettings":
        legacy_queue = _env(
            "TEMPORAL_RAG_INGESTION_TASK_QUEUE",
            LEGACY_INGESTION_TASK_QUEUE,
        )
        settings = cls(
            temporal_address=_env("TEMPORAL_ADDRESS", "temporal:7233"),
            temporal_namespace=_env("TEMPORAL_NAMESPACE", "default"),
            task_queue=_env("TEMPORAL_RAG_INDEXER_TASK_QUEUE", legacy_queue),
            activity_worker_threads=_positive_int(
                "TEMPORAL_RAG_ACTIVITY_WORKER_THREADS", 2
            ),
            callback_url=_env(
                "HAWKI_RAG_WORKER_CALLBACK_URL",
                "http://hawki_rag_app/api/internal/pipeline/worker-events",
            ),
            callback_secret=os.getenv("HAWKI_RAG_WORKER_CALLBACK_SECRET", ""),
            callback_timeout_seconds=float(
                _env("HAWKI_RAG_WORKER_CALLBACK_TIMEOUT_SECONDS", "10")
            ),
            callback_retry_attempts=_positive_int(
                "HAWKI_RAG_WORKER_CALLBACK_RETRY_ATTEMPTS", 3
            ),
            rag_working_dir=Path(_env("RAG_WORKING_DIR", "/app/rag_storage")),
            legacy_task_queue=legacy_queue,
        )
        if not settings.callback_secret:
            raise RuntimeError(
                "HAWKI_RAG_WORKER_CALLBACK_SECRET is required for indexer status events."
            )
        if settings.callback_timeout_seconds <= 0:
            raise RuntimeError(
                "HAWKI_RAG_WORKER_CALLBACK_TIMEOUT_SECONDS must be positive"
            )
        return settings


__all__ = ["IndexerSettings"]
