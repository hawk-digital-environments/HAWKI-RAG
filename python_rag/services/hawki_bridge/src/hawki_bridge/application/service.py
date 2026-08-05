"""Read-only query facade used by the bridge HTTP layer."""

from __future__ import annotations

from typing import Any

from hawki_model_providers.factory import get_provider

from hawki_bridge.adapters.reranker_client import rerank_hits


class QueryService:
    def get_provider(self, name: str) -> Any:
        return get_provider(name)

    def rerank_hits(self, **kwargs: Any) -> list[dict[str, Any]]:
        return rerank_hits(**kwargs)

    @staticmethod
    def runtime_summary() -> dict[str, str]:
        return {"role": "bridge", "mode": "read-only"}


__all__ = ["QueryService"]
