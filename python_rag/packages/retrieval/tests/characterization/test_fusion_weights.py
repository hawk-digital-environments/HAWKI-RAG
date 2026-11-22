"""Characterization tests for semantic/structural fusion weights and thresholds."""

from __future__ import annotations

import os
import unittest
from unittest.mock import patch


class FusionWeightsCharacterizationTests(unittest.TestCase):
    """Characterize the exact fusion weight behavior: 0.6 semantic / 0.4 structural."""

    def test_fusion_weights_default_to_06_and_04(self) -> None:
        from hawki_rag_retrieval.query.settings import fusion_weights

        with patch.dict(os.environ, {}, clear=True):
            sem_weight, str_weight = fusion_weights()
            self.assertEqual(sem_weight, 0.6)
            self.assertEqual(str_weight, 0.4)

    def test_fusion_weights_respect_env_overrides(self) -> None:
        from hawki_rag_retrieval.query.settings import fusion_weights

        with patch.dict(
            os.environ,
            {"RAG_FUSION_SEM_WEIGHT": "0.7", "RAG_FUSION_STR_WEIGHT": "0.3"},
            clear=False,
        ):
            sem_weight, str_weight = fusion_weights()
            self.assertEqual(sem_weight, 0.7)
            self.assertEqual(str_weight, 0.3)

    def test_fusion_weights_apply_to_hit_scores(self) -> None:
        from hawki_rag_retrieval.query.hits import fuse_retrieval_hits

        sem_hits = [
            {"id": "sem-1", "score": 0.8, "payload": {"doc_id": "doc-a"}},
        ]
        struct_hits = [
            {"id": "struct-1", "score": 0.5, "payload": {"doc_id": "doc-a"}},
        ]

        fused = fuse_retrieval_hits(
            sem_hits, struct_hits, sem_weight=0.6, str_weight=0.4
        )

        # doc-a gets structural score added: 0.8 * 0.6 + 0.5 * 0.4 = 0.48 + 0.20 = 0.68
        self.assertAlmostEqual(fused[0]["score"], 0.68, places=2)


class ScoreThresholdsCharacterizationTests(unittest.TestCase):
    """Characterize primary/fallback thresholds: 0.1 primary, 0.2 fallback (stricter fallback quirk)."""

    def test_score_thresholds_default_to_01_primary_02_fallback(self) -> None:
        from hawki_rag_retrieval.query.settings import score_thresholds

        with patch.dict(os.environ, {}, clear=True):
            primary, fallback = score_thresholds()
            self.assertEqual(primary, 0.1)
            self.assertEqual(fallback, 0.2)

    def test_fallback_threshold_is_stricter_than_primary_quirk(self) -> None:
        """The fallback threshold (0.2) is HIGHER than primary (0.1) - this is a known quirk."""
        from hawki_rag_retrieval.query.settings import score_thresholds

        with patch.dict(os.environ, {}, clear=True):
            primary, fallback = score_thresholds()
            # This is the "stricter-fallback-threshold quirk" - fallback > primary
            self.assertGreater(fallback, primary)

    def test_score_thresholds_respect_env_overrides(self) -> None:
        from hawki_rag_retrieval.query.settings import score_thresholds

        with patch.dict(
            os.environ,
            {"RAG_MIN_SCORE": "0.15", "RAG_MIN_SCORE_FALLBACK": "0.25"},
            clear=False,
        ):
            primary, fallback = score_thresholds()
            self.assertEqual(primary, 0.15)
            self.assertEqual(fallback, 0.25)

    def test_select_ranked_hits_applies_primary_then_fallback_then_topk(self) -> None:
        from hawki_rag_retrieval.query.ranking import select_ranked_hits

        hits = [
            {"id": "a", "score": 0.05, "payload": {"title": "A"}},  # below primary
            {"id": "b", "score": 0.15, "payload": {"title": "B"}},  # primary only
            {"id": "c", "score": 0.25, "payload": {"title": "C"}},  # both
            {"id": "d", "score": 0.35, "payload": {"title": "D"}},  # both
        ]

        # With primary=0.1, fallback=0.2:
        # - a (0.05): below both
        # - b (0.15): primary only (>= 0.1, < 0.2)
        # - c (0.25): both (>= 0.1 and >= 0.2)
        # - d (0.35): both (>= 0.1 and >= 0.2)
        # Primary hits: b, c, d -> return ALL primary hits (not limited by top_k)
        # top_k only applies to the final fallback case
        # Order is preserved from input (not sorted by score)
        result = select_ranked_hits(
            hits,
            query="test",
            min_score=0.1,
            fallback_min=0.2,
            top_k=2,
            apply_lexical_boost=lambda selected, query: [],
        )

        # Current behavior: returns all 3 primary hits in input order
        self.assertEqual(len(result), 3)
        self.assertEqual([hit["id"] for hit in result], ["b", "c", "d"])

    def test_select_ranked_hits_fallback_when_no_primary(self) -> None:
        from hawki_rag_retrieval.query.ranking import select_ranked_hits

        hits = [
            {"id": "a", "score": 0.05, "payload": {"title": "A"}},  # below primary
            {"id": "b", "score": 0.15, "payload": {"title": "B"}},  # primary only
        ]

        # With primary=0.2, fallback=0.15:
        # - a (0.05): below both
        # - b (0.15): fallback only (but fallback < primary, so this is weird)
        # Actually with primary=0.2, fallback=0.15:
        # - a: below both
        # - b: >= fallback (0.15) but < primary (0.2)
        # So primary is empty, fallback has b -> return fallback
        result = select_ranked_hits(
            hits,
            query="test",
            min_score=0.2,
            fallback_min=0.15,
            top_k=2,
            apply_lexical_boost=lambda selected, query: [],
        )

        self.assertEqual(len(result), 1)
        self.assertEqual(result[0]["id"], "b")


