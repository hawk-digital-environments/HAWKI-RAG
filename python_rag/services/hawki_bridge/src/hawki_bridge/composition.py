"""Concrete query dependency composition for the bridge process."""

from hawki_model_providers.factory import create_model_provider

from hawki_rag_retrieval.adapters import Neo4jReader, QdrantReader, rerank_hits
from hawki_rag_retrieval.dependencies import QueryDependencies


def build_query_dependencies() -> QueryDependencies:
    """Bind query ports to the production model, storage, and reranker adapters."""

    return QueryDependencies(
        vector_search_factory=QdrantReader,
        graph_search=Neo4jReader(),
        resolve_model_provider=create_model_provider,
        rerank_hits=rerank_hits,
    )


__all__ = ["build_query_dependencies"]
