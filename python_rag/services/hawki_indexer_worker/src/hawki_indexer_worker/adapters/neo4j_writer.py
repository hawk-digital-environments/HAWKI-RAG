"""Neo4j adapter implementing the indexer-owned graph writer port."""

from __future__ import annotations

from dataclasses import dataclass

from hawki_graph_store.graph import Neo4jGraph
from hawki_indexer_worker.domain.ports import GraphWriterPort


@dataclass(slots=True)
class Neo4jWriter:
    """Delegate scoped graph writes to the graph-store client."""

    client: Neo4jGraph

    def upsert_triplets(
        self,
        triplets: list[tuple[str, str, str]],
        *,
        doc_id: str,
        request_id: str | None,
        dataset_id: str,
        neo4j_namespace: str,
    ) -> object:
        return self.client.upsert_triplets(
            triplets,
            doc_id=doc_id,
            request_id=request_id,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
        )

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        request_id: str | None = None,
    ) -> object:
        return self.client.delete_by_doc_id(doc_id, request_id=request_id)

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