class ResultCountBehaviorCharacterizationTests(unittest.TestCase):
    """Characterize max(top_k, max_context_docs) result-count behavior."""

    def test_final_hits_limited_by_max_of_topk_and_context_docs(self) -> None:
        from hawki_rag_retrieval.query.execution import execute_authorized_query
        from hawki_rag_retrieval.dependencies import QueryDependencies
        from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope
        from hawki_rag_contracts.retrieval.query import QueryRequest

        class _VectorSearch:
            def __init__(self, hits: list[dict]):
                self.hits = hits
                self.collection = ""

            def select_scoped_collection(self, collection: str) -> None:
                self.collection = collection

            def search_candidates(self, **kwargs) -> list[dict]:
                return list(self.hits)

            def search_high_recall(self, **kwargs) -> list[dict]:
                return []

            def search_with_text(self, *args, **kwargs) -> list[dict]:
                return []

            def scroll_with_text(self, **kwargs) -> list[dict]:
                return []

            def scroll_with_text_all(self, **kwargs) -> list[dict]:
                return []

        class _Provider:
            embed_model = "provider-embed"
            rag_model = "provider-chat"
            vision_model = "provider-vision"

            def embed(self, text: str) -> list[float]:
                return [0.5, 0.25]

            def chat(self, *args, **kwargs) -> str:
                return "unused"

        # Create 10 hits
        hits = [
            {
                "id": f"hit-{i}",
                "score": 0.9 - i * 0.05,
                "payload": {
                    "content": f"content {i}",
                    "component_type": "chunk",
                    "doc_id": f"doc-{i}",
                },
            }
            for i in range(10)
        ]

        vector_search = _VectorSearch(hits)
        graph_search = type(
            "GraphSearch",
            (),
            {
                "build_structural_hits": lambda self, *args, **kwargs: [],
                "fetch_related_graph": lambda self, *args, **kwargs: [],
            },
        )()

        # Test 1: top_k=5, max_context_docs=6 -> max(5, 6) = 6
        with patch.dict(
            os.environ,
            {
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
                "RAG_CONTEXT_DOCS": "6",
                "RAG_GENERATE_ANSWER": "false",
            },
            clear=False,
        ):
            request = QueryRequest(
                query="test",
                authorized_scope=AuthorizedQueryScope(
                    dataset_id="dataset-a",
                    qdrant_collection="hawki_dataset_a",
                    neo4j_namespace=None,
                    embedding_provider="fake",
                    embedding_model="authorized-embedding",
                    graph_enabled=False,
                ),
                top_k=5,
                provider="fake",
                chat_model="selected-chat",
                vision_model="selected-vision",
                filters={},
                generate=False,
                fast_mode=False,
                structural_hops=1,
                reranker="none",
                rerank_top_n=4,
                mix_mode=False,
                mix_weight=0.25,
            )
            dependencies = QueryDependencies(
                vector_search_factory=lambda: vector_search,
                graph_search=graph_search,
                resolve_model_provider=lambda _name: _Provider(),
                rerank_hits=lambda *, hits, **kwargs: hits,
            )
            result = execute_authorized_query(request, dependencies=dependencies)
            self.assertEqual(result.count, 6)  # max(5, 6) = 6

        # Test 2: top_k=8, max_context_docs=6 -> max(8, 6) = 8
        with patch.dict(
            os.environ,
            {
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
                "RAG_CONTEXT_DOCS": "6",
                "RAG_GENERATE_ANSWER": "false",
            },
            clear=False,
        ):
            request = QueryRequest(
                query="test",
                authorized_scope=AuthorizedQueryScope(
                    dataset_id="dataset-a",
                    qdrant_collection="hawki_dataset_a",
                    neo4j_namespace=None,
                    embedding_provider="fake",
                    embedding_model="authorized-embedding",
                    graph_enabled=False,
                ),
                top_k=8,
                provider="fake",
                chat_model="selected-chat",
                vision_model="selected-vision",
                filters={},
                generate=False,
                fast_mode=False,
                structural_hops=1,
                reranker="none",
                rerank_top_n=4,
                mix_mode=False,
                mix_weight=0.25,
            )
            dependencies = QueryDependencies(
                vector_search_factory=lambda: vector_search,
                graph_search=graph_search,
                resolve_model_provider=lambda _name: _Provider(),
                rerank_hits=lambda *, hits, **kwargs: hits,
            )
            result = execute_authorized_query(request, dependencies=dependencies)
            self.assertEqual(result.count, 8)  # max(8, 6) = 8


