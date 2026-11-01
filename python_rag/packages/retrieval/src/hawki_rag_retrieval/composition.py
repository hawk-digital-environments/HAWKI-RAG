"""Concrete query dependency composition for embedding services.

The factory binds the shared retrieval ports to the production adapters once,
at the composition root of a service. It performs no network I/O: adapters are
constructed lazily and only connect when a query is executed.

It deliberately knows nothing about FastAPI, ``python_backend`` or the bridge
HTTP service. Those layers own their own transport and request parsing.
"""

from __future__ import annotations

from hawki_model_providers.factory import create_model_provider

from hawki_rag_retrieval.adapters import Neo4jReader, QdrantReader, rerank_hits
from hawki_rag_retrieval.dependencies import QueryDependencies


def build_query_dependencies() -> QueryDependencies:
    """Return production query dependencies without opening a connection."""

    return QueryDependencies(
        vector_search_factory=QdrantReader,
        graph_search=Neo4jReader(),
        resolve_model_provider=create_model_provider,
        rerank_hits=rerank_hits,
    )


__all__ = ["build_query_dependencies"]
