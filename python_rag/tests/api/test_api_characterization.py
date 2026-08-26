"""FastAPI boundary scenarios for schemas, configuration, routes, and injected application services."""

from __future__ import annotations

import sys
import tempfile
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
    fastapi_http_exception_type as _fastapi_http_exception_type,
    fastapi_test_client_class,
)

TestClient = fastapi_test_client_class()


class ApiCharacterizationTests(unittest.TestCase):
    """Describe bridge reads plus transport-neutral indexer mutation boundaries."""

    def test_neo4j_driver_errors_use_the_explicit_api_boundary(self) -> None:
        import logging

        from fastapi import FastAPI
        from neo4j.exceptions import ServiceUnavailable

        from hawki_bridge.http.errors.handlers import install_exception_handlers

        app = FastAPI()
        install_exception_handlers(app, logging.getLogger("neo4j-handler-test"))

        @app.get("/graph-failure")
        def graph_failure() -> None:
            raise ServiceUnavailable("connection details must stay private")

        with TestClient(app) as client:
            response = client.get("/graph-failure")

        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json()["error"]["type"], "Neo4jUnavailable")
        self.assertNotIn("connection details", response.text)

    def test_non_availability_neo4j_errors_are_safe_internal_failures(self) -> None:
        import logging

        from fastapi import FastAPI
        from neo4j.exceptions import Neo4jError

        from hawki_bridge.http.errors.handlers import install_exception_handlers

        app = FastAPI()
        install_exception_handlers(app, logging.getLogger("neo4j-handler-test"))
        syntax_error = Neo4jError._hydrate_neo4j(
            code="Neo.ClientError.Statement.SyntaxError",
            message="MATCH secret-password-token",
        )

        @app.get("/invalid-graph-query")
        def invalid_graph_query() -> None:
            raise syntax_error

        with TestClient(app) as client:
            response = client.get("/invalid-graph-query")

        self.assertEqual(response.status_code, 500)
        self.assertEqual(response.json()["error"]["type"], "GraphStorageError")
        self.assertNotIn("secret-password-token", response.text)

    def test_api_schema_defaults_and_provider_errors_are_validation_boundaries(
        self,
    ) -> None:
        from hawki_bridge.http.dependencies import get_provider_or_400
        from hawki_bridge.http.schemas import QueryRequest, apply_query_settings
        from hawki_bridge.settings import load_settings

        HTTPException = _fastapi_http_exception_type()
        query = QueryRequest(
            query="Which toys are wooden?",
            authorized_scope={
                "dataset_id": "dataset-a",
                "qdrant_collection": "hawki_dataset_a",
                "neo4j_namespace": "hawki_dataset_a",
                "embedding_provider": "ollama",
                "embedding_model": "hawki-ollama-embedding",
                "graph_enabled": False,
            },
            provider="ollama",
            chat_model="llama3.1:8b",
            vision_model="qwen2.5vl:7b",
        )
        settings = load_settings({})

        self.assertEqual(query.top_k, 5)
        self.assertEqual(query.filters, {})

        patched_query = apply_query_settings(query, settings)
        self.assertEqual(
            patched_query.provider, query.authorized_scope.embedding_provider
        )
        self.assertEqual(patched_query.reranker, settings.reranker_mode)
        self.assertEqual(patched_query.mix_mode, settings.reranker_mix_mode)
        self.assertEqual(patched_query.mix_weight, settings.reranker_mix_weight)

        custom_query = QueryRequest(
            query="Which toys are wooden?",
            authorized_scope={
                "dataset_id": "dataset-a",
                "qdrant_collection": "hawki_dataset_a",
                "neo4j_namespace": "hawki_dataset_a",
                "embedding_provider": "query-provider",
                "embedding_model": "hawki-ollama-embedding",
                "graph_enabled": False,
            },
            provider="query-provider",
            chat_model="llama3.1:8b",
            vision_model="qwen2.5vl:7b",
            reranker="cosine",
            mix_mode=False,
        )
        patched = apply_query_settings(custom_query, settings)
        self.assertEqual(patched.provider, "query-provider")
        self.assertEqual(patched.reranker, "cosine")
        self.assertFalse(patched.mix_mode)

        class Service:
            def get_provider(self, name: str):
                raise ValueError(f"unknown provider {name}")

        with self.assertRaises(HTTPException) as raised:
            get_provider_or_400(Service(), "missing")

        self.assertEqual(raised.exception.status_code, 400)
        self.assertIn("unknown provider missing", raised.exception.detail)

    def test_app_settings_includes_runtime_env_overrides(self) -> None:
        from hawki_bridge.settings import load_settings

        settings = load_settings(
            {
                "RERANKER_MODE": "cosine",
                "RERANKER_MIX_MODE": "false",
                "RERANKER_MIX_WEIGHT": "0.7",
                "STARTUP_CHECKS_ENABLED": "true",
            }
        )

        self.assertEqual(settings.reranker_mode, "cosine")
        self.assertFalse(settings.reranker_mix_mode)
        self.assertEqual(settings.reranker_mix_weight, 0.7)
        self.assertTrue(settings.startup_checks_enabled)

    def test_graph_index_request_requires_scope_and_preserves_defaults(self) -> None:
        from hawki_indexer_worker.domain.errors import IndexingValidationError
        from hawki_indexer_worker.domain.models import IngestDocument
        from hawki_indexer_worker.indexing.request import IndexRequest

        with self.assertRaises(IndexingValidationError):
            IndexRequest(
                docs=[IngestDocument(id="doc-1", text="Toy blocks")],
                graph=True,
                collection="toy_docs",
            )

        request = IndexRequest(
            docs=[
                IngestDocument(
                    id="doc-1",
                    text="Toy blocks",
                    payload={"title": "Toys"},
                )
            ],
            provider="fake",
            collection="toy_docs",
            dataset_id="dataset-a",
            neo4j_namespace="hawki_dataset_a",
            graph=True,
        )

        self.assertEqual(request.docs[0].id, "doc-1")
        self.assertEqual(request.docs[0].payload, {"title": "Toys"})
        self.assertEqual(request.provider, "fake")
        self.assertEqual(request.collection, "toy_docs")
        self.assertEqual(request.chunk_chars, 1200)
        self.assertEqual(request.chunk_overlap, 250)
        self.assertTrue(request.graph)

    def test_app_logging_config_sets_app_and_graph_logger_levels(self) -> None:
        import logging

        from hawki_bridge.logging_config import configure_logging
        from hawki_bridge.settings import load_settings

        app_logger = logging.getLogger("tests.logging_config")
        ingest_logger = logging.getLogger("hawki_indexer_worker.indexing.orchestration")
        rag_logger = logging.getLogger("hawki_bridge.application.service")
        old_levels = (app_logger.level, ingest_logger.level, rag_logger.level)
        try:
            logger = configure_logging(
                load_settings({"LOG_LEVEL": "WARNING"}),
                logger_name="tests.logging_config",
            )

            self.assertEqual(logger.level, logging.WARNING)
            self.assertEqual(ingest_logger.level, old_levels[1])
            self.assertEqual(rag_logger.level, old_levels[2])
        finally:
            app_logger.setLevel(old_levels[0])
            ingest_logger.setLevel(old_levels[1])
            rag_logger.setLevel(old_levels[2])

    def test_app_router_builder_surface_exists_and_routes_are_available(self) -> None:
        from hawki_bridge.factory import build_app
        from hawki_bridge.settings import load_settings

        class Service:
            @staticmethod
            def runtime_summary() -> dict[str, str]:
                return {"role": "bridge", "mode": "read-only"}

            @staticmethod
            def get_provider(_name: str) -> object:
                return object()

        app = build_app(
            settings=load_settings({}),
            service=Service(),
            logger_name="test.bridge_route_surface",
        )
        paths = set(app.openapi()["paths"])

        self.assertIn("/health", paths)
        self.assertNotIn("/config", paths)
        self.assertIn("/query", paths)
        self.assertIn("/graph/related", paths)
        self.assertNotIn("/ingest", paths)
        self.assertNotIn("/documents/{doc_id}", paths)
        self.assertNotIn("/graph/from-text", paths)
        self.assertNotIn("/graph/cache/clear", paths)

    def test_app_factory_builds_routes_with_injected_dependencies(self) -> None:
        from hawki_bridge.factory import build_app
        from hawki_bridge.settings import load_settings

        class FakeService:
            def __init__(self) -> None:
                self.runtime_calls = 0

            def runtime_summary(self) -> dict[str, object]:
                self.runtime_calls += 1
                return {"role": "bridge", "mode": "read-only"}

        app_settings = load_settings({})
        service = FakeService()
        app = build_app(
            settings=app_settings,
            service=service,
            logger_name="test.bridge_factory",
        )
        route_endpoints = {
            route.path: route.endpoint
            for included in app.routes
            for route in getattr(
                getattr(included, "original_router", None), "routes", ()
            )
            if hasattr(route, "endpoint")
        }
        health = route_endpoints["/health"](runtime=True)
        lightweight_health = route_endpoints["/health"](runtime=False)

        self.assertEqual(health["ok"], True)
        self.assertEqual(
            health["runtime"],
            {"role": "bridge", "mode": "read-only"},
        )
        self.assertEqual(lightweight_health["ok"], True)
        self.assertEqual(lightweight_health["runtime"], {})
        self.assertEqual(service.runtime_calls, 1)

    def test_app_query_route_uses_injected_dependencies(self) -> None:
        from hawki_bridge.factory import build_app
        from hawki_bridge.http.schemas import QueryRequest
        from hawki_bridge.settings import load_settings

        class FakeService:
            def __init__(self) -> None:
                self.provider_calls: list[str] = []

            def get_provider(self, name: str) -> object:
                self.provider_calls.append(name)
                return SimpleNamespace(embed_model="query-embed", rag_model="query-rag")

            def runtime_summary(self) -> dict[str, str]:
                return {"mode": "test"}

        query_body = QueryRequest(
            query="Why wooden toys are safe?",
            authorized_scope={
                "dataset_id": "dataset-a",
                "qdrant_collection": "hawki_dataset_a",
                "neo4j_namespace": "hawki_dataset_a",
                "embedding_provider": "query-provider",
                "embedding_model": "hawki-ollama-embedding",
                "graph_enabled": False,
            },
            top_k=4,
            provider="query-provider",
            chat_model="query-chat",
            vision_model="query-vision",
            filters={"source_format": "markdown"},
            generate=False,
            is_optimized=True,
            fast_mode=True,
            smart_lookup=True,
            structural_hops=2,
            preferred_tags=["kids", "safety"],
            reranker="cosine",
            rerank_top_n=12,
            mix_mode=False,
            mix_weight=0.4,
        )
        captured: dict[str, object] = {}

        def fake_query_documents(
            body: QueryRequest,
            rag_service: object,
            get_provider,
            dependencies,
        ) -> dict[str, object]:
            captured["body_type"] = type(body).__name__
            captured["body_provider"] = body.provider
            captured["body_top_k"] = body.top_k
            captured["service_is_injected"] = rag_service is service
            captured["provider_fn_called"] = callable(get_provider)
            captured["provider_value"] = get_provider(body.provider)
            captured["storage_dependencies"] = dependencies
            return {
                "ok": True,
                "query": body.query,
                "top_k": body.top_k,
            }

        with tempfile.TemporaryDirectory():
            service = FakeService()
            app = build_app(
                service=service,
                settings=load_settings({}),
                logger_name="app_test_query_route",
            )

            with patch(
                "hawki_bridge.http.routers.query.query_documents",
                side_effect=fake_query_documents,
            ):
                with TestClient(app) as client:
                    response = client.post(
                        "/query",
                        json=query_body.model_dump(),
                    )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            response.json(), {"ok": True, "query": query_body.query, "top_k": 4}
        )
        self.assertEqual(captured["body_type"], "QueryRequest")
        self.assertEqual(captured["body_provider"], query_body.provider)
        self.assertEqual(captured["body_top_k"], 4)
        self.assertEqual(captured["provider_fn_called"], True)
        self.assertEqual(captured["service_is_injected"], True)
        self.assertIsNotNone(captured["storage_dependencies"])
        self.assertEqual(captured["provider_value"].embed_model, "query-embed")
        self.assertEqual(service.provider_calls, [query_body.provider])

    def test_index_request_is_built_from_workflow_input_without_http(self) -> None:
        from hawki_indexer_worker.domain.models import IngestDocument
        from hawki_indexer_worker.indexing.request import IndexRequest

        request = IndexRequest.from_options(
            [
                IngestDocument(
                    id="doc-toy-1",
                    text="Wooden trains and blocks.",
                    payload={"title": "Toys"},
                )
            ],
            workflow_input={
                "dataset_id": "dataset-a",
                "job_id": "job-1",
            },
            options={
                "provider": "ollama",
                "embedding_model": "bge-m3",
                "collection": "hawki_dataset_a",
                "neo4j_namespace": "hawki_dataset_a",
                "graph": True,
            },
            operation_id="workflow-op-1",
        )

        self.assertEqual(request.docs[0].id, "doc-toy-1")
        self.assertEqual(request.dataset_id, "dataset-a")
        self.assertEqual(request.collection, "hawki_dataset_a")
        self.assertEqual(request.neo4j_namespace, "hawki_dataset_a")
        self.assertEqual(request.idempotency_key, "workflow-op-1")
        self.assertEqual(request.job_id, "job-1")
        self.assertTrue(request.graph)

    def test_document_delete_contract_is_owned_by_the_indexer(self) -> None:
        from hawki_indexer_worker.indexing.deletion import delete_document_entries

        class FakeQdrant:
            def __init__(self) -> None:
                self.collection = "default"
                self.calls: list[tuple[str, str | None]] = []

            def set_collection(self, collection: str) -> None:
                self.collection = collection

            def count_points_by_doc_id(self, doc_id: str, **_kwargs) -> int:
                assert doc_id == "doc-replace-1"
                return 2

            def delete_by_doc_id(
                self,
                doc_id: str,
                *,
                idempotency_key: str | None = None,
            ) -> dict[str, str]:
                self.calls.append((doc_id, idempotency_key))
                return {"status": "ok"}

        graph_instances = []

        class FakeGraph:
            def __init__(self, *, neo4j_namespace: str | None = None) -> None:
                self._neo4j_namespace = neo4j_namespace
                self.calls: list[tuple[str, str | None]] = []
                self.closed = False
                graph_instances.append(self)

            def delete_by_doc_id(
                self,
                doc_id: str,
                *,
                request_id: str | None = None,
            ) -> dict[str, int]:
                self.calls.append((doc_id, request_id))
                return {"relationships_deleted": 3, "entities_deleted": 1}

            def close(self) -> None:
                self.closed = True

        qdrant = FakeQdrant()
        response = delete_document_entries(
            "doc-replace-1",
            idempotency_key="delete-op-1",
            collection="toy_docs",
            neo4j_namespace="toy_graph",
            vector_writer_factory=lambda: qdrant,
            graph_writer_factory=FakeGraph,
        )

        self.assertEqual(
            response,
            {
                "qdrant": {
                    "doc_id": "doc-replace-1",
                    "collection": "toy_docs",
                    "deleted_points": 2,
                    "result": {"status": "ok"},
                },
                "neo4j": {
                    "doc_id": "doc-replace-1",
                    "namespace": "toy_graph",
                    "relationships_deleted": 3,
                    "entities_deleted": 1,
                },
            },
        )
        self.assertEqual(qdrant.calls, [("doc-replace-1", "delete-op-1")])
        self.assertEqual(graph_instances[0].calls, [("doc-replace-1", "delete-op-1")])
        self.assertTrue(graph_instances[0].closed)
