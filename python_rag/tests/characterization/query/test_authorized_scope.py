"""Dataset-scoped query scenarios covering validation, search fallbacks, and fail-closed errors."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path
from typing import Any

from pydantic import ValidationError


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


class AuthorizedScopeSchemaTests(unittest.TestCase):
    """Verify the Python boundary accepts only Laravel-derived trusted retrieval scope."""

    def test_query_requires_a_typed_authorized_scope(self) -> None:
        from hawki_rag_contracts.query import QueryRequest

        with self.assertRaises(ValidationError):
            QueryRequest(query="Find the PDF")

        request = QueryRequest(
            query="Find the PDF",
            provider="ollama",
            chat_model="llama3.1:8b",
            vision_model="qwen2.5vl:7b",
            authorized_scope={
                "dataset_id": " dataset-a ",
                "qdrant_collection": " hawki_dataset_a ",
                "neo4j_namespace": " hawki_dataset_a ",
                "embedding_provider": " ollama ",
                "embedding_model": " hawki-ollama-embedding ",
                "graph_enabled": False,
            },
        )

        self.assertEqual(request.authorized_scope.dataset_id, "dataset-a")
        self.assertEqual(request.authorized_scope.qdrant_collection, "hawki_dataset_a")
        self.assertEqual(request.authorized_scope.embedding_provider, "ollama")
        self.assertEqual(
            request.authorized_scope.embedding_model, "hawki-ollama-embedding"
        )
        self.assertFalse(request.authorized_scope.graph_enabled)

    def test_scope_rejects_unknown_internal_fields(self) -> None:
        from hawki_rag_contracts.auth_scope import AuthorizedQueryScope
        from hawki_rag_contracts.query import QueryRequest

        with self.assertRaises(ValidationError):
            AuthorizedQueryScope(
                dataset_id="dataset-a",
                qdrant_collection="hawki_dataset_a",
                embedding_provider="ollama",
                embedding_model="hawki-ollama-embedding",
                graph_enabled=False,
                caller_collection="hawki_dataset_b",
            )

        with self.assertRaises(ValidationError):
            QueryRequest(
                query="Find the PDF",
                authorized_scope={
                    "dataset_id": "dataset-a",
                    "qdrant_collection": "hawki_dataset_a",
                },
                collection="hawki_dataset_b",
            )

    def test_trusted_scope_accepts_graph_only_with_a_namespace(self) -> None:
        from hawki_rag_contracts.auth_scope import AuthorizedQueryScope

        scope = AuthorizedQueryScope(
            dataset_id="dataset-a",
            qdrant_collection="hawki_dataset_a",
            neo4j_namespace="graph_dataset_a",
            embedding_provider="ollama",
            embedding_model="hawki-ollama-embedding",
            graph_enabled=True,
        )

        self.assertTrue(scope.graph_enabled)
        self.assertEqual(scope.neo4j_namespace, "graph_dataset_a")

        with self.assertRaises(ValidationError):
            AuthorizedQueryScope(
                dataset_id="dataset-a",
                qdrant_collection="hawki_dataset_a",
                embedding_provider="ollama",
                embedding_model="hawki-ollama-embedding",
                graph_enabled=True,
            )

        with self.assertRaises(ValidationError):
            AuthorizedQueryScope.model_validate(
                {
                    "dataset_id": "dataset-a",
                    "qdrant_collection": "hawki_dataset_a",
                    "neo4j_namespace": "graph_dataset_a",
                    "embedding_provider": "ollama",
                    "embedding_model": "hawki-ollama-embedding",
                    "graph_enabled": "true",
                }
            )

    def test_query_rejects_provider_mismatch_instead_of_falling_back(self) -> None:
        from hawki_rag_contracts.query import QueryRequest

        with self.assertRaisesRegex(
            ValidationError,
            "automatic provider fallback is disabled",
        ):
            QueryRequest(
                query="Find the PDF",
                provider="litellm",
                chat_model="hawki-ollama-chat",
                vision_model="hawki-ollama-vision",
                authorized_scope={
                    "dataset_id": "dataset-a",
                    "qdrant_collection": "hawki_dataset_a",
                    "neo4j_namespace": "graph_dataset_a",
                    "embedding_provider": "ollama",
                    "embedding_model": "bge-m3",
                    "graph_enabled": False,
                },
            )

    def test_query_filters_reject_unsupported_nested_values(self) -> None:
        from hawki_rag_contracts.query import QueryRequest

        with self.assertRaises(ValidationError):
            QueryRequest(
                query="Find the PDF",
                authorized_scope={
                    "dataset_id": "dataset-a",
                    "qdrant_collection": "hawki_dataset_a",
                },
                filters={"metadata": {"owner": "user-a"}},
            )

        with self.assertRaises(ValidationError):
            QueryRequest(
                query="Find the PDF",
                authorized_scope={
                    "dataset_id": "dataset-a",
                    "qdrant_collection": "hawki_dataset_a",
                },
                filters={"score": float("nan")},
            )

    def test_mandatory_dataset_filter_overrides_reserved_user_filters(self) -> None:
        from hawki_bridge.application.query.scope import build_scoped_query_filters

        filters = build_scoped_query_filters(
            "dataset-a",
            {
                "dataset_id": "dataset-b",
                "collection": "other",
                "qdrant_collection": "other",
                "neo4j_namespace": "other",
                "qdrantCollection": "other-camel-case",
                "neo4j-namespace": "other-kebab-case",
                "authContext": "caller-owned",
                "graph-enabled": True,
                "source_format": "pdf",
                "": "ignored",
            },
        )

        self.assertEqual(filters, {"source_format": "pdf", "dataset_id": "dataset-a"})
