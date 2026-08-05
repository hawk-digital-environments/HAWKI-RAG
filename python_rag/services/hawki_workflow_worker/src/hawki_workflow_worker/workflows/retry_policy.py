"""Deterministic retry policies for ingestion workflow activities."""

from __future__ import annotations

from datetime import timedelta

from temporalio.common import RetryPolicy


def ingestion_activity_retry_policy() -> RetryPolicy:
    """Return the existing bounded activity retry policy."""

    return RetryPolicy(
        initial_interval=timedelta(seconds=5),
        backoff_coefficient=2.0,
        maximum_interval=timedelta(minutes=5),
        maximum_attempts=5,
    )


__all__ = ["ingestion_activity_retry_policy"]
