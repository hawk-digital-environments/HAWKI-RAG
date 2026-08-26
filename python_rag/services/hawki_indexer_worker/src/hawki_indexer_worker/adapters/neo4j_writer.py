"""Neo4j adapter implementing the indexer-owned graph writer port."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from hawki_graph_store.graph import Neo4jGraph
from hawki_indexer_worker.domain.ports import GraphWriterPort


@dataclass(slots=True)
class Neo4jWriter:
    """Delegate scoped graph writes to the graph-store client."""

    client: Neo4jGraph

    @property
    def _neo4j_namespace(self) -> str | None:
        return getattr(self.client, "_neo4j_namespace", None)

    def upsert_triplets(
        self, triplets: list[tuple[str, str, str]], **kwargs: Any
    ) -> object:
        return self.client.upsert_triplets(triplets, **kwargs)

    def delete_by_doc_id(self, doc_id: str, **kwargs: Any) -> object:
        return self.client.delete_by_doc_id(doc_id, **kwargs)

    def close(self) -> None:
        self.client.close()


def create_neo4j_writer(
    database: str | None = None,
    *,
    dataset_id: str | None = None,
    neo4j_namespace: str | None = None,
) -> GraphWriterPort:
    return Neo4jWriter(
        Neo4jGraph(
            database=database,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
        )
    )


__all__ = ["Neo4jWriter", "create_neo4j_writer"]