class StructuralHopsZeroCharacterizationTests(unittest.TestCase):
    """Characterize structural_hops=0 still permitting separate KG fact retrieval."""

    def test_structural_hops_zero_skips_structural_search_but_allows_kg_facts(
        self,
    ) -> None:
        from hawki_rag_retrieval.query.execution import execute_authorized_query
        from hawki_rag_retrieval.dependencies import QueryDependencies
        from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope
        from hawki_rag_contracts.retrieval.query import QueryRequest

        structural_calls: list[str] = []
        fact_calls: list[str] = []

        class _VectorSearch:
            def __init__(self, hits: list[dict]):
                self.hits = hits
                self.collection = ""

            def select_scoped_collection(self, collection: str) -> None:
                self.collection = collection

            def search_candidates(self, **kwargs) -> list[dict]:
                return list(self.hits)

            def search_high_recall(self, **kwargs) -> list[dict]:
                return []

            def search_with_text(self, *args, **kwargs) -> list[dict]:
                return []

            def scroll_with_text(self, **kwargs) -> list[dict]:
                return []

            def scroll_with_text_all(self, **kwargs) -> list[dict]:
                return []

        class _GraphSearch:
            def build_structural_hits(self, *_args, **_kwargs) -> list[dict]:
                structural_calls.append("structural")
                return []

            def fetch_related_graph(self, *_args, **_kwargs) -> list[dict]:
                fact_calls.append("facts")
                return [{"subject": "Test", "relation": "IS", "object": "Entity"}]

        class _Provider:
            embed_model = "provider-embed"
            rag_model = "provider-chat"
            vision_model = "provider-vision"

            def embed(self, text: str) -> list[float]:
                return [0.5, 0.25]

            def chat(self, *args, **kwargs) -> str:
                return "unused"

        hits = [
            {
                "id": "hit-1",
                "score": 0.9,
                "payload": {
                    "content": "test content",
                    "component_type": "chunk",
                    "doc_id": "doc-1",
                },
            }
        ]

        vector_search = _VectorSearch(hits)
        graph_search = _GraphSearch()

        with patch.dict(
            os.environ,
            {
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
                "RAG_GENERATE_ANSWER": "false",
            },
            clear=False,
        ):
            request = QueryRequest(
                query="test",
                authorized_scope=AuthorizedQueryScope(
                    dataset_id="dataset-a",
                    qdrant_collection="hawki_dataset_a",
                    neo4j_namespace="hawki_dataset_a",
                    embedding_provider="fake",
                    embedding_model="authorized-embedding",
                    graph_enabled=True,
                ),
                top_k=2,
                provider="fake",
                chat_model="selected-chat",
                vision_model="selected-vision",
                filters={},
                generate=False,
                fast_mode=False,
                structural_hops=0,  # Zero hops!
                reranker="none",
                rerank_top_n=4,
                mix_mode=False,
                mix_weight=0.25,
            )
            dependencies = QueryDependencies(
                vector_search_factory=lambda: vector_search,
                graph_search=graph_search,
                resolve_model_provider=lambda _name: _Provider(),
                rerank_hits=lambda *, hits, **kwargs: hits,
            )
            result = execute_authorized_query(request, dependencies=dependencies)

            # Structural search should NOT be called (hops=0)
            self.assertEqual(structural_calls, [])
            # But KG fact retrieval SHOULD still be called
            self.assertEqual(fact_calls, ["facts"])
            self.assertEqual(len(result.kg), 1)


