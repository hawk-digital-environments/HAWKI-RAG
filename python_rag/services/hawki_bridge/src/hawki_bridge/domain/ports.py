"""Bridge-owned dependency ports."""

from __future__ import annotations

from typing import Protocol

from hawki_model_providers.ports import ModelProvider, ProviderResolver


class GraphReader(Protocol):
    """Read-only graph operations required by the bridge application."""

    def fetch_related_terms(
        self,
        terms: list[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int,
    ) -> list[dict[str, str]]: ...


__all__ = ["GraphReader", "ModelProvider", "ProviderResolver"]
