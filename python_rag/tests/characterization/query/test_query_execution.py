"""End-to-end query use-case characterization through bridge-owned ports."""

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from typing import Any
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


class _VectorSearch:
    def __init__(self, hits: list[dict[str, Any]]) -> None:
        self.hits = hits
        self.collection = ""

    def select_scoped_collection(self, collection: str) -> None:
        self.collection = collection

    def search_candidates(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return list(self.hits)

    def search_high_recall(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return []

    def search_with_text(self, *_args: Any, **_kwargs: Any) -> list[dict[str, Any]]:
        return []

    def scroll_with_text(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return []

    def scroll_with_text_all(self, **_kwargs: Any) -> list[dict[str, Any]]:
        return []


class _Provider:
    embed_model = "provider-embed"
    rag_model = "provider-chat"
    vision_model = "provider-vision"

    def __init__(self, calls: list[str]) -> None:
        self.calls = calls

    def embed(self, text: str) -> list[float]:
        self.calls.append("embed")
        return [0.5, 0.25]

    def chat(self, *_args: Any, **_kwargs: Any) -> str:
        self.calls.append("chat")
        return "unused"


def _request(*, graph_enabled: bool):
    from hawki_rag_contracts.auth_scope import AuthorizedQueryScope
    from hawki_rag_contracts.query import QueryRequest

    return QueryRequest(
        query="toy train",
        authorized_scope=AuthorizedQueryScope(
            dataset_id="dataset-a",
            qdrant_collection="hawki_dataset_a",
            neo4j_namespace="hawki_dataset_a" if graph_enabled else None,
            embedding_provider="fake",
            embedding_model="authorized-embedding",
            graph_enabled=graph_enabled,
        ),
        top_k=2,
        provider="fake",
        chat_model="selected-chat",
        vision_model="selected-vision",
        filters={"source_format": "markdown"},
        generate=False,
        fast_mode=False,
        structural_hops=1,
        reranker="none",
        rerank_top_n=4,
        mix_mode=False,
        mix_weight=0.25,
    )


class QueryCharacterizationTests(unittest.TestCase):
    """Protect the public query workflow without implementation-level injection."""

    def test_query_uses_reranked_order_through_typed_dependencies(self) -> None:
        from hawki_bridge.application.dependencies import QueryDependencies
        from hawki_bridge.application.query.execution import execute_authorized_query

        calls: list[str] = []
        vector_search = _VectorSearch(
            [
                {
                    "id": "b",
                    "score": 0.6,
                    "payload": {
                        "title": "Vector",
                        "content": "Qdrant vector search",
                        "component_type": "chunk",
                        "doc_id": "doc-b",
                    },
                },
                {
                    "id": "a",
                    "score": 0.5,
                    "payload": {
                        "title": "Graph",
                        "content": "Neo4j graph search",
                        "component_type": "chunk",
                        "doc_id": "doc-a",
                    },
                },
            ]
        )
        graph_search = type(
            "GraphSearch",
            (),
            {
                "build_structural_hits": lambda self, *args, **kwargs: [],
                "fetch_related_terms": lambda self, *args, **kwargs: [],
            },
        )()
        dependencies = QueryDependencies(
            vector_search_factory=lambda: vector_search,
            graph_search=graph_search,
            resolve_model_provider=lambda _name: _Provider(calls),
            rerank_hits=lambda *, hits, **kwargs: sorted(
                hits, key=lambda hit: hit["payload"]["title"]
            ),
        )

        with patch.dict(
            os.environ,
            {
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
                "RAG_CONTEXT_DOCS": "2",
                "RAG_GENERATE_ANSWER": "false",
            },
            clear=False,
        ):
            result = execute_authorized_query(
                _request(graph_enabled=False),
                dependencies=dependencies,
            )

        self.assertTrue(result.ok)
        self.assertEqual(
            [hit.payload["title"] for hit in result.hits], ["Graph", "Vector"]
        )
        self.assertEqual(result.retrieval["context_docs"], 2)
        self.assertEqual(vector_search.collection, "hawki_dataset_a")
        self.assertEqual(calls, ["embed"])

    def test_query_passes_authorized_scope_to_both_graph_operations(self) -> None:
        from hawki_bridge.application.dependencies import QueryDependencies
        from hawki_bridge.application.query.execution import execute_authorized_query

        graph_calls: list[tuple[str, dict[str, object]]] = []

        class GraphSearch:
            def build_structural_hits(
                self, _terms: list[str], **scope: object
            ) -> list[dict[str, Any]]:
                graph_calls.append(("structural", scope))
                return []

            def fetch_related_terms(
                self, _terms: list[str], **scope: object
            ) -> list[dict[str, str]]:
                graph_calls.append(("facts", scope))
                return [{"subject": "HAWKI", "relation": "related", "object": "RAG"}]

        vector_search = _VectorSearch(
            [
                {
                    "id": "a",
                    "score": 0.9,
                    "payload": {
                        "title": "A",
                        "content": "toy train",
                        "component_type": "chunk",
                        "doc_id": "doc-a",
                    },
                }
            ]
        )
        calls: list[str] = []
        dependencies = QueryDependencies(
            vector_search_factory=lambda: vector_search,
            graph_search=GraphSearch(),
            resolve_model_provider=lambda _name: _Provider(calls),
            rerank_hits=lambda *, hits, **kwargs: hits,
        )

        with patch.dict(
            os.environ,
            {
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
                "RAG_GENERATE_ANSWER": "false",
            },
            clear=False,
        ):
            result = execute_authorized_query(
                _request(graph_enabled=True),
                dependencies=dependencies,
            )

        self.assertEqual(result.count, 1)
        self.assertEqual(
            [operation for operation, _scope in graph_calls],
            ["structural", "facts"],
        )
        for _operation, scope in graph_calls:
            self.assertEqual(scope["dataset_id"], "dataset-a")
            self.assertEqual(scope["neo4j_namespace"], "hawki_dataset_a")
