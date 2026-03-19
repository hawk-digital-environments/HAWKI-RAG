from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(slots=True)
class Reranker:
    """Local reranker helper kept for simpler offline reorder experiments."""
    config: dict[str, Any]

    def rerank(self, query: str, hits: list[dict[str, Any]]) -> list[dict[str, Any]]:
        """Apply a lightweight heuristic rerank while preserving reranker-enabled fairness."""
        if not self.config.get("enabled", False):
            raise RuntimeError("Reranker must remain enabled for reproducibility.")

        mode = self.config.get("mode", "log_only")
        if mode == "heuristic":
            query_terms = {term.lower() for term in query.split() if term.strip()}
            return sorted(
                hits,
                key=lambda hit: (
                    -sum(
                        1
                        for term in query_terms
                        if term in str(hit.get("payload", {}).get("text", "")).lower()
                    ),
                    -float(hit.get("score", 0.0)),
                ),
            )
        return hits
