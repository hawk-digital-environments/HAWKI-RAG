"""Small domain values consumed by the indexing workflow."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(slots=True)
class IngestDocument:
    """One normalized document supplied to the in-process indexer."""

    id: str | int
    text: str
    payload: dict[str, Any] = field(default_factory=dict)


__all__ = ["IngestDocument"]
