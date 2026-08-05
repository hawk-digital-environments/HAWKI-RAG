"""Structural ports used to inject indexer infrastructure."""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any, Protocol


class EmbeddingProvider(Protocol):
    embed_model: str

    def embed(self, text: str) -> list[float]: ...


class VectorWriter(Protocol):
    collection: str

    def ensure_collection(self, vector_size: int, *, distance: str) -> object: ...

    def upsert_points(
        self,
        points: Sequence[Mapping[str, Any]],
        *,
        batch_size: int,
        idempotency_key: str | None = None,
    ) -> object: ...


class GraphWriter(Protocol):
    def upsert_triplets(
        self, triplets: list[tuple[str, str, str]], **kwargs: Any
    ) -> object: ...

    def close(self) -> None: ...


__all__ = ["EmbeddingProvider", "GraphWriter", "VectorWriter"]
