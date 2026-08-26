"""Concrete dependency composition for the indexer process."""

from hawki_indexer_worker.adapters.neo4j_writer import create_neo4j_writer
from hawki_indexer_worker.adapters.qdrant_writer import create_qdrant_writer
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.page_state import QdrantPageState


def build_ingest_workflow_dependencies() -> IngestWorkflowDependencies:
    """Bind indexer-owned ports to production Qdrant and Neo4j adapters."""

    return IngestWorkflowDependencies(
        vector_writer_factory=create_qdrant_writer,
        graph_writer_factory=create_neo4j_writer,
        page_state_factory=QdrantPageState,
    )


__all__ = ["build_ingest_workflow_dependencies"]
