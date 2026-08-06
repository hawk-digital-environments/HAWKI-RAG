"""Dataset-scoped query scenarios covering validation, search fallbacks, and fail-closed errors."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from typing import Any
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[3]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


class _FakeResponse:
    def __init__(self, status_code: int, payload: dict[str, Any] | None = None) -> None:
        self.status_code = status_code
        self._payload = payload or {}
        self.text = ""

    def json(self) -> dict[str, Any]:
        return self._payload

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")


def _qdrant_settings() -> Any:
    from hawki_rag_stores.qdrant.settings import QdrantSettings

    return QdrantSettings(
        scheme="http",
        host="qdrant",
        port=6333,
        collection="global_default",
        api_key=None,
        timeout=1.0,
        max_attempts=1,
    )


def _qdrant_http_settings(*, search_all: bool = True, fallback_all: bool = True) -> Any:
    from hawki_rag_stores.qdrant.settings import QdrantHTTPSettings

    return QdrantHTTPSettings(
        log_latency=False,
        search_all=search_all,
        search_all_per_collection=0,
        fallback_all=fallback_all,
        fallback_per_collection=0,
        upsert_timeout=1.0,
        search_timeout=1.0,
        count_timeout=1.0,
        delete_timeout=1.0,
        text_timeout=1.0,
        text_fallback_terms=3,
        text_scroll_hard_cap=100,
        text_scroll_batch=16,
    )


class QueryExecutionScopeTests(unittest.TestCase):
    """Verify provider, vector, graph, ranking, and generation stages retain authorized scope."""

    def _run_generation_query(
        self,
        *,
        generate: bool,
    ) -> tuple[dict[str, Any], list[dict[str, Any]], Any]:
        from hawki_bridge.application.query.execution import run_query_documents

        calls: list[dict[str, Any]] = []

        class FakeQdrant:
            def select_scoped_collection(self, _collection: str) -> None:
                return None

        class Provider:
            embed_model = "initial-embedding"
            rag_model = "initial-chat"
            vision_model = "initial-vision"

            def embed(self, text: str) -> list[float]:
                calls.append(
                    {
                        "operation": "embed",
                        "text": text,
                        "embedding_model": self.embed_model,
                        "chat_model": self.rag_model,
                        "vision_model": self.vision_model,
                    }
                )
                return [0.1, 0.2]

            def chat(
                self,
                system_prompt: str,
                messages: list[dict[str, str]],
                *,
                temperature: float,
            ) -> str:
                calls.append(
                    {
                        "operation": "chat",
                        "system_prompt": system_prompt,
                        "messages": messages,
                        "temperature": temperature,
                        "embedding_model": self.embed_model,
                        "chat_model": self.rag_model,
                        "vision_model": self.vision_model,
                    }
                )
                return "The answer is grounded [Source 1]."

        provider = Provider()
        body = SimpleNamespace(
            query="find page ten",
            authorized_scope=SimpleNamespace(
                dataset_id="dataset-a",
                qdrant_collection="hawki_dataset_a",
                neo4j_namespace="hawki_dataset_a",
                embedding_provider="litellm",
                embedding_model="authorized-embedding",
                graph_enabled=False,
            ),
            embedding_model="untrusted-request-embedding",
            chat_model="selected-chat",
            vision_model="selected-vision",
            top_k=1,
            provider="litellm",
            filters={},
            generate=generate,
            is_optimized=False,
            fast_mode=False,
            smart_lookup=False,
            structural_hops=0,
            preferred_tags=None,
            reranker="none",
            rerank_top_n=1,
            mix_mode=False,
            mix_weight=0.5,
        )

        result = run_query_documents(
            body,
            rag_service=SimpleNamespace(),
            get_provider=lambda _name: provider,
            qdrant_ctor=FakeQdrant,
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
            build_query_rewrite_fn=lambda _provider, query, **_kwargs: {
                "enabled": False,
                "rewritten_query": query,
                "high_level_keys": [],
                "low_level_keys": [],
                "entity_terms": [],
                "modality_hints": [],
            },
            build_query_terms_fn=lambda *_args: [],
            run_search_fn=lambda **_kwargs: [
                {
                    "id": "chunk-1",
                    "score": 0.9,
                    "payload": {
                        "dataset_id": "dataset-a",
                        "component_type": "chunk",
                        "content": "Page ten contains the grounded answer.",
                    },
                }
            ],
            keyword_fallback_fn=lambda *_args, **_kwargs: [],
            build_fused_hits_fn=lambda semantic, _structural, **_kwargs: semantic,
            rerank_and_filter_hits_fn=lambda hits, **_kwargs: hits,
            prepare_context_fn=lambda _hits, max_docs, max_tokens: (
                [
                    {
                        "idx": 1,
                        "title": "Ten-page PDF",
                        "url": "upload://ten-pages.pdf",
                        "snippet": "Page ten contains the grounded answer.",
                    }
                ],
                [],
                12,
            ),
            context_limits_fn=lambda: (100, 1),
            score_thresholds_fn=lambda: (0.0, 0.0),
            iterative_retrieval_enabled_fn=lambda: False,
            generation_enabled_fn=lambda: True,
            configured_search_top_k_fn=lambda top_k: top_k,
            build_grounded_answer_prompt_fn=lambda _query, _sources, _facts: (
                "grounded system prompt",
                "grounded user prompt",
            ),
        )

        return result, calls, provider

    def test_authorized_embedding_and_selected_chat_vision_aliases_apply_before_provider_calls(
        self,
    ) -> None:
        result, calls, provider = self._run_generation_query(generate=True)

        self.assertEqual([call["operation"] for call in calls], ["embed", "chat"])
        for call in calls:
            self.assertEqual(call["embedding_model"], "authorized-embedding")
            self.assertEqual(call["chat_model"], "selected-chat")
            self.assertEqual(call["vision_model"], "selected-vision")
        self.assertEqual(provider._explicit_graph_model, "selected-chat")
        self.assertEqual(calls[1]["system_prompt"], "grounded system prompt")
        self.assertEqual(
            calls[1]["messages"], [{"role": "user", "content": "grounded user prompt"}]
        )
        self.assertEqual(calls[1]["temperature"], 0.0)
        self.assertEqual(result["answer"], "The answer is grounded [Source 1].")

    def test_generate_false_skips_provider_chat_even_when_context_and_generation_are_enabled(
        self,
    ) -> None:
        result, calls, _provider = self._run_generation_query(generate=False)

        self.assertEqual([call["operation"] for call in calls], ["embed"])
        self.assertEqual(result["answer"], "")

    def test_kg_timing_is_recorded_before_it_is_logged(self) -> None:
        with patch("hawki_bridge.application.query.execution.logger.info") as log_info:
            result, _calls, _provider = self._run_generation_query(generate=False)

        kg_timing = result["retrieval"]["timings_ms"]["kg"]
        kg_log = next(
            call
            for call in log_info.call_args_list
            if call.args and call.args[0] == "query:kg facts=%s ms=%.2f"
        )

        self.assertGreater(kg_timing, 0.0)
        self.assertEqual(kg_log.args[2], kg_timing)

    def test_scoped_vector_paths_share_filters_and_disabled_graph_is_not_called(
        self,
    ) -> None:
        from hawki_bridge.application.query.execution import run_query_documents

        captured: dict[str, Any] = {}

        class FakeQdrant:
            def select_scoped_collection(self, collection: str) -> None:
                captured["collection"] = collection

        class Provider:
            def embed(self, text: str) -> list[float]:
                return [0.1, 0.2]

        body = SimpleNamespace(
            query="find page ten",
            authorized_scope=SimpleNamespace(
                dataset_id="dataset-a",
                qdrant_collection="hawki_dataset_a",
                neo4j_namespace="hawki_dataset_a",
                graph_enabled=False,
            ),
            top_k=2,
            provider="fake",
            filters={
                "dataset_id": "dataset-b",
                "qdrant_collection": "hawki_dataset_b",
                "source_format": "pdf",
            },
            generate=False,
            is_optimized=False,
            fast_mode=False,
            smart_lookup=False,
            structural_hops=2,
            preferred_tags=None,
            reranker="none",
            rerank_top_n=4,
            mix_mode=False,
            mix_weight=0.5,
        )

        def primary_search(**kwargs: Any) -> list[dict[str, Any]]:
            captured["primary_filters"] = kwargs["filters"]
            return [
                {
                    "id": "a",
                    "score": 0.9,
                    "payload": {
                        "dataset_id": "dataset-a",
                        "component_type": "chunk",
                        "content": "page ten",
                    },
                }
            ]

        def keyword_search(*args: Any, **kwargs: Any) -> list[dict[str, Any]]:
            captured["keyword_filters"] = kwargs["filters"]
            return []

        def iterative_search(**kwargs: Any) -> list[dict[str, Any]]:
            captured["iterative_filters"] = kwargs["filters"]
            return []

        result = run_query_documents(
            body,
            rag_service=SimpleNamespace(),
            get_provider=lambda _name: Provider(),
            qdrant_ctor=FakeQdrant,
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
                "enabled": False,
                "rewritten_query": query,
                "high_level_keys": [],
                "low_level_keys": [],
                "entity_terms": [],
                "modality_hints": [],
            },
            build_query_terms_fn=lambda *args: ["page", "ten"],
            run_search_fn=primary_search,
            keyword_fallback_fn=keyword_search,
            build_structural_hits_fn=lambda *args, **kwargs: (_ for _ in ()).throw(
                AssertionError("disabled graph retrieval must not run")
            ),
            structural_hops_fn=lambda: 2,
            structural_limit_fn=lambda top_k: top_k,
            rerank_and_filter_hits_fn=lambda hits, **kwargs: hits,
            should_iterate_fn=lambda query, hits, top_k: True,
            collect_expansion_terms_fn=lambda hits: [],
            merge_hits_fn=lambda primary, secondary, limit: primary + secondary,
            build_fused_hits_fn=lambda semantic, structural, **kwargs: semantic,
            prepare_context_fn=lambda hits, max_docs, max_tokens: ([], [], 0),
            run_high_recall_fn=iterative_search,
            fetch_related_terms_fn=lambda terms, limit: (_ for _ in ()).throw(
                AssertionError("disabled KG retrieval must not run")
            ),
            context_limits_fn=lambda: (100, 2),
            score_thresholds_fn=lambda: (0.0, 0.0),
            iterative_retrieval_enabled_fn=lambda: True,
            generation_enabled_fn=lambda: False,
            configured_search_top_k_fn=lambda top_k: top_k,
            extract_terms_fn=lambda text: [],
            terms_from_payload_fn=lambda payload: [],
        )

        expected_filters = {"source_format": "pdf", "dataset_id": "dataset-a"}
        self.assertTrue(result["ok"])
        self.assertEqual(captured["collection"], "hawki_dataset_a")
        self.assertEqual(captured["primary_filters"], expected_filters)
        self.assertEqual(captured["keyword_filters"], expected_filters)
        self.assertEqual(captured["iterative_filters"], expected_filters)

    def test_missing_scoped_collection_maps_to_dataset_not_ready(self) -> None:
        from fastapi import HTTPException

        from hawki_bridge.application.query.execution import run_query_documents
        from hawki_rag_stores.qdrant.client import ScopedCollectionNotReadyError

        class FakeQdrant:
            def select_scoped_collection(self, collection: str) -> None:
                return None

        body = SimpleNamespace(
            query="find page ten",
            authorized_scope=SimpleNamespace(
                dataset_id="dataset-a",
                qdrant_collection="missing_collection",
                neo4j_namespace=None,
                graph_enabled=False,
            ),
            top_k=1,
            provider="fake",
            filters={},
            generate=False,
            is_optimized=False,
            fast_mode=True,
            smart_lookup=False,
            structural_hops=0,
            preferred_tags=None,
            reranker="none",
            rerank_top_n=1,
            mix_mode=False,
            mix_weight=0.5,
        )

        with self.assertRaises(HTTPException) as raised:
            run_query_documents(
                body,
                rag_service=SimpleNamespace(),
                get_provider=lambda _name: SimpleNamespace(embed=lambda text: [0.1]),
                qdrant_ctor=FakeQdrant,
                analyze_prompt_fn=lambda query: {
                    "blocked": False,
                    "issues": [],
                    "sanitized": query,
                },
                sanitize_prompt_text_fn=lambda query: query,
                build_query_rewrite_fn=lambda provider, query, **kwargs: {
                    "enabled": False,
                    "rewritten_query": query,
                    "high_level_keys": [],
                    "low_level_keys": [],
                    "entity_terms": [],
                    "modality_hints": [],
                },
                build_query_terms_fn=lambda *args: [],
                run_search_fn=lambda **kwargs: (_ for _ in ()).throw(
                    ScopedCollectionNotReadyError("missing")
                ),
                keyword_fallback_fn=lambda *args, **kwargs: [],
            )

        self.assertEqual(raised.exception.status_code, 503)
        self.assertEqual(raised.exception.detail["code"], "dataset_not_ready")
