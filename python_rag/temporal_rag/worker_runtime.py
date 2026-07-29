"""Shared Temporal worker runtime helpers."""

from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor

from temporal_rag.settings import TemporalRagSettings


def create_activity_executor(settings: TemporalRagSettings) -> ThreadPoolExecutor:
    """Create the executor required for synchronous Temporal activities."""
    return ThreadPoolExecutor(
        max_workers=settings.activity_worker_threads,
        thread_name_prefix="temporal-rag-activity",
    )


__all__ = ["create_activity_executor"]