class RerankerTopNTailRetentionCharacterizationTests(unittest.TestCase):
    """Characterize reranker top-N tail retention behavior."""

    def test_reranker_only_reranks_top_n_retains_tail(self) -> None:
        from hawki_rag_retrieval.adapters import rerank_hits

        # Create 10 hits
        hits = [
            {
                "id": f"hit-{i}",
                "score": 0.5,
                "payload": {"title": f"Doc {i}", "content": f"Content {i}"},
            }
            for i in range(10)
        ]

        class MockProvider:
            def embed(self, text: str) -> list[float]:
                return [0.1] * 384

        # Rerank only top 3 (top_n=3), mode="cosine"
        # The tail (hits[3:]) should be retained in original order
        reranked = rerank_hits(
            hits=hits,
            user_query="test query",
            provider=MockProvider(),
            query_vector=[0.1] * 384,
            mode="cosine",
            top_n=3,
            mix_mode=False,
            mix_weight=0.5,
        )

        # First 3 should be reranked (order may change based on cosine similarity)
        # Hits 3-9 should remain in original order
        self.assertEqual(len(reranked), 10)
        # Tail should be preserved
        for i in range(3, 10):
            self.assertEqual(reranked[i]["id"], f"hit-{i}")

    def test_reranker_none_mode_returns_all_hits_unchanged(self) -> None:
        from hawki_rag_retrieval.adapters import rerank_hits

        hits = [
            {"id": f"hit-{i}", "score": 0.5, "payload": {"title": f"Doc {i}"}}
            for i in range(5)
        ]

        reranked = rerank_hits(
            hits=hits,
            user_query="test",
            provider=None,
            query_vector=None,
            mode="none",
            top_n=3,
            mix_mode=False,
            mix_weight=0.5,
        )

        self.assertEqual(reranked, hits)

    def test_reranker_external_failure_degrades_to_original_order(self) -> None:
        from hawki_rag_retrieval.adapters import rerank_hits
        from unittest.mock import Mock

        hits = [
            {"id": f"hit-{i}", "score": 0.5, "payload": {"title": f"Doc {i}"}}
            for i in range(5)
        ]

        class FailingProvider:
            pass

        failing_client = Mock()
        failing_client.post.side_effect = Exception("Network error")

        reranked = rerank_hits(
            hits=hits,
            user_query="test",
            provider=FailingProvider(),
            query_vector=[0.1] * 384,
            mode="external",
            top_n=3,
            mix_mode=False,
            mix_weight=0.5,
            http_client=failing_client,
        )

        # Should return original hits unchanged
        self.assertEqual(reranked, hits)


