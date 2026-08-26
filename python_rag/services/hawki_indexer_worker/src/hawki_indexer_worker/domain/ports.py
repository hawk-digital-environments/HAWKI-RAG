"""Structural ports used to inject indexer infrastructure."""

from __future__ import annotations

from collections.abc import Callable, Mapping, Sequence
from typing import Any, Protocol


class EmbeddingProvider(Protocol):
    embed_model: str

    def embed(self, text: str) -> list[float]: ...


class VectorWriterPort(Protocol):
    """Vector persistence operations required by indexing workflows."""

    collection: str

    def set_collection(self, collection: str) -> None: ...

    def ensure_collection(
        self, vector_size: int, distance: str = "Cosine"
    ) -> object: ...

    def upsert_points(
        self,
        points: Sequence[Mapping[str, Any]],
        *,
        batch_size: int,
        idempotency_key: str | None = None,
    ) -> object: ...

    def delete_by_doc_id(
        self, doc_id: str, *, idempotency_key: str | None = None
    ) -> object: ...

    def find_points_by_payload(
        self, filters: Mapping[str, object], *, limit: int = 1
    ) -> Sequence[object]: ...

    def count_points_by_doc_id(
        self,
        doc_id: str,
        *,
        collection: str | None = None,
        exact: bool = True,
    ) -> int | None: ...


class GraphWriterPort(Protocol):
    """Graph persistence operations required by indexing workflows."""

    def upsert_triplets(
        self, triplets: list[tuple[str, str, str]], **kwargs: Any
    ) -> object: ...

    def delete_by_doc_id(self, doc_id: str, **kwargs: Any) -> object: ...

    def close(self) -> None: ...


class PageStatePort(Protocol):
    """Incremental document-state operations used by ingestion."""

    def find_by_source_identity(
        self, *, collection: str, source_identity: str
    ) -> dict[str, Any] | None: ...

    def mark_completed(self, records: list[Any]) -> None: ...

    def mark_seen(self, records: list[Any]) -> None: ...


GraphWriter = GraphWriterPort
VectorWriter = VectorWriterPort
GraphWriterFactory = Callable[..., GraphWriterPort]
VectorWriterFactory = Callable[[], VectorWriterPort]
PageStateFactory = Callable[[VectorWriterPort], PageStatePort | None]


__all__ = [
    "EmbeddingProvider",
    "GraphWriter",
    "GraphWriterFactory",
    "GraphWriterPort",
    "PageStateFactory",
    "PageStatePort",
    "VectorWriter",
    "VectorWriterFactory",
    "VectorWriterPort",
]
