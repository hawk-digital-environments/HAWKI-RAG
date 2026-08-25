"""Query scenarios from lexical fallback and rewrite through scoped ranking and context assembly."""

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))

from characterization_support import (
    ScopedQdrantStub as _ScopedQdrantStub,
    authorized_query_scope as _authorized_query_scope,
)


class QueryCharacterizationTests(unittest.TestCase):
    """Protect query settings, fallback, deduplication, reranking, rewriting, and execution flow."""

    def test_query_uses_reranked_order_without_external_services(self) -> None:
        from hawki_bridge.application.query import orchestration as query_logic

        class Provider:
            def embed(self, text: str) -> list[float]:
                return [0.5, 0.25]

        class RagService:
            def rerank_hits(self, *, hits, **kwargs):
                return sorted(hits, key=lambda hit: hit["payload"]["title"])

        body = SimpleNamespace(
            query="HAWKI architecture",
            authorized_scope=_authorized_query_scope(),
            top_k=2,
            provider="fake",
            filters={},
            generate=False,
            is_optimized=False,
            fast_mode=True,
            smart_lookup=False,
            structural_hops=0,
            preferred_tags=None,
            reranker="none",
            rerank_top_n=10,
            mix_mode=False,
            mix_weight=0.5,
        )
        hits = [
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

        with (
            patch.dict(
                os.environ,
                {
                    "RAG_ITERATIVE_RETRIEVAL": "false",
                    "RAG_MIN_SCORE": "0.0",
                    "RAG_CONTEXT_DOCS": "2",
                    "RAG_GENERATE_ANSWER": "false",
                },
                clear=False,
            ),
            patch.object(query_logic, "QdrantHTTP", _ScopedQdrantStub),
            patch.object(query_logic, "run_search", lambda **kwargs: list(hits)),
            patch.object(
                query_logic, "_keyword_fallback_search", lambda *args, **kwargs: []
            ),
            patch.object(
                query_logic, "build_structural_hits", lambda *args, **kwargs: []
            ),
            patch.object(
                query_logic, "fetch_related_terms", lambda *args, **kwargs: []
            ),
        ):
            result = query_logic.query_documents(
                body,
                rag_service=RagService(),
                get_provider=lambda name: Provider(),
            )

        self.assertTrue(result["ok"])
        self.assertEqual(
            [hit["payload"]["title"] for hit in result["hits"]], ["Graph", "Vector"]
        )
        self.assertEqual(result["retrieval"]["context_docs"], 2)

    def test_query_execution_module_injection_and_flow(self) -> None:
        from hawki_bridge.application.query import execution as query_execution

        calls: list[str] = []
        graph_calls: list[tuple[str, dict[str, object]]] = []
        kg_term_calls: list[list[str]] = []

        class Provider:
            embed_model = "provider-embed"
            rag_model = "provider-rag"

            def embed(self, text: str) -> list[float]:
                calls.append("embed")
                return [0.1, 0.2, 0.3]

        body = SimpleNamespace(
            query="toy train",
            authorized_scope=_authorized_query_scope(graph_enabled=True),
            top_k=2,
            provider="fake",
            filters={"source_format": "markdown"},
            generate=False,
            is_optimized=False,
            fast_mode=False,
            smart_lookup=False,
            structural_hops=1,
            preferred_tags=None,
            reranker="none",
            rerank_top_n=4,
            mix_mode=False,
            mix_weight=0.25,
        )

        def fetch_scoped_terms(
            terms: list[str], **kwargs: object
        ) -> list[dict[str, str]]:
            kg_term_calls.append(terms)
            graph_calls.append(("kg", kwargs))
            return [{"subject": "HAWKI", "predicate": "related", "object": "RAG"}]

        result = query_execution.run_query_documents(
            body,
            rag_service=SimpleNamespace(
                rerank_hits=lambda **kwargs: [
                    {
                        "id": "fallback",
                        "score": 0.1,
                        "payload": {"title": "Fallback", "content": "", "doc_id": "z"},
                    }
                ]
            ),
            get_provider=lambda name: Provider(),
            qdrant_ctor=_ScopedQdrantStub,
            analyze_prompt_fn=lambda query: {
                "blocked": False,
                "issues": [],
                "sanitized": query,
            },
            enforce_output_safety_fn=lambda answer: {
                "blocked": False,
                "issues": [],
                "answer": answer,
            },
            sanitize_prompt_text_fn=lambda query: query,
            build_query_rewrite_fn=lambda provider, query, **kwargs: {
                "enabled": True,
                "rewritten_query": "rewritten",
                "high_level_keys": ["toys"],
                "low_level_keys": ["trains"],
                "entity_terms": ["train"],
                "modality_hints": [],
            },
            build_query_terms_fn=lambda rewritten_query, high_level_keys, low_level_keys, entity_terms: [
                "train",
                "toy",
            ],
            run_search_fn=lambda **kwargs: [
                {
                    "id": "a",
                    "score": 0.6,
                    "payload": {
                        "title": "A",
                        "component_type": "chunk",
                        "content": "train",
                        "doc_id": "a",
                    },
                },
                {
                    "id": "b",
                    "score": 0.4,
                    "payload": {
                        "title": "B",
                        "component_type": "chunk",
                        "content": "toy",
                        "doc_id": "b",
                    },
                },
            ],
            keyword_fallback_fn=lambda *args, **kwargs: [],
            build_structural_hits_fn=lambda *args, **kwargs: (
                graph_calls.append(("structural", kwargs))
                or [
                    {"id": "s", "payload": {"component_type": "relation", "title": "s"}}
                ]
            ),
            structural_hops_fn=lambda: 1,
            structural_limit_fn=lambda top_k: top_k,
            fusion_weights_fn=lambda: (1.0, 0.5),
            rerank_and_filter_hits_fn=lambda hits, **kwargs: hits,
            should_iterate_fn=lambda query, hits, top_k: False,
            collect_expansion_terms_fn=lambda hits: ["x"],
            merge_hits_fn=lambda primary, secondary, limit: secondary + primary,
            build_fused_hits_fn=lambda sem_hits, struct_hits, sem_weight=0.0, str_weight=0.0: (
                sem_hits + struct_hits
            ),
            prepare_context_fn=lambda hits, max_docs, max_tokens: (hits, [], 0),
            run_high_recall_fn=lambda **kwargs: [],
            fetch_related_terms_fn=fetch_scoped_terms,
            context_limits_fn=lambda: (400, 10),
            score_thresholds_fn=lambda: (0.0, 0.0),
            iterative_retrieval_enabled_fn=lambda: False,
            generation_enabled_fn=lambda: False,
            configured_search_top_k_fn=lambda top_k: top_k,
            extract_terms_fn=lambda text: text.lower().split(),
            terms_from_payload_fn=lambda payload: ["kg"],
        )

        self.assertEqual(result["ok"], True)
        self.assertEqual(len(result["hits"]), 3)
        self.assertEqual(result["count"], 3)
        self.assertTrue(any(h["id"] == "a" for h in result["hits"]))
        self.assertEqual(result["retrieval"]["iterative_pass"], False)
        self.assertIn("embed", calls)
        self.assertEqual([call[0] for call in graph_calls], ["structural", "kg"])
        for _operation, scope in graph_calls:
            self.assertEqual(scope["dataset_id"], "dataset-a")
            self.assertEqual(scope["neo4j_namespace"], "hawki_dataset_a")
        self.assertEqual(kg_term_calls, [["rewritten", "train", "toy", "kg"]])
