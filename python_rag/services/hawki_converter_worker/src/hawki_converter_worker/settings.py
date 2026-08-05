"""Narrow environment settings for the converter activity worker."""

from __future__ import annotations

import os
from dataclasses import dataclass

from hawki_rag_contracts.temporal import CONVERTER_TASK_QUEUE


def _env(name: str, default: str) -> str:
    value = os.getenv(name)
    return value.strip() if value and value.strip() else default


@dataclass(frozen=True, slots=True)
class ConverterSettings:
    temporal_address: str
    temporal_namespace: str
    task_queue: str
    activity_worker_threads: int
    converter_url: str
    converter_start_path: str
    converter_status_path: str
    converter_token: str
    request_timeout_seconds: float
    poll_interval_seconds: float
    poll_timeout_seconds: float
    http_retry_attempts: int
    callback_url: str
    callback_secret: str
    callback_timeout_seconds: float
    callback_retry_attempts: int

    @classmethod
    def from_env(cls) -> ConverterSettings:
        settings = cls(
            temporal_address=_env("TEMPORAL_ADDRESS", "temporal:7233"),
            temporal_namespace=_env("TEMPORAL_NAMESPACE", "default"),
            task_queue=_env("TEMPORAL_RAG_CONVERTER_TASK_QUEUE", CONVERTER_TASK_QUEUE),
            activity_worker_threads=max(
                1, int(_env("TEMPORAL_RAG_ACTIVITY_WORKER_THREADS", "4"))
            ),
            converter_url=_env(
                "EXTERNAL_CONVERTER_URL",
                _env("FILE_CONVERTER_BASE_URL", "http://file-converter:8000"),
            ),
            converter_start_path=_env("EXTERNAL_CONVERTER_START_PATH", "/extract"),
            converter_status_path=_env("EXTERNAL_CONVERTER_STATUS_PATH", ""),
            converter_token=_env(
                "EXTERNAL_CONVERTER_TOKEN",
                _env("FILE_CONVERTER_TOKEN", ""),
            ),
            request_timeout_seconds=float(
                _env("TEMPORAL_RAG_HTTP_TIMEOUT_SECONDS", "1800")
            ),
            poll_interval_seconds=float(
                _env("TEMPORAL_RAG_EXTERNAL_POLL_INTERVAL_SECONDS", "5")
            ),
            poll_timeout_seconds=float(
                _env("TEMPORAL_RAG_EXTERNAL_POLL_TIMEOUT_SECONDS", "43200")
            ),
            http_retry_attempts=max(
                1, int(_env("TEMPORAL_RAG_HTTP_RETRY_ATTEMPTS", "3"))
            ),
            callback_url=_env(
                "HAWKI_RAG_WORKER_CALLBACK_URL",
                "http://hawki_rag_app/api/internal/pipeline/worker-events",
            ),
            callback_secret=os.getenv("HAWKI_RAG_WORKER_CALLBACK_SECRET", ""),
            callback_timeout_seconds=float(
                _env("HAWKI_RAG_WORKER_CALLBACK_TIMEOUT_SECONDS", "10")
            ),
            callback_retry_attempts=max(
                1, int(_env("HAWKI_RAG_WORKER_CALLBACK_RETRY_ATTEMPTS", "3"))
            ),
        )
        if not settings.callback_secret:
            raise RuntimeError(
                "HAWKI_RAG_WORKER_CALLBACK_SECRET is required for converter status events."
            )
        if settings.callback_timeout_seconds <= 0:
            raise RuntimeError(
                "HAWKI_RAG_WORKER_CALLBACK_TIMEOUT_SECONDS must be positive."
            )
        return settings


__all__ = ["ConverterSettings"]
