"""Dataset-scoped graph-read application service."""

from __future__ import annotations

from dataclasses import dataclass

from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope

from hawki_bridge.domain.ports import GraphReader


@dataclass(frozen=True, slots=True)
class GraphReadService:
    """Authorize the storage scope before delegating a graph read."""

    reader: GraphReader

    def related_terms(
        self,
        terms: list[str],
        *,
        authorized_scope: AuthorizedQueryScope,
        limit: int,
    ) -> list[dict[str, str]]:
        if not authorized_scope.graph_enabled:
            raise ValueError("graph access is not enabled for the authorized dataset")

        namespace = authorized_scope.neo4j_namespace
        if not namespace:
            raise ValueError("neo4j_namespace is required for graph retrieval")

        return self.reader.fetch_related_terms(
            terms,
            dataset_id=authorized_scope.dataset_id,
            neo4j_namespace=namespace,
            limit=limit,
        )


__all__ = ["GraphReadService"]