class ScopedQdrantRequestCompositionCharacterizationTests(unittest.TestCase):
    """Characterize important scoped Qdrant request composition."""

    def test_scoped_collection_selection_before_search(self) -> None:
        from hawki_rag_retrieval.adapters import QdrantReader
        from unittest.mock import Mock

        # QdrantReader uses default_factory=QdrantHTTP which captures the class at definition time.
        # Pass a mock client directly to verify the delegation.
        mock_client = Mock()
        reader = QdrantReader(client=mock_client)
        reader.select_scoped_collection("hawki_dataset_a")

        mock_client.select_scoped_collection.assert_called_once_with("hawki_dataset_a")

    def test_mandatory_dataset_filter_in_all_search_paths(self) -> None:
        from hawki_rag_retrieval.query.fallback import retrieve_lexical_hits

        calls: list[tuple[str, dict]] = []

        class FakeQdrant:
            def search_with_text(self, vector: list[float], **kwargs) -> list[dict]:
                calls.append(("search", kwargs.get("filters", {})))
                return []

            def scroll_with_text(self, **kwargs) -> list[dict]:
                calls.append(("scroll", kwargs.get("filters", {})))
                if kwargs["require_all"]:
                    return []
                return [
                    {"id": "a", "score": 0.5, "payload": {"dataset_id": "dataset-a"}}
                ]

        _ = retrieve_lexical_hits(
            FakeQdrant(),
            [0.1],
            "test query",
            2,
            filters={"source_format": "pdf", "dataset_id": "dataset-a"},
            text_scroll_limit_fn=lambda top_k: 5,
            exhaustive_text_fn=lambda: False,
        )

        # All calls should receive the mandatory dataset_id filter
        for call_type, filters in calls:
            self.assertEqual(filters.get("dataset_id"), "dataset-a")
            # User filter should also be present
            self.assertEqual(filters.get("source_format"), "pdf")

    def test_reserved_filter_keys_stripped_from_user_filters(self) -> None:
        from hawki_rag_retrieval.query.scope import build_scoped_query_filters

        filters = build_scoped_query_filters(
            "dataset-a",
            {
                "dataset_id": "dataset-b",  # Should be overridden
                "collection": "other",  # Reserved - stripped
                "qdrant_collection": "other",  # Reserved - stripped
                "neo4j_namespace": "other",  # Reserved - stripped
                "qdrantCollection": "other",  # Reserved (camelCase) - stripped
                "neo4j-namespace": "other",  # Reserved (kebab-case) - stripped
                "authContext": "caller",  # Reserved - stripped
                "graph-enabled": True,  # Reserved - stripped
                "source_format": "pdf",  # Should be preserved
            },
        )

        # Only source_format and mandatory dataset_id should remain
        self.assertEqual(filters, {"source_format": "pdf", "dataset_id": "dataset-a"})


class HistoricalLoggerNameCharacterizationTests(unittest.TestCase):
    """Characterize the historical logger name: hawki_bridge.application.query.execution"""

    def test_execution_module_uses_historical_logger_name(self) -> None:
        import hawki_rag_retrieval.query.execution as execution_module

        # The logger name must be exactly "hawki_bridge.application.query.execution"
        self.assertEqual(
            execution_module.logger.name, "hawki_bridge.application.query.execution"
        )

    def test_fallback_module_uses_historical_logger_name(self) -> None:
        import hawki_rag_retrieval.query.fallback as fallback_module

        # The logger name must be exactly "hawki_bridge.application.query.fallback"
        self.assertEqual(
            fallback_module.logger.name, "hawki_bridge.application.query.fallback"
        )

    def test_reranker_client_uses_historical_logger_name(self) -> None:
        import hawki_rag_retrieval.adapters.reranker_client as reranker_module

        # The logger name must be exactly "hawki_bridge.adapters.reranker_client"
        self.assertEqual(
            reranker_module.logger.name, "hawki_bridge.adapters.reranker_client"
        )

    def test_qdrant_reader_uses_historical_logger_name(self) -> None:
        import hawki_rag_retrieval.adapters.qdrant_reader as qdrant_module

        # The logger name must be exactly "hawki_bridge.adapters.qdrant_reader"
        self.assertEqual(
            qdrant_module.logger.name, "hawki_bridge.adapters.qdrant_reader"
        )

    def test_neo4j_reader_uses_historical_logger_name(self) -> None:
        import hawki_rag_retrieval.adapters.neo4j_reader as neo4j_module

        # The logger name must be exactly "hawki_bridge.adapters.neo4j_reader"
        self.assertEqual(neo4j_module.logger.name, "hawki_bridge.adapters.neo4j_reader")


if __name__ == "__main__":
    unittest.main()
