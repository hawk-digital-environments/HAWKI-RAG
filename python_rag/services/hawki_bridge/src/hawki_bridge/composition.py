"""Concrete query dependency composition for the bridge process."""

from hawki_model_providers.factory import get_provider

from hawki_bridge.adapters.neo4j_reader import Neo4jReader
from hawki_bridge.adapters.qdrant_reader import QdrantReader
from hawki_bridge.adapters.reranker_client import rerank_hits
from hawki_bridge.application.dependencies import QueryDependencies


def build_query_dependencies() -> QueryDependencies:
    """Bind query ports to the production model, storage, and reranker adapters."""

    return QueryDependencies(
        vector_search_factory=QdrantReader,
        graph_search=Neo4jReader(),
        resolve_model_provider=get_provider,
        rerank_hits=rerank_hits,
    )


__all__ = ["build_query_dependencies"]
