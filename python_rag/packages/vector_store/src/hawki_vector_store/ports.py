"""Structural ports implemented by vector-store adapters."""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any, Protocol, runtime_checkable

from hawki_vector_store.contracts import Vector, VectorFilter, VectorSearchHit


@runtime_checkable
class VectorReader(Protocol):
    """Vector operations required by read-side applications."""

    collection: str

    def select_scoped_collection(self, collection: str) -> None: ...

    def list_collections(self) -> list[str]: ...

    def search(
        self,
        vector: Vector,
        top_k: int = 5,
        filters: VectorFilter | None = None,
        **options: Any,
    ) -> list[VectorSearchHit]: ...

    def search_with_text(
        self,
        vector: Vector,
        *,
        top_k: int,
        terms: list[str],
        fields: list[str],
        filters: VectorFilter | None = None,
    ) -> list[VectorSearchHit]: ...


@runtime_checkable
class VectorWriter(Protocol):
    """Vector operations required by indexing and deletion workflows."""

    collection: str

    def set_collection(self, collection: str) -> None: ...

    def ensure_collection(self, vector_size: int, distance: str = "Cosine") -> None: ...

    def upsert_points(
        self,
        points: Sequence[Mapping[str, Any]],
        *,
        batch_size: int = 64,
        idempotency_key: str | None = None,
    ) -> None: ...

    def count_points_by_doc_id(
        self,
        doc_id: str,
        *,
        collection: str | None = None,
        exact: bool = True,
    ) -> int | None: ...

    def find_points_by_payload(
        self,
        filters: Mapping[str, object],
        *,
        limit: int = 1,
    ) -> Sequence[object]: ...

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        idempotency_key: str | None = None,
    ) -> object: ...


__all__ = ["VectorReader", "VectorWriter"]
