"""Small deterministic retry-delay policy used by worker adapters."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class RetryDelays:
    initial_seconds: float = 1.0
    maximum_seconds: float = 10.0

    def for_attempt(self, attempt: int) -> float:
        if attempt < 1:
            raise ValueError("Retry attempt must be at least one.")
        return min(self.initial_seconds * (2 ** (attempt - 1)), self.maximum_seconds)


__all__ = ["RetryDelays"]
