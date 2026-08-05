"""Small Temporal worker runtime primitives without service-owned settings."""

from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor


def create_activity_executor(
    max_workers: int,
    *,
    thread_name_prefix: str = "hawki-rag-activity",
) -> ThreadPoolExecutor:
    """Create the executor required by synchronous Temporal activities."""

    if max_workers < 1:
        raise ValueError("Activity worker thread count must be at least one.")
    return ThreadPoolExecutor(
        max_workers=max_workers,
        thread_name_prefix=thread_name_prefix,
    )


__all__ = ["create_activity_executor"]
