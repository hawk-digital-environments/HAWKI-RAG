"""Structural ports implemented by graph-store adapters."""

from __future__ import annotations

from collections.abc import Iterable
from typing import Protocol, runtime_checkable

from hawki_graph_store.contracts import (
    GraphDeletionResult,
    GraphFact,
    GraphStructuralHit,
    GraphTriplet,
)


@runtime_checkable
class GraphReader(Protocol):
    """Dataset-scoped operations required by graph retrieval."""

    def fetch_related(
        self,
        terms: Iterable[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int = 25,
    ) -> list[GraphFact]: ...

    def search_structural(
        self,
        terms: Iterable[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int = 40,
        hops: int = 2,
        include_rel_match: bool = False,
    ) -> list[GraphStructuralHit]: ...


@runtime_checkable
class GraphWriter(Protocol):
    """Dataset-scoped operations required by graph indexing and deletion."""

    def upsert_triplets(
        self,
        triplets: Iterable[GraphTriplet],
        *,
        doc_id: str | None = None,
        request_id: str | None = None,
        dataset_id: str | None = None,
        neo4j_namespace: str | None = None,
    ) -> None: ...

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        request_id: str | None = None,
    ) -> GraphDeletionResult: ...

    def close(self) -> None: ...


__all__ = ["GraphReader", "GraphWriter"]
