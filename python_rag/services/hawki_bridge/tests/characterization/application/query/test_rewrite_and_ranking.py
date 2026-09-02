"""Query scenarios from lexical boosting and rewrite through scoped ranking and context assembly."""

from __future__ import annotations

import os
import unittest
from types import SimpleNamespace
from unittest.mock import patch


class QueryCharacterizationTests(unittest.TestCase):
    """Protect query settings, fallback, deduplication, reranking, rewriting, and execution flow."""

    def test_query_rewrite_contract_is_multimodal_and_dedupes_terms(self) -> None:
        from hawki_bridge.application.query import rewrite as query_rewrite

        with (
            patch.object(
                query_rewrite,
                "is_multimodal_query",
                return_value=True,
            ) as multimodal_check,
            patch.object(
                query_rewrite,
                "request_query_rewrite",
                return_value={
                    "rewritten_query": "Compare wooden blocks and toy trains",
                    "high_level_keys": ["toys", "toys"],
                    "low_level_keys": ["blocks", ""],
                    "modality_hints": ["image", None],
                    "entity_terms": ["train", "train"],
                },
            ),
        ):
            rewrite = query_rewrite.build_query_rewrite(
                SimpleNamespace(chat=lambda system, messages: "{}"),
                "How to show toy train figure?",
                fast_mode=False,
            )

        self.assertEqual(rewrite["enabled"], True)
        self.assertEqual(rewrite["high_level_keys"], ["toys", "toys"])
        self.assertEqual(rewrite["low_level_keys"], ["blocks"])
        self.assertEqual(rewrite["modality_hints"], ["image"])
        self.assertEqual(rewrite["entity_terms"], ["train", "train"])

        with patch.object(
            query_rewrite,
            "query_terms",
            return_value=SimpleNamespace(terms=["compare"]),
        ):
            query_terms = query_rewrite.build_query_terms(
                "Compare wooden blocks and toy trains",
                rewrite["high_level_keys"],
                rewrite["low_level_keys"],
                rewrite["entity_terms"],
            )

        self.assertEqual(query_terms, ["train", "blocks", "toys", "compare"])
        multimodal_check.assert_called_once()

    def test_disabled_query_rewrite_returns_complete_normalized_shape(self) -> None:
        from hawki_bridge.application.query import rewrite as query_rewrite

        with patch.object(query_rewrite, "request_query_rewrite") as rewrite_request:
            rewrite = query_rewrite.build_query_rewrite(
                SimpleNamespace(chat=lambda system, messages: "{}"),
                "plain text query",
                fast_mode=True,
            )

        self.assertEqual(
            rewrite,
            {
                "enabled": False,
                "raw": {},
                "high_level_keys": [],
                "low_level_keys": [],
                "modality_hints": [],
                "entity_terms": [],
                "rewritten_query": None,
            },
        )
        rewrite_request.assert_not_called()

    def test_query_ranking_preserves_best_path_and_fallback(
        self,
    ) -> None:
        from hawki_bridge.application.query import ranking as query_ranking

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

        no_match = query_ranking.rerank_and_filter_hits(
            hits,
            user_query="unrelated",
            provider=provider,
            query_vector=[0.1],
            rerank_hits=rerank_service.rerank_hits,
            mode="none",
            top_n=12,
            mix_mode=False,
            mix_weight=0.4,
            min_score=0.5,
            fallback_min=0.3,
            top_k=2,
            filter_hits=lambda ranked, **kwargs: query_ranking.select_ranked_hits(
                ranked,
                apply_lexical_boost=lambda selected, query: [],
                **kwargs,
            ),
        )

        self.assertEqual([item["id"] for item in no_match], ["b"])
        self.assertEqual(rerank_service.calls, 1)

        fallback = query_ranking.select_ranked_hits(
            hits,
            query="unrelated",
            min_score=0.9,
            fallback_min=0.9,
            top_k=2,
            apply_lexical_boost=lambda selected, query: [],
        )

        self.assertEqual([item["id"] for item in fallback], ["a", "b"])

    def test_query_rewrite_module_normalizes_model_response(self) -> None:
        from hawki_bridge.application.query import rewrite as query_rewrite

        with (
            patch.object(query_rewrite, "is_multimodal_query", return_value=True),
            patch.object(
                query_rewrite,
                "request_query_rewrite",
                return_value={
                    "rewritten_query": "visual toy planes",
                    "high_level_keys": ["toys", "", None, "toys"],
                    "low_level_keys": ["planes", None],
                    "modality_hints": [None, "image"],
                    "entity_terms": ["train", "train"],
                },
            ),
        ):
            rewrite = query_rewrite.build_query_rewrite(
                SimpleNamespace(chat=lambda system, messages: "{}"),
                "Show toy planes and trains",
                fast_mode=False,
            )

        self.assertEqual(
            rewrite["high_level_keys"],
            ["toys", "toys"],
        )
        self.assertEqual(rewrite["low_level_keys"], ["planes"])
        self.assertEqual(rewrite["modality_hints"], ["image"])
        self.assertEqual(rewrite["entity_terms"], ["train", "train"])

        with patch.object(
            query_rewrite,
            "query_terms",
            return_value=SimpleNamespace(terms=["visual", "train", "train"]),
        ):
            terms = query_rewrite.build_query_terms(
                "Visual toy planes",
                rewrite["high_level_keys"],
                rewrite["low_level_keys"],
                rewrite["entity_terms"],
            )
        self.assertEqual(terms, ["train", "planes", "toys", "visual"])

    def test_query_ranking_module_iterate_and_expansion_terms(self) -> None:
        from hawki_bridge.application.query import ranking as query_ranking

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
            rerank_hits=RerankService().rerank_hits,
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

        self.assertTrue(
            query_ranking.should_expand_retrieval(
                "then compare toy trains", [{"score": 0.8}], top_k=3
            )
        )
        self.assertFalse(
            query_ranking.should_expand_retrieval(
                "toy blocks", [{"score": 0.9}, {"score": 0.8}, {"score": 0.7}], top_k=3
            )
        )

        expansion = query_ranking.collect_expansion_terms(
            [
                {"payload": {"content": "blocks for kids"}},
                {"payload": {"content": "builds and more"}},
            ],
            limit=2,
            extract_terms_fn=lambda text: ["blocks", "blocks", "toys", ""],
        )
        self.assertEqual(expansion, ["blocks", "toys"])

    def test_query_lexical_boost_folds_fuzzy_matches_and_boosts_scores(self) -> None:
        from hawki_bridge.application.query.lexical import (
            boost_lexical_hits,
            fold_text,
            fuzzy_term_in_words,
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
                "payload": {
                    "title": "Other",
                    "content": "Unrelated text",
                    "doc_id": "doc-b",
                },
            },
        ]

        boosted = boost_lexical_hits(hits, "Bauklötze Holzspielzeug")

        self.assertEqual(
            fold_text("Bauklötze für große Kinder"), "bauklotze fur grosse kinder"
        )
        self.assertTrue(fuzzy_term_in_words("blocks", ["block"]))
        self.assertEqual([hit["payload"]["doc_id"] for hit in boosted], ["doc-a"])
        self.assertGreater(boosted[0]["score"], 0.1)
        self.assertEqual(boosted[0]["score"], 0.25)

    def test_query_settings_parse_env_with_caps_and_fallbacks(self) -> None:
        from hawki_bridge.application.query.settings import (
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
