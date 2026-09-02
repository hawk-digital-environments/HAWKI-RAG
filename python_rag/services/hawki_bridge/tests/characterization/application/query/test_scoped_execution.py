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

    def fetch_related_graph(self, *_args: Any, **_kwargs: Any) -> list[Any]:
        raise AssertionError("disabled graph fact retrieval must not run")


def _request(*, generate: bool, filters: dict[str, Any] | None = None):
    from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope
    from hawki_rag_contracts.retrieval.query import QueryRequest

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

    def execute_expansion_case(self, expanded_vector: Any) -> dict[str, Any]:
        from hawki_bridge.application.dependencies import QueryDependencies
        from hawki_bridge.application.query.execution import execute_authorized_query

        captured: dict[str, Any] = {}

        class ExpansionProvider(_Provider):
            def __init__(self) -> None:
                super().__init__([])
                self.embedding_calls = 0
                self.expanded_query = ""
                self.original_vector = [0.1, 0.2]

            def embed(self, text: str) -> list[float]:
                self.embedding_calls += 1
                if self.embedding_calls == 1:
                    return self.original_vector
                self.expanded_query = text
                return expanded_vector

        class ExpansionVectorSearch(_VectorSearch):
            def search_high_recall(self, **kwargs: Any) -> list[dict[str, Any]]:
                captured["iterative_calls"] = captured.get("iterative_calls", 0) + 1
                captured["iterative_vector"] = kwargs["vector"]
                return super().search_high_recall(**kwargs)

        provider = ExpansionProvider()
        vector_search = ExpansionVectorSearch(
            [
                {
                    "id": "first-pass",
                    "score": 0.9,
                    "payload": {
                        "dataset_id": "dataset-a",
                        "component_type": "chunk",
                        "content": "expansion concepts relationships",
                    },
                }
            ],
            captured,
        )
        dependencies = QueryDependencies(
            vector_search_factory=lambda: vector_search,
            graph_search=_DisabledGraph(),
            resolve_model_provider=lambda _name: provider,
            rerank_hits=lambda *, hits, **kwargs: hits,
        )
        request = _request(
            generate=False,
            filters={"dataset_id": "dataset-b", "source_format": "pdf"},
        )

        with (
            patch.dict(
                os.environ,
                {
                    "RAG_GENERATE_ANSWER": "false",
                    "RAG_ITERATIVE_RETRIEVAL": "true",
                    "RAG_MIN_SCORE": "0.0",
                },
                clear=False,
            ),
            patch(
                "hawki_bridge.application.query.execution.logger.warning"
            ) as log_warning,
        ):
            result = execute_authorized_query(request, dependencies=dependencies)

        return {
            "captured": captured,
            "log_warning": log_warning,
            "provider": provider,
            "request": request,
            "result": result,
        }

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

    def test_invalid_vectors(self) -> None:
        invalid_cases = [
            ("empty", [], "empty"),
            ("tuple", (0.1, 0.2), "shape"),
            ("bool", [True, False], "type"),
            ("nan", [float("nan"), 0.2], "non_finite"),
            ("positive_inf", [float("inf"), 0.2], "non_finite"),
            ("negative_inf", [float("-inf"), 0.2], "non_finite"),
            ("numeric_strings", ["0.1", "0.2"], "type"),
            ("strings", ["x", "y"], "type"),
            ("none", [None, 0.2], "type"),
            ("nested", [[0.1], [0.2]], "type"),
            ("short", [0.1], "dimension"),
            ("long", [0.1, 0.2, 0.3], "dimension"),
        ]

        for case_name, expanded_vector, expected_reason in invalid_cases:
            with self.subTest(case=case_name):
                outcome = self.execute_expansion_case(expanded_vector)
                captured = outcome["captured"]
                provider = outcome["provider"]
                request = outcome["request"]
                result = outcome["result"]
                log_warning = outcome["log_warning"]
                actual_dim = (
                    len(expanded_vector) if isinstance(expanded_vector, list) else None
                )

                log_warning.assert_called_once_with(
                    "query:expansion_embedding invalid provider=%s dataset_id=%s "
                    "reason=%s expected_dim=%s actual_dim=%s "
                    "fallback=original_vector",
                    "litellm",
                    "dataset-a",
                    expected_reason,
                    2,
                    actual_dim,
                )
                warning_values = " ".join(
                    str(value) for value in log_warning.call_args.args
                )
                self.assertNotIn(request.query, warning_values)
                self.assertNotIn(provider.expanded_query, warning_values)
                self.assertNotIn(str(expanded_vector), warning_values)

                self.assertEqual(provider.embedding_calls, 2)
                self.assertIn("Key entities:", provider.expanded_query)
                self.assertEqual(captured["iterative_calls"], 1)
                self.assertIs(captured["iterative_vector"], provider.original_vector)
                self.assertEqual(
                    captured["iterative_filters"],
                    {"source_format": "pdf", "dataset_id": "dataset-a"},
                )
                self.assertEqual([hit.id for hit in result.hits], ["first-pass"])
                self.assertIs(result.retrieval["iterative_pass"], True)
                self.assertTrue(result.retrieval["expansion_terms"])

    def test_valid_vectors(self) -> None:
        for case_name, expanded_vector in (
            ("integers", [1, 2]),
            ("floats", [0.3, 0.4]),
        ):
            with self.subTest(case=case_name):
                outcome = self.execute_expansion_case(expanded_vector)
                captured = outcome["captured"]
                provider = outcome["provider"]
                result = outcome["result"]

                outcome["log_warning"].assert_not_called()
                self.assertEqual(provider.embedding_calls, 2)
                self.assertIs(captured["iterative_vector"], expanded_vector)
                self.assertIsNot(captured["iterative_vector"], provider.original_vector)
                self.assertEqual(
                    captured["iterative_filters"],
                    {"source_format": "pdf", "dataset_id": "dataset-a"},
                )
                self.assertEqual([hit.id for hit in result.hits], ["first-pass"])
                self.assertIs(result.retrieval["iterative_pass"], True)
                self.assertTrue(result.retrieval["expansion_terms"])

    def test_expansion_embedding_failure_logs_safe_metadata_and_reuses_original_vector(
        self,
    ) -> None:
        from hawki_bridge.application.dependencies import QueryDependencies
        from hawki_bridge.application.query.execution import execute_authorized_query

        secret_error = "DO-NOT-LOG-THIS-SECRET"
        original_vector = [0.1, 0.2]
        captured: dict[str, Any] = {}

        class ExpansionFailureProvider(_Provider):
            def __init__(self) -> None:
                super().__init__([])
                self.embedding_calls = 0
                self.expanded_query = ""

            def embed(self, text: str) -> list[float]:
                self.embedding_calls += 1
                if self.embedding_calls == 1:
                    return super().embed(text)
                self.expanded_query = text
                raise RuntimeError(secret_error)

        class ExpansionVectorSearch(_VectorSearch):
            def search_high_recall(self, **kwargs: Any) -> list[dict[str, Any]]:
                captured["iterative_calls"] = captured.get("iterative_calls", 0) + 1
                captured["iterative_vector"] = kwargs["vector"]
                return super().search_high_recall(**kwargs)

        provider = ExpansionFailureProvider()
        vector_search = ExpansionVectorSearch(
            [
                {
                    "id": "first-pass",
                    "score": 0.9,
                    "payload": {
                        "dataset_id": "dataset-a",
                        "component_type": "chunk",
                        "content": "expansion concepts relationships",
                    },
                }
            ],
            captured,
        )
        dependencies = QueryDependencies(
            vector_search_factory=lambda: vector_search,
            graph_search=_DisabledGraph(),
            resolve_model_provider=lambda _name: provider,
            rerank_hits=lambda *, hits, **kwargs: hits,
        )
        request = _request(
            generate=False,
            filters={"dataset_id": "dataset-b", "source_format": "pdf"},
        )

        with (
            patch.dict(
                os.environ,
                {
                    "RAG_GENERATE_ANSWER": "false",
                    "RAG_ITERATIVE_RETRIEVAL": "true",
                    "RAG_MIN_SCORE": "0.0",
                },
                clear=False,
            ),
            patch(
                "hawki_bridge.application.query.execution.logger.warning"
            ) as log_warning,
        ):
            result = execute_authorized_query(request, dependencies=dependencies)

        log_warning.assert_called_once_with(
            "query:expansion_embedding failed provider=%s dataset_id=%s "
            "error=%s fallback=original_vector",
            "litellm",
            "dataset-a",
            "RuntimeError",
        )
        warning_values = " ".join(str(value) for value in log_warning.call_args.args)
        self.assertNotIn(request.query, warning_values)
        self.assertNotIn(provider.expanded_query, warning_values)
        self.assertNotIn(secret_error, warning_values)

        self.assertEqual(provider.embedding_calls, 2)
        self.assertIn("Key entities:", provider.expanded_query)
        self.assertEqual(captured["iterative_calls"], 1)
        self.assertEqual(captured["iterative_vector"], original_vector)
        self.assertEqual(
            captured["iterative_filters"],
            {"source_format": "pdf", "dataset_id": "dataset-a"},
        )
        self.assertEqual([hit.id for hit in result.hits], ["first-pass"])
        self.assertIs(result.retrieval["iterative_pass"], True)
        self.assertTrue(result.retrieval["expansion_terms"])

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
