"""Concrete dependency composition for the bridge process."""

from hawki_bridge.adapters.neo4j_reader import Neo4jReader
from hawki_bridge.adapters.qdrant_reader import QdrantReader
from hawki_bridge.application.dependencies import BridgeDependencies


def build_bridge_dependencies() -> BridgeDependencies:
    """Bind bridge-owned storage ports to their production adapters."""

    return BridgeDependencies(
        vector_search_factory=QdrantReader,
        graph_search=Neo4jReader(),
    )


__all__ = ["build_bridge_dependencies"]
