"""Contract tests for the shared query dependency composition factory."""

from __future__ import annotations

from hawki_model_providers.factory import create_model_provider

from hawki_rag_retrieval import build_query_dependencies
from hawki_rag_retrieval.adapters import Neo4jReader, QdrantReader, rerank_hits
from hawki_rag_retrieval.dependencies import QueryDependencies


def test_build_query_dependencies_binds_production_adapters() -> None:
    """The factory must bind the shared adapters without a service dependency."""

    dependencies = build_query_dependencies()

    assert isinstance(dependencies, QueryDependencies)
    assert dependencies.vector_search_factory is QdrantReader
    assert isinstance(dependencies.graph_search, Neo4jReader)
    assert dependencies.resolve_model_provider is create_model_provider
    assert dependencies.rerank_hits is rerank_hits


def test_build_query_dependencies_constructs_no_storage_client(
    monkeypatch,
) -> None:
    """Composition must stay lazy and must not open a storage connection."""

    import hawki_rag_retrieval.adapters.neo4j_reader as neo4j_module
    import hawki_rag_retrieval.adapters.qdrant_reader as qdrant_module

    def fail_connection(*_args, **_kwargs):
        raise AssertionError("composition must not construct a storage client")

    monkeypatch.setattr(qdrant_module.QdrantHTTP, "__init__", fail_connection)
    monkeypatch.setattr(neo4j_module.Neo4jGraph, "__init__", fail_connection)

    dependencies = build_query_dependencies()

    assert dependencies.vector_search_factory is QdrantReader


def test_factory_is_exported_from_package_public_api() -> None:
    """The factory must be part of the retrieval package's public surface."""

    import hawki_rag_retrieval

    assert "build_query_dependencies" in hawki_rag_retrieval.__all__
