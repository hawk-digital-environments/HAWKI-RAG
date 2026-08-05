"""Indexer composition surface for scoped Neo4j writes."""

from hawki_rag_stores.neo4j.graph import Neo4jGraph


def create_neo4j_writer(
    database: str | None = None,
    *,
    dataset_id: str | None = None,
    neo4j_namespace: str | None = None,
) -> Neo4jGraph:
    return Neo4jGraph(
        database=database,
        dataset_id=dataset_id,
        neo4j_namespace=neo4j_namespace,
    )


__all__ = ["create_neo4j_writer"]
