"""Canonical status vocabulary for external start/status job APIs."""

from __future__ import annotations

_SUCCESS_STATUSES = frozenset(
    {"completed", "complete", "succeeded", "success", "done", "ready"}
)
_FAILED_STATUSES = frozenset({"failed", "error", "timeout", "cancelled", "canceled"})


def normalize_external_job_status(value: object) -> str:
    """Map external terminal-state synonyms while preserving unknown states."""

    status = str(value or "running").strip().lower()
    if status in _SUCCESS_STATUSES:
        return "success"
    if status in _FAILED_STATUSES:
        return "failed"
    return status


__all__ = ["normalize_external_job_status"]
