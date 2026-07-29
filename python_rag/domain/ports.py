"""Persistence/provider contracts (ports) shared by domain logic and adapters."""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any, Protocol


class EmbeddingPort(Protocol):
    """Embed plain text into a numeric vector."""

    def embed(self, text: str) -> list[float]:
        """Return a fixed-dimension embedding vector."""


class ModelProvider(Protocol):
    """Common model surface used by query and ingestion workflows."""

    embed_model: str
    rag_model: str
    vision_model: str

    def embed(self, text: str) -> list[float]:
        """Return a fixed-dimension embedding vector."""

    def chat(
        self,
        system: str,
        messages: list[Mapping[str, object]],
        *,
        temperature: float | None = None,
    ) -> str:
        """Return a chat completion for structured messages."""


class ProviderResolver(Protocol):
    """Resolve a configured model provider by its public name."""

    def get_provider(self, name: str) -> ModelProvider:
        """Return the configured provider or raise ``ValueError``."""


class GraphStorePort(Protocol):
    """Persistence contract for graph stores."""

    def upsert_triplets(
        self,
        triplets: Sequence[tuple[str, str, str]],
        *,
        doc_id: str | None = None,
        request_id: str | None = None,
    ) -> None:
        """Store a graph triplet batch."""

    def delete_by_doc_id(self, doc_id: str, *, request_id: str | None = None) -> dict[str, Any]:
        """Delete graph state bound to a document id."""

    def count_triplets(self) -> int:
        """Count triplets in the graph store."""


class VectorStorePort(Protocol):
    """Persistence contract for vector indexes."""

    def upsert_points(
        self,
        points: list[dict[str, Any]],
        *,
        batch_size: int = 64,
        idempotency_key: str | None = None,
    ) -> None:
        """Upsert points into a vector index."""

    def delete_by_filter(self, *, filter_id: str, idempotency_key: str | None = None) -> dict[str, int]:
        """Delete vectors by an index filter."""

    def count_points(self, collection: str | None = None, exact: bool = True) -> int | None:
        """Return indexed point count."""


class RerankerPort(Protocol):
    """Contract for reranker adapters."""

    def rerank_hits(
        self,
        *,
        hits: list[dict[str, Any]],
        user_query: str,
        provider: Any,
        query_vector: list[float] | None,
        mode: str | None,
        top_n: int,
        mix_mode: bool,
        mix_weight: float,
    ) -> list[dict[str, Any]]:
        """Return reranked hits."""
