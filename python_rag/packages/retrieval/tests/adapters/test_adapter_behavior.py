"""Shared retrieval adapter error and dataset-scope regressions."""

from __future__ import annotations

from typing import Any, cast

import pytest
from neo4j.exceptions import Neo4jError, ServiceUnavailable

from hawki_rag_retrieval.adapters import Neo4jReader
from hawki_rag_retrieval.adapters import QdrantReader
from hawki_rag_retrieval.errors import (
    DatasetVectorStoreNotReadyError,
)
from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope


def test_qdrant_adapter_translates_missing_scoped_collection() -> None:
    from hawki_vector_store.client import ScopedCollectionNotReadyError

    class MissingCollectionClient:
        def search_with_text(self, *_args, **_kwargs):
            raise ScopedCollectionNotReadyError("missing")

    reader = QdrantReader(client=cast(Any, MissingCollectionClient()))

    with pytest.raises(DatasetVectorStoreNotReadyError, match="missing"):
        reader.search_with_text(
            [0.1],
            top_k=1,
            terms=["fee"],
            fields=["content"],
        )


def _scope(*, graph_enabled: bool = False) -> AuthorizedQueryScope:
    return AuthorizedQueryScope(
        dataset_id="dataset-42",
        qdrant_collection="dataset_42_vectors",
        neo4j_namespace="dataset_42_graph" if graph_enabled else None,
        embedding_provider="ollama",
        embedding_model="nomic-embed-text",
        graph_enabled=graph_enabled,
    )


def test_neo4j_adapter_delegates_a_read_without_changing_scope(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    from hawki_rag_retrieval.adapters import neo4j_reader as reader_module

    observed: dict[str, Any] = {}

    def fake_fetch_related_graph(terms: list[str], **scope: Any):
        observed.update({"terms": terms, **scope})
        return [{"subject": "A", "relation": "uses", "object": "B"}]

    monkeypatch.setattr(reader_module, "fetch_related_graph", fake_fetch_related_graph)

    result = Neo4jReader().fetch_related_graph(
        ["authorization"],
        dataset_id="dataset-42",
        neo4j_namespace="dataset_42_graph",
        limit=11,
    )

    assert result == [{"subject": "A", "relation": "uses", "object": "B"}]
    assert observed == {
        "terms": ["authorization"],
        "dataset_id": "dataset-42",
        "neo4j_namespace": "dataset_42_graph",
        "limit": 11,
    }


@pytest.mark.parametrize(
    "error",
    [
        Neo4jError._hydrate_neo4j(
            code="Neo.ClientError.Security.Unauthorized",
            message="bad credentials",
        ),
        ValueError("result contract is invalid"),
    ],
)
@pytest.mark.parametrize("operation", ["related", "structural"])
def test_neo4j_adapter_propagates_non_availability_errors(
    monkeypatch: pytest.MonkeyPatch,
    error: BaseException,
    operation: str,
) -> None:
    from hawki_rag_retrieval.adapters import neo4j_reader as reader_module

    class FailingGraph:
        def __init__(self, **_kwargs: Any) -> None:
            pass

        def fetch_related(self, *_args: Any, **_kwargs: Any) -> list[dict[str, str]]:
            raise error

        def search_structural(
            self, *_args: Any, **_kwargs: Any
        ) -> list[dict[str, Any]]:
            raise error

        def close(self) -> None:
            pass

    monkeypatch.setattr(reader_module, "Neo4jGraph", FailingGraph)

    with pytest.raises(type(error)):
        if operation == "related":
            reader_module.fetch_related_graph(
                ["term"], dataset_id="dataset-a", neo4j_namespace="graph-a"
            )
        else:
            reader_module.build_structural_hits(
                ["term"],
                dataset_id="dataset-a",
                neo4j_namespace="graph-a",
                limit=10,
                hops=2,
            )


@pytest.mark.parametrize("operation", ["related", "structural"])
def test_neo4j_adapter_degrades_only_unavailability_to_empty(
    monkeypatch: pytest.MonkeyPatch,
    operation: str,
) -> None:
    from hawki_rag_retrieval.adapters import neo4j_reader as reader_module

    class UnavailableGraph:
        def __init__(self, **_kwargs: Any) -> None:
            pass

        def fetch_related(self, *_args: Any, **_kwargs: Any) -> list[dict[str, str]]:
            raise ServiceUnavailable("connection details must stay private")

        def search_structural(
            self, *_args: Any, **_kwargs: Any
        ) -> list[dict[str, Any]]:
            raise ServiceUnavailable("connection details must stay private")

        def close(self) -> None:
            pass

    monkeypatch.setattr(reader_module, "Neo4jGraph", UnavailableGraph)

    if operation == "related":
        result = reader_module.fetch_related_graph(
            ["term"], dataset_id="dataset-a", neo4j_namespace="graph-a"
        )
    else:
        result = reader_module.build_structural_hits(
            ["term"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            limit=10,
            hops=2,
        )

    assert result == []
