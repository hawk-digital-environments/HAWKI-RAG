"""Read-only Neo4j adapter exposed to bridge application code."""

from __future__ import annotations

from dataclasses import dataclass

from hawki_rag_stores.neo4j.traversal import (
    build_structural_hits,
    fetch_related_terms,
)


@dataclass(frozen=True, slots=True)
class Neo4jReader:
    """Adapt the shared Neo4j store functions to the bridge graph port."""

    def fetch_related_terms(
        self,
        terms: list[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int,
    ) -> list[dict[str, str]]:
        return fetch_related_terms(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
        )


__all__ = ["Neo4jReader", "build_structural_hits", "fetch_related_terms"]
