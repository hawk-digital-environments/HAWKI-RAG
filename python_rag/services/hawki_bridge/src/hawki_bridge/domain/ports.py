"""Bridge-owned ports for model, vector, graph, and reranking dependencies."""

from __future__ import annotations

from collections.abc import Callable, Mapping
from typing import Any, Protocol


class ModelProvider(Protocol):
    """Model capabilities required by authorized query execution."""

    embed_model: str
    rag_model: str
    vision_model: str

    def embed(self, text: str) -> list[float]: ...

    def chat(
        self,
        system: str,
        messages: list[Mapping[str, object]],
        *,
        temperature: float | None = None,
    ) -> str: ...


class VectorSearchPort(Protocol):
    """Dataset-scoped vector operations required by query retrieval."""

    def select_scoped_collection(self, collection: str) -> None: ...

    def search_candidates(
        self,
        *,
        vector: list[float],
        top_k: int,
        filters: dict[str, Any] | None,
        query_terms: list[str],
        keyword_fields: list[str],
        smart_lookup: bool,
        fast_mode: bool,
        is_optimized: bool,
        preferred_tags: list[str] | None,
    ) -> list[dict[str, Any]]: ...

    def search_high_recall(
        self,
        *,
        vector: list[float],
        top_k: int,
        filters: dict[str, Any] | None,
        preferred_tags: list[str] | None,
    ) -> list[dict[str, Any]]: ...

    def search_with_text(
        self,
        vector: list[float],
        *,
        top_k: int,
        terms: list[str],
        fields: list[str],
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]: ...

    def scroll_with_text(
        self,
        *,
        terms: list[str],
        fields: list[str],
        limit: int,
        require_all: bool = True,
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]: ...

    def scroll_with_text_all(
        self,
        *,
        terms: list[str],
        fields: list[str],
        limit: int,
        require_all: bool = True,
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]: ...


class GraphReader(Protocol):
    """Read-only graph operation required by the graph endpoint."""

    def fetch_related_terms(
        self,
        terms: list[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int,
    ) -> list[dict[str, str]]: ...


class GraphSearchPort(GraphReader, Protocol):
    """Graph enrichment operations required by the full query workflow."""

    def build_structural_hits(
        self,
        terms: list[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int,
        hops: int,
        include_rel_match: bool,
    ) -> list[dict[str, Any]]: ...


class RerankHitsPort(Protocol):
    """Ranking operation supplied by the configured reranker adapter."""

    def __call__(
        self,
        *,
        hits: list[dict[str, Any]],
        user_query: str,
        provider: ModelProvider,
        query_vector: list[float] | None,
        mode: str | None,
        top_n: int,
        mix_mode: bool,
        mix_weight: float,
    ) -> list[dict[str, Any]]: ...


ModelProviderResolver = Callable[[str], ModelProvider]
VectorSearchFactory = Callable[[], VectorSearchPort]


__all__ = [
    "GraphReader",
    "GraphSearchPort",
    "ModelProvider",
    "ModelProviderResolver",
    "RerankHitsPort",
    "VectorSearchFactory",
    "VectorSearchPort",
]
