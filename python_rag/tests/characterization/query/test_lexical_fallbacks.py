"""Query scenarios from lexical fallback and rewrite through scoped ranking and context assembly."""

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))

from characterization_support import (
    install_optional_dependency_stubs,
)

install_optional_dependency_stubs()


class QueryCharacterizationTests(unittest.TestCase):
    """Protect query settings, fallback, deduplication, reranking, rewriting, and execution flow."""

    def test_query_lexical_helpers_fold_fuzzy_match_and_boost_scores(self) -> None:
        from hawki_bridge.application.query.lexical import (
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
                "payload": {
                    "title": "Other",
                    "content": "Unrelated text",
                    "doc_id": "doc-b",
                },
            },
        ]

        boosted = lexical_boost_hits(hits, "Bauklötze Holzspielzeug")

        self.assertEqual(
            fold_text("Bauklötze für große Kinder"), "bauklotze fur grosse kinder"
        )
        self.assertTrue(fuzzy_term_in_words("blocks", ["block"]))
        self.assertIn("bauklotze", extract_query_terms_for_lexical("Bauklötze"))
        self.assertEqual([hit["payload"]["doc_id"] for hit in boosted], ["doc-a"])
        self.assertGreater(boosted[0]["score"], 0.1)

    def test_query_lexical_terms_keep_ordinals_and_remove_dataset_instructions(
        self,
    ) -> None:
        from hawki_bridge.application.query.lexical import (
            extract_query_terms_for_lexical,
        )

        terms = extract_query_terms_for_lexical(
            "Was ist die dritte Mahnung in mein Dataset?"
        )

        self.assertIn("dritte", terms)
        self.assertIn("mahnung", terms)
        self.assertNotIn("dataset", terms)
        self.assertNotIn("mein", terms)

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

    def test_query_fallback_uses_text_search_then_relaxed_scroll(self) -> None:
        from hawki_bridge.application.query.fallback import keyword_fallback_search

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

        with patch.dict(
            os.environ,
            {"RAG_EXHAUSTIVE_TEXT": "false", "QDRANT_TEXT_SCROLL_LIMIT": "10"},
            clear=False,
        ):
            hits = keyword_fallback_search(Qdrant(), [0.1], "wooden toys", 3)

        self.assertEqual([hit["payload"]["doc_id"] for hit in hits], ["doc-b", "doc-a"])
        self.assertEqual(calls, [("search", None), ("scroll", True), ("scroll", False)])

    def test_query_fallback_uses_injected_scroll_controls(self) -> None:
        from hawki_bridge.application.query.fallback import keyword_fallback_search

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
