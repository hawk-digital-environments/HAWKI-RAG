"""Shared worker connection settings; business queues remain service-owned."""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class WorkerRuntimeSettings:
    temporal_address: str
    temporal_namespace: str
    activity_worker_threads: int
    laravel_callback_url: str
    laravel_callback_secret: str
    laravel_callback_timeout_seconds: float
    laravel_callback_retry_attempts: int


def load_worker_runtime_settings() -> WorkerRuntimeSettings:
    return WorkerRuntimeSettings(
        temporal_address=os.getenv("TEMPORAL_ADDRESS", "temporal:7233").strip(),
        temporal_namespace=os.getenv("TEMPORAL_NAMESPACE", "default").strip(),
        activity_worker_threads=int(
            os.getenv("TEMPORAL_RAG_ACTIVITY_WORKER_THREADS", "4")
        ),
        laravel_callback_url=os.getenv("HAWKI_RAG_WORKER_CALLBACK_URL", "").strip(),
        laravel_callback_secret=os.getenv("HAWKI_RAG_WORKER_CALLBACK_SECRET", ""),
        laravel_callback_timeout_seconds=float(
            os.getenv("HAWKI_RAG_WORKER_CALLBACK_TIMEOUT_SECONDS", "10")
        ),
        laravel_callback_retry_attempts=int(
            os.getenv("HAWKI_RAG_WORKER_CALLBACK_RETRY_ATTEMPTS", "3")
        ),
    )


__all__ = ["WorkerRuntimeSettings", "load_worker_runtime_settings"]
