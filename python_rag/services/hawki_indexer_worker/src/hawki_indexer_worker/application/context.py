"""Typed execution context shared by indexer application workflows."""

from __future__ import annotations

from collections.abc import Callable
from typing import Any, Protocol


class ActivityExecutionInfo(Protocol):
    """Temporal identifiers required when reporting activity status."""

    workflow_id: str
    workflow_run_id: str
    attempt: int


StatusReporter = Callable[..., dict[str, Any]]


__all__ = ["ActivityExecutionInfo", "StatusReporter"]
