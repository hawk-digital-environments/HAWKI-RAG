"""Stable values exchanged across the vector-store boundary."""

from __future__ import annotations

from typing import Any, TypeAlias, TypedDict


Vector: TypeAlias = list[float]
VectorFilter: TypeAlias = dict[str, Any]


class VectorPoint(TypedDict, total=False):
    """One Qdrant point produced by indexing."""

    id: str | int
    vector: Vector
    payload: dict[str, Any]


class VectorSearchHit(TypedDict, total=False):
    """One vector-search result returned to retrieval orchestration."""

    id: str | int
    score: float
    payload: dict[str, Any]
    vector: Vector
    collection: str


__all__ = ["Vector", "VectorFilter", "VectorPoint", "VectorSearchHit"]
