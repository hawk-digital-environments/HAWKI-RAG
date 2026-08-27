"""Dataset-scoped query scenarios for model, storage, and graph boundaries."""

from __future__ import annotations

import os
import unittest
from typing import Any
from unittest.mock import patch


class _Provider:
    embed_model = "initial-embedding"
    rag_model = "initial-chat"
    vision_model = "initial-vision"

    def __init__(self, calls: list[dict[str, Any]]) -> None:
        self.calls = calls

    def embed(self, text: str) -> list[float]:
        self.calls.append(
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
        self.calls.append(
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


class _VectorSearch:
    def __init__(
        self,
        hits: list[dict[str, Any]],
        captured: dict[str, Any] | None = None,
    ) -> None:
        self.hits = hits
        self.captured = captured if captured is not None else {}

    def select_scoped_collection(self, collection: str) -> None:
        self.captured["collection"] = collection

    def search_candidates(self, **kwargs: Any) -> list[dict[str, Any]]:
        self.captured["primary_filters"] = kwargs["filters"]
        return list(self.hits)

    def search_high_recall(self, **kwargs: Any) -> list[dict[str, Any]]:
        self.captured["iterative_filters"] = kwargs["filters"]
        return []

    def search_with_text(
        self, _vector: list[float], **kwargs: Any
    ) -> list[dict[str, Any]]:
        self.captured["keyword_filters"] = kwargs["filters"]
        return []

    def scroll_with_text(self, **kwargs: Any) -> list[dict[str, Any]]:
        self.captured["scroll_filters"] = kwargs["filters"]
        return []

    def scroll_with_text_all(self, **kwargs: Any) -> list[dict[str, Any]]:
        return []


class _DisabledGraph:
    def build_structural_hits(self, *_args: Any, **_kwargs: Any) -> list[Any]:
        raise AssertionError("disabled graph retrieval must not run")

    def fetch_related_terms(self, *_args: Any, **_kwargs: Any) -> list[Any]:
        raise AssertionError("disabled graph fact retrieval must not run")


def _request(*, generate: bool, filters: dict[str, Any] | None = None):
    from hawki_rag_contracts.auth_scope import AuthorizedQueryScope
    from hawki_rag_contracts.query import QueryRequest

    return QueryRequest(
        query="find page ten",
        authorized_scope=AuthorizedQueryScope(
            dataset_id="dataset-a",
            qdrant_collection="hawki_dataset_a",
            neo4j_namespace=None,
            embedding_provider="litellm",
            embedding_model="authorized-embedding",
            graph_enabled=False,
        ),
        provider="litellm",
        chat_model="selected-chat",
        vision_model="selected-vision",
        top_k=1,
        filters=filters or {},
        generate=generate,
        structural_hops=0,
        reranker="none",
        rerank_top_n=1,
        mix_mode=False,
    )


class QueryExecutionScopeTests(unittest.TestCase):
    """Verify every external query operation retains the authorized scope."""

    def _run_generation_query(
        self,
        *,
        generate: bool,
    ) -> tuple[Any, list[dict[str, Any]], Any]:
        from hawki_bridge.application.dependencies import QueryDependencies
        from hawki_bridge.application.query.execution import execute_authorized_query

        calls: list[dict[str, Any]] = []
        provider = _Provider(calls)
        vector_search = _VectorSearch(
            [
                {
                    "id": "chunk-1",
                    "score": 0.9,
                    "payload": {
                        "dataset_id": "dataset-a",
                        "component_type": "chunk",
                        "title": "Ten-page PDF",
                        "source_url": "upload://ten-pages.pdf",
                        "content": "Page ten contains the grounded answer.",
                    },
                }
            ]
        )
        dependencies = QueryDependencies(
            vector_search_factory=lambda: vector_search,
            graph_search=_DisabledGraph(),
            resolve_model_provider=lambda _name: provider,
            rerank_hits=lambda *, hits, **kwargs: hits,
        )

        with patch.dict(
            os.environ,
            {
                "RAG_GENERATE_ANSWER": "true",
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
                "RAG_CONTEXT_TOKENS": "100",
                "RAG_CONTEXT_DOCS": "1",
            },
            clear=False,
        ):
            result = execute_authorized_query(
                _request(generate=generate),
                dependencies=dependencies,
            )

        return result, calls, provider

    def test_authorized_model_aliases_apply_before_provider_calls(self) -> None:
        result, calls, provider = self._run_generation_query(generate=True)

        self.assertEqual([call["operation"] for call in calls], ["embed", "chat"])
        for call in calls:
            self.assertEqual(call["embedding_model"], "authorized-embedding")
            self.assertEqual(call["chat_model"], "selected-chat")
            self.assertEqual(call["vision_model"], "selected-vision")
        self.assertEqual(provider._explicit_graph_model, "selected-chat")
        self.assertIn(
            "only from the supplied dataset evidence", calls[1]["system_prompt"]
        )
        self.assertEqual(calls[1]["temperature"], 0.0)
        self.assertEqual(result.answer, "The answer is grounded [Source 1].")

    def test_generate_false_skips_provider_chat(self) -> None:
        result, calls, _provider = self._run_generation_query(generate=False)

        self.assertEqual([call["operation"] for call in calls], ["embed"])
        self.assertEqual(result.answer, "")

    def test_kg_timing_is_recorded_before_it_is_logged(self) -> None:
        with patch("hawki_bridge.application.query.execution.logger.info") as log_info:
            result, _calls, _provider = self._run_generation_query(generate=False)

        kg_timing = result.retrieval["timings_ms"]["kg"]
        kg_log = next(
            call
            for call in log_info.call_args_list
            if call.args and call.args[0] == "query:kg facts=%s ms=%.2f"
        )

        self.assertGreater(kg_timing, 0.0)
        self.assertEqual(kg_log.args[2], kg_timing)

    def test_vector_paths_share_filters_and_disabled_graph_is_not_called(self) -> None:
        from hawki_bridge.application.dependencies import QueryDependencies
        from hawki_bridge.application.query.execution import execute_authorized_query

        captured: dict[str, Any] = {}
        vector_search = _VectorSearch(
            [
                {
                    "id": "a",
                    "score": 0.9,
                    "payload": {
                        "dataset_id": "dataset-a",
                        "component_type": "chunk",
                        "content": "find page ten",
                    },
                }
            ],
            captured,
        )
        provider = _Provider([])
        dependencies = QueryDependencies(
            vector_search_factory=lambda: vector_search,
            graph_search=_DisabledGraph(),
            resolve_model_provider=lambda _name: provider,
            rerank_hits=lambda *, hits, **kwargs: hits,
        )
        request = _request(
            generate=False,
            filters={
                "dataset_id": "dataset-b",
                "qdrant_collection": "hawki_dataset_b",
                "source_format": "pdf",
            },
        ).model_copy(update={"top_k": 2})

        with patch.dict(
            os.environ,
            {
                "RAG_GENERATE_ANSWER": "false",
                "RAG_ITERATIVE_RETRIEVAL": "true",
                "RAG_MIN_SCORE": "0.0",
            },
            clear=False,
        ):
            result = execute_authorized_query(request, dependencies=dependencies)

        expected_filters = {"source_format": "pdf", "dataset_id": "dataset-a"}
        self.assertTrue(result.ok)
        self.assertEqual(captured["collection"], "hawki_dataset_a")
        self.assertEqual(captured["primary_filters"], expected_filters)
        self.assertEqual(captured["keyword_filters"], expected_filters)
        self.assertEqual(captured["scroll_filters"], expected_filters)
        self.assertEqual(captured["iterative_filters"], expected_filters)

    def test_missing_scoped_collection_raises_application_error(self) -> None:
        from hawki_bridge.application.dependencies import QueryDependencies
        from hawki_bridge.application.query.execution import execute_authorized_query
        from hawki_bridge.domain.errors import DatasetVectorStoreNotReadyError

        class MissingVectorSearch(_VectorSearch):
            def search_candidates(self, **_kwargs: Any) -> list[dict[str, Any]]:
                raise DatasetVectorStoreNotReadyError("missing")

        dependencies = QueryDependencies(
            vector_search_factory=lambda: MissingVectorSearch([]),
            graph_search=_DisabledGraph(),
            resolve_model_provider=lambda _name: _Provider([]),
            rerank_hits=lambda *, hits, **kwargs: hits,
        )

        with self.assertRaises(DatasetVectorStoreNotReadyError):
            execute_authorized_query(
                _request(generate=False),
                dependencies=dependencies,
            )
