"""Stable values exchanged across the graph-store boundary."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, TypeAlias, TypedDict


GraphTriplet: TypeAlias = tuple[str, str, str]


@dataclass(frozen=True, slots=True)
class GraphScope:
    """Dataset boundary applied to every graph read and write."""

    dataset_id: str
    neo4j_namespace: str

    def __post_init__(self) -> None:
        dataset_id = str(self.dataset_id or "").strip()
        namespace = str(self.neo4j_namespace or "").strip()
        if not dataset_id or not namespace:
            raise ValueError(
                "GraphScope requires non-empty dataset_id and neo4j_namespace."
            )
        object.__setattr__(self, "dataset_id", dataset_id)
        object.__setattr__(self, "neo4j_namespace", namespace)


class GraphFact(TypedDict):
    """One directed fact returned by graph retrieval."""

    subject: str
    relation: str
    object: str


class GraphStructuralHit(GraphFact, total=False):
    """A graph fact enriched with traversal evidence."""

    doc_id: str | None
    hops: int
    payload: dict[str, Any]


class GraphDeletionResult(TypedDict):
    """Counts produced when one document is removed from the graph."""

    relationships_deleted: int
    entities_deleted: int


__all__ = [
    "GraphDeletionResult",
    "GraphFact",
    "GraphScope",
    "GraphStructuralHit",
    "GraphTriplet",
]
