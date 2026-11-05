"""Exact historical vector deletion and shared-safe graph contribution cleanup."""

from hawki_graph_store import Neo4jGraph, load_neo4j_settings
from hawki_backend.text_ingestion.vectors import TextVectorDeletion


class ManagedCleanup:
    def __init__(self, vectors: TextVectorDeletion) -> None:
        self.vectors = vectors

    def delete(self, revision: dict, operation_id: str) -> None:
        scope = revision["workflow_input"]["ingestion"]
        for doc_id, target in (
            revision["writer_state"].get("managed_targets", {}).items()
        ):
            self.vectors.delete(
                collection=scope["collection"],
                dataset_id=revision["dataset_id"],
                source_id=revision["source_id"],
                document_id=doc_id,
                operation_key=operation_id,
            )
            if target["graph"]:
                namespace = scope.get("neo4j_namespace")
                if not namespace:
                    raise RuntimeError("Frozen graph namespace is unavailable")
                # Freeze the configured database as well as namespace. Explicit
                # settings prevent an empty historical database from drifting.
                from dataclasses import replace

                database = scope.get("neo4j_database")
                graph = Neo4jGraph(
                    dataset_id=revision["dataset_id"],
                    neo4j_namespace=namespace,
                    settings=replace(load_neo4j_settings(), database=database),
                    allow_database_fallback=False,
                )
                try:
                    receipt = graph.delete_scoped_document(
                        doc_id, request_id=operation_id + ":" + doc_id
                    )
                    if (
                        receipt.get("verified") is not True
                        or type(receipt.get("remaining_contributions")) is not int
                        or receipt.get("remaining_contributions") != 0
                    ):
                        raise RuntimeError("Graph deletion was not verified")
                finally:
                    graph.close()
