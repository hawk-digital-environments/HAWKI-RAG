"""Shared worker connection settings; business queues remain service-owned."""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class WorkerRuntimeSettings:
    temporal_address: str
    temporal_namespace: str
    activity_worker_threads: int


def load_worker_runtime_settings() -> WorkerRuntimeSettings:
    return WorkerRuntimeSettings(
        temporal_address=os.getenv("TEMPORAL_ADDRESS", "temporal:7233").strip(),
        temporal_namespace=os.getenv("TEMPORAL_NAMESPACE", "default").strip(),
        activity_worker_threads=int(
            os.getenv("TEMPORAL_RAG_ACTIVITY_WORKER_THREADS", "4")
        ),
    )


__all__ = ["WorkerRuntimeSettings", "load_worker_runtime_settings"]
