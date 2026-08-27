"""FastAPI boundary scenarios for schemas, configuration, routes, and injected application services."""

from __future__ import annotations

import asyncio
from contextlib import asynccontextmanager
import tempfile
import unittest
from types import SimpleNamespace
from typing import Any
from unittest.mock import patch

import httpx


class TestClient:
    """Exercise an ASGI app without Starlette's cross-thread portal."""

    __test__ = False

    def __init__(self, app: Any, *, raise_server_exceptions: bool = True) -> None:
        self.app = app
        self.raise_server_exceptions = raise_server_exceptions

    def __enter__(self) -> TestClient:
        return self

    def __exit__(self, *_args: object) -> None:
        return None

    def get(self, path: str, **kwargs: Any) -> httpx.Response:
        return self.request("GET", path, **kwargs)

    def post(self, path: str, **kwargs: Any) -> httpx.Response:
        return self.request("POST", path, **kwargs)

    def request(self, method: str, path: str, **kwargs: Any) -> httpx.Response:
        return asyncio.run(self._request(method, path, **kwargs))

    async def _request(self, method: str, path: str, **kwargs: Any) -> httpx.Response:
        lifespan = getattr(getattr(self.app, "router", None), "lifespan_context", None)

        @asynccontextmanager
        async def no_lifespan(_app: Any):
            yield

        transport = httpx.ASGITransport(
            app=self.app,
            raise_app_exceptions=self.raise_server_exceptions,
        )

        async def run_sync_endpoint_inline(
            function: Any, *args: Any, **call_kwargs: Any
        ) -> Any:
            return function(*args, **call_kwargs)

        async def run_anyio_sync_inline(
            function: Any,
            *args: Any,
            **_thread_options: Any,
        ) -> Any:
            return function(*args)

        async with (lifespan or no_lifespan)(self.app):
            with (
                patch("anyio.to_thread.run_sync", run_anyio_sync_inline),
                patch("fastapi.routing.run_in_threadpool", run_sync_endpoint_inline),
            ):
                async with httpx.AsyncClient(
                    transport=transport,
                    base_url="http://testserver",
                ) as client:
                    return await client.request(method, path, **kwargs)


class ApiCharacterizationTests(unittest.TestCase):
    """Describe bridge reads plus transport-neutral indexer mutation boundaries."""

    def test_structured_dataset_not_ready_error_has_a_stable_public_shape(self) -> None:
        import logging

        from fastapi import FastAPI, HTTPException

        from hawki_bridge.http.errors import install_exception_handlers

        app = FastAPI()
        install_exception_handlers(
            app, logging.getLogger("dataset_not_ready_contract_test")
        )

        @app.post("/query")
        def query() -> None:
            raise HTTPException(
                status_code=503,
                detail={
                    "code": "dataset_not_ready",
                    "message": "The authorized dataset storage is not ready.",
                },
            )

        with TestClient(app) as client:
            response = client.post("/query")

        self.assertEqual(response.status_code, 503)
        self.assertEqual(
            response.json(),
            {
                "error": {
                    "type": "HTTPException",
                    "status": 503,
                    "message": "The authorized dataset storage is not ready.",
                    "path": "/query",
                    "request_id": "",
                    "code": "dataset_not_ready",
                },
            },
        )

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

    def test_api_schema_defaults_and_query_errors_are_validation_boundaries(
        self,
    ) -> None:
        from hawki_bridge.domain.errors import UnsupportedModelProviderError
        from hawki_bridge.http.errors import query_error_to_http_exception
        from hawki_bridge.http.schemas import QueryRequest, apply_query_settings
        from hawki_bridge.settings import load_settings

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

        error = query_error_to_http_exception(
            UnsupportedModelProviderError("unknown provider missing")
        )
        self.assertEqual(error.status_code, 400)
        self.assertIn("unknown provider missing", error.detail)

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

    def test_app_logging_config_sets_app_and_graph_logger_levels(self) -> None:
        import logging

        from hawki_bridge.logging_config import configure_logging
        from hawki_bridge.settings import load_settings

        app_logger = logging.getLogger("tests.logging_config")
        ingest_logger = logging.getLogger("hawki_indexer_worker.indexing.orchestration")
        rag_logger = logging.getLogger("hawki_bridge.application.query.execution")
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

        app = build_app(
            settings=load_settings({}),
            runtime_summary=lambda: {"role": "bridge", "mode": "read-only"},
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

        class RuntimeSummary:
            def __init__(self) -> None:
                self.calls = 0

            def __call__(self) -> dict[str, object]:
                self.calls += 1
                return {"role": "bridge", "mode": "read-only"}

        app_settings = load_settings({})
        runtime_summary = RuntimeSummary()
        app = build_app(
            settings=app_settings,
            runtime_summary=runtime_summary,
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
        self.assertEqual(runtime_summary.calls, 1)

    def test_app_query_route_uses_injected_dependencies(self) -> None:
        from hawki_bridge.application.dependencies import QueryDependencies
        from hawki_bridge.factory import build_app
        from hawki_bridge.http.schemas import QueryRequest
        from hawki_bridge.settings import load_settings

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
        provider_calls: list[str] = []

        def resolve_provider(name: str) -> object:
            provider_calls.append(name)
            return SimpleNamespace(embed_model="query-embed", rag_model="query-rag")

        dependencies = QueryDependencies(
            vector_search_factory=lambda: SimpleNamespace(),
            graph_search=SimpleNamespace(),
            resolve_model_provider=resolve_provider,
            rerank_hits=lambda **kwargs: kwargs["hits"],
        )

        def fake_execute_authorized_query(
            body: QueryRequest,
            *,
            dependencies: QueryDependencies,
        ) -> dict[str, object]:
            captured["body_type"] = type(body).__name__
            captured["body_provider"] = body.provider
            captured["body_top_k"] = body.top_k
            captured["dependencies"] = dependencies
            captured["provider_value"] = dependencies.resolve_model_provider(
                body.provider
            )
            return {
                "ok": True,
                "count": 0,
                "hits": [],
                "kg": [],
                "answer": "",
                "retrieval": {"top_k": body.top_k},
            }

        with tempfile.TemporaryDirectory():
            app = build_app(
                query_dependencies=dependencies,
                runtime_summary=lambda: {"mode": "test"},
                settings=load_settings({}),
                logger_name="app_test_query_route",
            )

            with patch(
                "hawki_bridge.http.routers.query.execute_authorized_query",
                side_effect=fake_execute_authorized_query,
            ):
                with TestClient(app) as client:
                    response = client.post(
                        "/query",
                        json=query_body.model_dump(),
                    )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            response.json(),
            {
                "ok": True,
                "count": 0,
                "hits": [],
                "kg": [],
                "answer": "",
                "retrieval": {"top_k": 4},
            },
        )
        self.assertEqual(captured["body_type"], "QueryRequest")
        self.assertEqual(captured["body_provider"], query_body.provider)
        self.assertEqual(captured["body_top_k"], 4)
        self.assertIs(captured["dependencies"], dependencies)
        self.assertEqual(captured["provider_value"].embed_model, "query-embed")
        self.assertEqual(provider_calls, [query_body.provider])
