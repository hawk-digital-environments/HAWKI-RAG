"""Query scenarios from lexical fallback and rewrite through scoped ranking and context assembly."""

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))

from characterization_support import (
    ScopedQdrantStub as _ScopedQdrantStub,
    authorized_query_scope as _authorized_query_scope,
    install_optional_dependency_stubs,
)

install_optional_dependency_stubs()



class QueryCharacterizationTests(unittest.TestCase):
    """Protect query settings, fallback, deduplication, reranking, rewriting, and execution flow."""
    def test_query_lexical_helpers_fold_fuzzy_match_and_boost_scores(self) -> None:
        from application.workflows.query_lexical import (
            extract_query_terms_for_lexical,
            fold_text,
            fuzzy_term_in_words,
            lexical_boost_hits,
        )

        hits = [
            {
                "id": "a",
                "score": 0.1,
                "payload": {
                    "title": "Holzspielzeug",
                    "content": "Robuste Baukloetze und Holzspielzeug fuer Kinder",
                    "doc_id": "doc-a",
                },
            },
            {
                "id": "b",
                "score": 0.9,
                "payload": {"title": "Other", "content": "Unrelated text", "doc_id": "doc-b"},
            },
        ]

        boosted = lexical_boost_hits(hits, "Bauklötze Holzspielzeug")

        self.assertEqual(fold_text("Bauklötze für große Kinder"), "bauklotze fur grosse kinder")
        self.assertTrue(fuzzy_term_in_words("blocks", ["block"]))
        self.assertIn("bauklotze", extract_query_terms_for_lexical("Bauklötze"))
        self.assertEqual([hit["payload"]["doc_id"] for hit in boosted], ["doc-a"])
        self.assertGreater(boosted[0]["score"], 0.1)

    def test_query_settings_parse_env_with_caps_and_fallbacks(self) -> None:
        from application.workflows.query_settings import (
            context_limits,
            fusion_weights,
            generation_enabled,
            iterative_retrieval_enabled,
            score_thresholds,
            search_top_k,
        )

        with patch.dict(
            os.environ,
            {
                "RAG_SEARCH_TOP_K_MULT": "4",
                "RAG_SEARCH_TOP_K_CAP": "15",
                "RAG_FUSION_SEM_WEIGHT": "bad",
                "RAG_FUSION_STR_WEIGHT": "0.25",
                "RAG_MIN_SCORE": "0.3",
                "RAG_MIN_SCORE_FALLBACK": "bad",
                "RAG_CONTEXT_TOKENS": "120",
                "RAG_CONTEXT_DOCS": "bad",
                "RAG_ITERATIVE_RETRIEVAL": "no",
                "RAG_GENERATE_ANSWER": "yes",
            },
            clear=False,
        ):
            self.assertEqual(search_top_k(5), 15)
            self.assertEqual(fusion_weights(), (0.6, 0.25))
            self.assertEqual(score_thresholds(), (0.3, 0.2))
            self.assertEqual(context_limits(), (120, 6))
            self.assertFalse(iterative_retrieval_enabled())
            self.assertTrue(generation_enabled())

    def test_query_fallback_uses_text_search_then_relaxed_scroll(self) -> None:
        from application.workflows.query_fallback import keyword_fallback_search

        calls: list[tuple[str, bool | None]] = []

        class Qdrant:
            def search_with_text(self, vector, *, top_k, terms, fields):
                calls.append(("search", None))
                return [{"id": "a", "score": 0.5, "payload": {"doc_id": "doc-a"}}]

            def scroll_with_text(self, *, terms, fields, limit, require_all):
                calls.append(("scroll", require_all))
                if require_all:
                    return []
                return [{"id": "b", "score": 0.8, "payload": {"doc_id": "doc-b"}}]

        with patch.dict(os.environ, {"RAG_EXHAUSTIVE_TEXT": "false", "QDRANT_TEXT_SCROLL_LIMIT": "10"}, clear=False):
            hits = keyword_fallback_search(Qdrant(), [0.1], "wooden toys", 3)

        self.assertEqual([hit["payload"]["doc_id"] for hit in hits], ["doc-b", "doc-a"])
        self.assertEqual(calls, [("search", None), ("scroll", True), ("scroll", False)])

    def test_query_fallback_uses_injected_scroll_controls(self) -> None:
        from application.workflows.query_fallback import keyword_fallback_search

        calls: list[tuple[str, int | bool | None]] = []

        class Qdrant:
            def search_with_text(self, vector, *, top_k, terms, fields):
                calls.append(("search", top_k))
                return []

            def scroll_with_text_all(self, *, terms, fields, limit, require_all):
                calls.append(("scroll_all", limit))
                return [{"id": "a", "score": 0.4, "payload": {"doc_id": "doc-a"}}]

            def scroll_with_text(self, *, terms, fields, limit, require_all):
                calls.append(("scroll", limit))
                return []

        hits = keyword_fallback_search(
            Qdrant(),
            [0.1],
            "wooden toys",
            3,
            text_scroll_limit_fn=lambda top_k: 7,
            exhaustive_text_fn=lambda: True,
        )

        self.assertEqual([hit["payload"]["doc_id"] for hit in hits], ["doc-a"])
        self.assertEqual(calls, [("search", 3), ("scroll_all", 7)])

    def test_query_hit_helpers_merge_dedupe_and_limit_by_doc_identity(self) -> None:
        from application.workflows import query_logic

        primary = [
            {"id": "a", "score": 0.2, "payload": {"doc_id": "doc-a", "title": "Toy Train"}},
            {"id": "b", "score": 0.4, "payload": {"doc_id": "doc-b", "title": "Blocks"}},
        ]
        secondary = [
            {"id": "a2", "score": 0.9, "payload": {"doc_id": "doc-a", "title": "Toy Train Duplicate"}},
            {"id": "c", "score": 0.8, "payload": {"doc_id": "doc-c", "title": "Blocks"}},
        ]

        merged = query_logic._merge_hits(primary, secondary, limit=3)
        deduped = query_logic._dedupe_hits_by_title_or_url(merged)

        self.assertEqual([hit["payload"]["doc_id"] for hit in merged], ["doc-c", "doc-b", "doc-a"])
        self.assertEqual([hit["payload"]["doc_id"] for hit in deduped], ["doc-c", "doc-a"])

    def test_query_context_summaries_trim_to_token_budget(self) -> None:
        from application.workflows import query_logic

        hits = [
            {
                "id": "a",
                "score": 0.9,
                "payload": {
                    "title": "Toy Catalog",
                    "page_url": "upload://toys.md",
                    "content": " ".join(["wooden blocks"] * 80),
                    "component_type": "chunk",
                },
            }
        ]

        summaries, trimmed, used_tokens = query_logic._prepare_context_summaries(
            hits,
            max_docs=1,
            max_tokens=20,
        )

        self.assertEqual(len(summaries), 1)
        self.assertEqual(trimmed, [1])
        self.assertLessEqual(used_tokens, 30)
        self.assertEqual(summaries[0]["title"], "Toy Catalog")

    def test_query_uses_reranked_order_without_external_services(self) -> None:
        from application.workflows import query_logic

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

        with patch.dict(
            os.environ,
            {
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
                "RAG_CONTEXT_DOCS": "2",
                "RAG_GENERATE_ANSWER": "false",
            },
            clear=False,
        ), patch.object(query_logic, "QdrantHTTP", _ScopedQdrantStub), patch.object(
            query_logic, "run_search", lambda **kwargs: list(hits)
        ), patch.object(
            query_logic, "_keyword_fallback_search", lambda *args, **kwargs: []
        ), patch.object(
            query_logic, "build_structural_hits", lambda *args, **kwargs: []
        ), patch.object(
            query_logic, "fetch_related_terms", lambda *args, **kwargs: []
        ):
            result = query_logic.query_documents(
                body,
                rag_service=RagService(),
                get_provider=lambda name: Provider(),
            )

        self.assertTrue(result["ok"])
        self.assertEqual([hit["payload"]["title"] for hit in result["hits"]], ["Graph", "Vector"])
        self.assertEqual(result["retrieval"]["context_docs"], 2)

    def test_query_execution_module_injection_and_flow(self) -> None:
        from application.workflows import query_execution

        calls: list[str] = []
        fast_mode_calls: list[bool] = []
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

        def fetch_scoped_terms(terms: list[str], **kwargs: object) -> list[dict[str, str]]:
            kg_term_calls.append(terms)
            graph_calls.append(("kg", kwargs))
            return [{"subject": "HAWKI", "predicate": "related", "object": "RAG"}]

        result = query_execution.run_query_documents(
            body,
            rag_service=SimpleNamespace(rerank_hits=lambda **kwargs: [{"id": "fallback", "score": 0.1, "payload": {"title": "Fallback", "content": "", "doc_id": "z"}}]),
            get_provider=lambda name: Provider(),
            qdrant_ctor=_ScopedQdrantStub,
            analyze_prompt_fn=lambda query: {"blocked": False, "issues": [], "sanitized": query},
            enforce_output_safety_fn=lambda answer: {"blocked": False, "issues": [], "answer": answer},
            sanitize_prompt_text_fn=lambda query: query,
            build_query_rewrite_fn=lambda provider, query, **kwargs: {
                "enabled": True,
                "rewritten_query": "rewritten",
                "high_level_keys": ["toys"],
                "low_level_keys": ["trains"],
                "entity_terms": ["train"],
                "modality_hints": [],
            },
            build_query_terms_fn=lambda rewritten_query, high_level_keys, low_level_keys, entity_terms: ["train", "toy"],
            run_search_fn=lambda **kwargs: [
                {"id": "a", "score": 0.6, "payload": {"title": "A", "component_type": "chunk", "content": "train", "doc_id": "a"}},
                {"id": "b", "score": 0.4, "payload": {"title": "B", "component_type": "chunk", "content": "toy", "doc_id": "b"}},
            ],
            keyword_fallback_fn=lambda *args, **kwargs: [],
            build_structural_hits_fn=lambda *args, **kwargs: graph_calls.append(
                ("structural", kwargs)
            ) or [{"id": "s", "payload": {"component_type": "relation", "title": "s"}}],
            structural_hops_fn=lambda: 1,
            structural_limit_fn=lambda top_k: top_k,
            fusion_weights_fn=lambda: (1.0, 0.5),
            rerank_and_filter_hits_fn=lambda hits, **kwargs: hits,
            should_iterate_fn=lambda query, hits, top_k: False,
            collect_expansion_terms_fn=lambda hits: ["x"],
            merge_hits_fn=lambda primary, secondary, limit: secondary + primary,
            build_fused_hits_fn=lambda sem_hits, struct_hits, sem_weight=0.0, str_weight=0.0: sem_hits + struct_hits,
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
            set_fast_mode_fn=lambda enabled: fast_mode_calls.append(enabled),
        )

        self.assertEqual(result["ok"], True)
        self.assertEqual(len(result["hits"]), 3)
        self.assertEqual(result["count"], 3)
        self.assertTrue(any(h["id"] == "a" for h in result["hits"]))
        self.assertEqual(result["retrieval"]["iterative_pass"], False)
        self.assertIn("embed", calls)
        self.assertEqual(fast_mode_calls, [False])
        self.assertEqual([call[0] for call in graph_calls], ["structural", "kg"])
        for _operation, scope in graph_calls:
            self.assertEqual(scope["dataset_id"], "dataset-a")
            self.assertEqual(scope["neo4j_namespace"], "hawki_dataset_a")
        self.assertEqual(kg_term_calls, [["rewritten", "train", "toy", "kg"]])

    def test_query_execution_fast_mode_setter_is_injected(self) -> None:
        from application.workflows import query_execution

        body = SimpleNamespace(
            query="fast mode",
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
            rerank_top_n=4,
            mix_mode=False,
            mix_weight=0.25,
        )

        fast_mode_calls: list[bool] = []
        query_execution.run_query_documents(
            body,
            rag_service=SimpleNamespace(rerank_hits=lambda **kwargs: []),
            get_provider=lambda name: SimpleNamespace(
                embed=lambda text: [0.1, 0.2, 0.3],
                embed_model="embed-model",
                rag_model="rag-model",
            ),
            qdrant_ctor=_ScopedQdrantStub,
            analyze_prompt_fn=lambda query: {"blocked": False, "issues": [], "sanitized": query},
            enforce_output_safety_fn=lambda answer: {"blocked": False, "issues": [], "answer": answer},
            sanitize_prompt_text_fn=lambda query: query,
            build_query_rewrite_fn=lambda provider, query, **kwargs: {
                "enabled": False,
                "rewritten_query": query,
                "high_level_keys": [],
                "low_level_keys": [],
                "entity_terms": [],
                "modality_hints": [],
            },
            build_query_terms_fn=lambda rewritten_query, high_level_keys, low_level_keys, entity_terms: [],
            run_search_fn=lambda **kwargs: [],
            keyword_fallback_fn=lambda *args, **kwargs: [],
            build_structural_hits_fn=lambda *args, **kwargs: [],
            structural_hops_fn=lambda: 0,
            structural_limit_fn=lambda top_k: top_k,
            rerank_and_filter_hits_fn=lambda hits, **kwargs: hits,
            run_high_recall_fn=lambda **kwargs: [],
            fetch_related_terms_fn=lambda terms, limit: [],
            set_fast_mode_fn=lambda enabled: fast_mode_calls.append(enabled),
        )

        self.assertEqual(fast_mode_calls, [True])

    def test_query_stages_rewrite_contract_is_multimodal_and_dedupe_terms(self) -> None:
        from application.workflows import query_stages

        with patch.object(
            query_stages,
            "_is_multimodal_query",
            return_value=True,
        ) as multimodal_check, patch.object(
            query_stages,
            "_rewrite_query",
            return_value={
                "rewritten_query": "Compare wooden blocks and toy trains",
                "high_level_keys": ["toys", "toys"],
                "low_level_keys": ["blocks", ""],
                "modality_hints": ["image", None],
                "entity_terms": ["train", "train"],
            },
        ):
            rewrite = query_stages.build_query_rewrite(
                SimpleNamespace(chat=lambda system, messages: "{}"),
                "How to show toy train figure?",
                fast_mode=False,
            )

        self.assertEqual(rewrite["enabled"], True)
        self.assertEqual(rewrite["high_level_keys"], ["toys", "toys"])
        self.assertEqual(rewrite["low_level_keys"], ["blocks"])
        self.assertEqual(rewrite["modality_hints"], ["image"])
        self.assertEqual(rewrite["entity_terms"], ["train", "train"])

        with patch.object(query_stages, "_extract_terms", return_value=["compare"]):
            query_terms = query_stages.build_query_terms(
                "Compare wooden blocks and toy trains",
                rewrite["high_level_keys"],
                rewrite["low_level_keys"],
                rewrite["entity_terms"],
            )

        self.assertEqual(query_terms, ["train", "blocks", "toys", "compare"])
        multimodal_check.assert_called_once()

    def test_query_stages_rerank_and_filter_preserves_best_path_and_fallback(self) -> None:
        from application.workflows import query_stages

        class RerankService:
            def __init__(self) -> None:
                self.calls = 0

            def rerank_hits(self, **kwargs) -> list[dict[str, object]]:
                self.calls += 1
                return [
                    {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
                    {"id": "b", "score": 0.6, "payload": {"title": "Beta"}},
                    {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
                ]

        rerank_service = RerankService()
        hits = [
            {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
            {"id": "b", "score": 0.6, "payload": {"title": "Beta"}},
            {"id": "c", "score": 0.4, "payload": {"title": "Gamma"}},
        ]
        provider = SimpleNamespace(embed_model="toy", rag_model="rag")

        with patch.object(query_stages, "apply_lexical_boost", return_value=[]):
            no_match = query_stages.rerank_and_filter_hits(
                hits,
                user_query="unrelated",
                provider=provider,
                query_vector=[0.1],
                rag_service=rerank_service,
                mode="none",
                top_n=12,
                mix_mode=False,
                mix_weight=0.4,
                min_score=0.5,
                fallback_min=0.3,
                top_k=2,
            )

        self.assertEqual([item["id"] for item in no_match], ["b"])
        self.assertEqual(rerank_service.calls, 1)

        with patch.object(query_stages, "apply_lexical_boost", return_value=[]), patch.object(
            query_stages,
            "_extract_terms",
            return_value=["compare"],
        ):
            fallback = query_stages.filter_hits_by_score(
                hits,
                query="unrelated",
                min_score=0.9,
                fallback_min=0.9,
                top_k=2,
            )

        self.assertEqual([item["id"] for item in fallback], ["a", "b"])


    def test_query_rewrite_module_handles_injected_policy_dependencies(self) -> None:
        from application.workflows import query_rewrite

        rewrite = query_rewrite.build_query_rewrite(
            SimpleNamespace(chat=lambda system, messages: "{}"),
            "Show toy planes and trains",
            fast_mode=False,
            is_multimodal_query=lambda text: True,
            rewrite_query=lambda provider, text: {
                "rewritten_query": "visual toy planes",
                "high_level_keys": ["toys", "", None, "toys"],
                "low_level_keys": ["planes", None],
                "modality_hints": [None, "image"],
                "entity_terms": ["train", "train"],
            },
            normalize_list=lambda values: [v for v in (values or []) if v],
        )

        self.assertEqual(
            rewrite["high_level_keys"],
            ["toys", "toys"],
        )
        self.assertEqual(rewrite["low_level_keys"], ["planes"])
        self.assertEqual(rewrite["modality_hints"], ["image"])
        self.assertEqual(rewrite["entity_terms"], ["train", "train"])

        terms = query_rewrite.build_query_terms(
            "Visual toy planes",
            rewrite["high_level_keys"],
            rewrite["low_level_keys"],
            rewrite["entity_terms"],
            extract_terms=lambda query: ["visual", "train", "train"],
        )
        self.assertEqual(terms, ["train", "planes", "toys", "visual"])

    def test_query_ranking_module_iterate_and_expansion_terms(self) -> None:
        from application.workflows import query_ranking

        class RerankService:
            def rerank_hits(self, **kwargs) -> list[dict[str, object]]:
                return [
                    {"id": "b", "score": 0.8, "payload": {"title": "Beta"}},
                    {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
                ]

        reranked = query_ranking.rerank_and_filter_hits(
            [{"id": "x", "score": 0.5}],
            user_query="toy train",
            provider=SimpleNamespace(),
            query_vector=[0.1],
            rag_service=RerankService(),
            mode="none",
            top_n=5,
            mix_mode=False,
            mix_weight=0.5,
            min_score=0.7,
            fallback_min=0.7,
            top_k=1,
            filter_hits=lambda hits, **kwargs: hits,
        )
        self.assertEqual(
            reranked,
            [
                {"id": "b", "score": 0.8, "payload": {"title": "Beta"}},
                {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
            ],
        )

        self.assertTrue(query_ranking.should_iterate("then compare toy trains", [{"score": 0.8}], top_k=3))
        self.assertFalse(query_ranking.should_iterate("toy blocks", [{"score": 0.9}, {"score": 0.8}, {"score": 0.7}], top_k=3))

        expansion = query_ranking.collect_expansion_terms(
            [
                {"payload": {"content": "blocks for kids"}},
                {"payload": {"content": "builds and more"}},
            ],
            limit=2,
            extract_terms=lambda text: ["blocks", "blocks", "toys", ""],
        )
        self.assertEqual(expansion, ["blocks", "toys"])
