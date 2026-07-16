"""FastAPI boundary scenarios for schemas, configuration, routes, and injected application services."""

from __future__ import annotations

import importlib
import json
import os
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
    install_optional_dependency_stubs,
)

install_optional_dependency_stubs()
TestClient = fastapi_test_client_class()



class ApiCharacterizationTests(unittest.TestCase):
    """Describe validation and HTTP delegation for query, ingest, replacement, and deletion endpoints."""
    def test_api_schema_defaults_and_provider_errors_are_validation_boundaries(self) -> None:
        from api.http.dependencies import get_provider_or_400
        from api.http.schemas import IngestDoc, IngestRequest, QueryRequest, apply_ingest_request_settings, apply_query_request_settings
        from api.settings import load_app_settings

        HTTPException = _fastapi_http_exception_type()
        ingest = IngestRequest(
            docs=[IngestDoc(id="doc-1", text="Toy catalog", payload={"title": "Toys"})],
        )
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
        )
        settings = load_app_settings()

        self.assertEqual(ingest.provider, settings.rag_default_provider)
        self.assertEqual(ingest.distance, settings.qdrant_distance)
        self.assertEqual(query.top_k, 5)
        self.assertEqual(query.filters, {})
        self.assertEqual(apply_ingest_request_settings(ingest, settings), ingest)

        patched_query = apply_query_request_settings(query, settings)
        self.assertEqual(patched_query.provider, query.authorized_scope.embedding_provider)
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
            reranker="cosine",
            mix_mode=False,
        )
        patched = apply_query_request_settings(custom_query, settings)
        self.assertEqual(patched.provider, "query-provider")
        self.assertEqual(patched.reranker, "cosine")
        self.assertFalse(patched.mix_mode)

        custom_ingest = IngestRequest(
            docs=[IngestDoc(id="doc-1", text="x", payload={})],
            provider="ingest-provider",
            distance="L2",
            chunk_chars=999,
            chunk_overlap=88,
            batch_size=12,
            graph_engine="custom-graph",
        )
        patched_ingest = apply_ingest_request_settings(custom_ingest, settings)
        self.assertEqual(patched_ingest.provider, "ingest-provider")
        self.assertEqual(patched_ingest.distance, "L2")
        self.assertEqual(patched_ingest.chunk_chars, 999)
        self.assertEqual(patched_ingest.batch_size, 12)
        self.assertEqual(patched_ingest.graph_engine, "custom-graph")

    def test_app_settings_includes_runtime_env_overrides(self) -> None:
        from api.http.dependencies import get_provider_or_400
        from api.settings import load_app_settings

        HTTPException = _fastapi_http_exception_type()
        with patch.dict(
            os.environ,
            {
                "CUDA_VISIBLE_DEVICES": "0,1",
                "NVIDIA_VISIBLE_DEVICES": "GPU-7",
            },
            clear=False,
        ):
            settings = load_app_settings()

        self.assertEqual(settings.cuda_visible_devices, "0,1")
        self.assertEqual(settings.nvidia_visible_devices, "GPU-7")

        class Service:
            def get_provider(self, name: str):
                raise ValueError(f"unknown provider {name}")

        with self.assertRaises(HTTPException) as raised:
            get_provider_or_400(Service(), "missing")

        self.assertEqual(raised.exception.status_code, 400)
        self.assertIn("unknown provider missing", raised.exception.detail)

    def test_document_replacement_request_validates_text_and_preserves_defaults(self) -> None:
        from application.documents import build_replacement_ingest_request
        from api.http.schemas import DocumentUpsertRequest
        from api.settings import load_app_settings

        HTTPException = _fastapi_http_exception_type()
        with self.assertRaises(HTTPException) as raised:
            build_replacement_ingest_request(
                doc_id="doc-1",
                body=DocumentUpsertRequest(text=" "),
                app_settings=load_app_settings(),
            )
        self.assertEqual(raised.exception.status_code, 400)

        request = build_replacement_ingest_request(
            doc_id="doc-1",
            body=DocumentUpsertRequest(
                text="Toy blocks",
                payload={"title": "Toys"},
                provider="fake",
                collection="toy_docs",
                graph=True,
            ),
            app_settings=load_app_settings(),
        )

        self.assertEqual(request.docs[0].id, "doc-1")
        self.assertEqual(request.docs[0].payload, {"title": "Toys"})
        self.assertEqual(request.provider, "fake")
        self.assertEqual(request.collection, "toy_docs")
        self.assertEqual(request.chunk_chars, 3200)
        self.assertEqual(request.chunk_overlap, 250)
        self.assertTrue(request.graph)

    def test_config_response_uses_provider_and_qdrant_boundaries(self) -> None:
        from application.config_response import build_config_response
        from api.settings import AppSettings

        class Provider:
            embed_model = "embed-toys"

        class Qdrant:
            collection = "toy_docs"

            def get_vector_size(self) -> int:
                return 384

        with patch.dict(
            os.environ,
            {
                "RAG_DEFAULT_PROVIDER": "fake",
                "RERANKER_MODE": "none",
                "RERANKER_MIX_MODE": "true",
                "RERANKER_MIX_WEIGHT": "0.7",
            },
            clear=False,
        ):
            response = build_config_response(
                get_provider=lambda name: Provider(),
                qdrant_factory=Qdrant,
                app_settings=AppSettings(
                    rag_default_provider="fake",
                    qdrant_distance="Cosine",
                    graph_engine="raganything",
                    reranker_mode="none",
                    reranker_mix_mode=True,
                    reranker_mix_weight=0.7,
                    reranker_jina_model="jina-reranker-v2-base-multilingual",
                    reranker_api_url="",
                    chunk_size=1200,
                    chunk_overlap_size=250,
                    ingest_batch_size=64,
                    cuda_visible_devices="unset",
                    nvidia_visible_devices="unset",
                    log_level="INFO",
                    graph_debug=False,
                    graph_debug_log="",
                    public_dir=Path("/tmp"),
                ),
            )

        self.assertEqual(response["provider"], "fake")
        self.assertEqual(response["embedding_model"], "embed-toys")
        self.assertEqual(response["qdrant_collection"], "toy_docs")
        self.assertEqual(response["qdrant_vector_size"], 384)
        self.assertEqual(response["reranker"]["mix_weight"], 0.7)

    def test_app_logging_config_sets_app_and_graph_logger_levels(self) -> None:
        import logging

        from api.logging_config import configure_app_logging, env_flag
        from api.settings import AppSettings

        app_logger = logging.getLogger("tests.logging_config")
        ingest_logger = logging.getLogger("application.workflows.ingest_logic")
        rag_logger = logging.getLogger("core.rag_service")
        old_levels = (app_logger.level, ingest_logger.level, rag_logger.level)
        try:
            logger, graph_debug, graph_debug_log = configure_app_logging(
                AppSettings(
                    rag_default_provider="ollama",
                    qdrant_distance="Cosine",
                    graph_engine="raganything",
                    reranker_mode="none",
                    reranker_mix_mode=False,
                    reranker_mix_weight=0.5,
                    reranker_jina_model="jina-reranker-v2-base-multilingual",
                    reranker_api_url="",
                    chunk_size=1200,
                    chunk_overlap_size=250,
                    ingest_batch_size=64,
                    cuda_visible_devices="unset",
                    nvidia_visible_devices="unset",
                    log_level="WARNING",
                    graph_debug=True,
                    graph_debug_log="",
                    public_dir=Path("/tmp"),
                ),
                logger_name="tests.logging_config",
            )

            self.assertTrue(env_flag("yes"))
            self.assertFalse(env_flag(""))
            self.assertTrue(graph_debug)
            self.assertEqual(graph_debug_log, "")
            self.assertEqual(logger.level, logging.WARNING)
            self.assertEqual(ingest_logger.level, logging.DEBUG)
        finally:
            app_logger.setLevel(old_levels[0])
            ingest_logger.setLevel(old_levels[1])
            rag_logger.setLevel(old_levels[2])

    def test_app_router_builder_surface_exists_and_routes_are_available(self) -> None:
        import sys

        with tempfile.TemporaryDirectory() as tmp:
            with patch.dict(os.environ, {"RAG_WORKING_DIR": tmp}, clear=False):
                for mod_name in [
                    "api.main",
                    "api.http.routers",
                    "api.http.routers.health",
                    "api.http.routers.config",
                    "api.http.routers.ingest",
                    "api.http.routers.query",
                    "api.http.routers.graph",
                ]:
                    sys.modules.pop(mod_name, None)

                app_main = importlib.import_module("api.main")
                paths = {route.path for route in app_main.app.router.routes}

        self.assertIn("/health", paths)
        self.assertIn("/config", paths)
        self.assertIn("/ingest", paths)
        self.assertIn("/documents/{doc_id}", paths)
        self.assertIn("/query", paths)
        self.assertIn("/graph/from-text", paths)
        self.assertIn("/graph/cache/clear", paths)

    def test_app_factory_builds_routes_with_injected_dependencies(self) -> None:
        from api.factory import build_app
        from api.settings import load_app_settings

        class FakeService:
            def __init__(self) -> None:
                self.runtime_calls = 0
                self.clear_calls = 0
                self.provider_calls = 0

            def graph_runtime_summary(self) -> dict[str, object]:
                self.runtime_calls += 1
                return {"mode": "test"}

            def clear_graph_cache(self) -> dict[str, object]:
                self.clear_calls += 1
                return {"ok": True}

            def get_provider(self, name: str) -> object:
                self.provider_calls += 1
                return SimpleNamespace(embed_model="test-embed")

        class FakeQdrant:
            collection = "app_test"

            def get_vector_size(self) -> int:
                return 128

        with tempfile.TemporaryDirectory() as tmp:
            app_settings = load_app_settings()
            service = FakeService()
            app = build_app(
                rag_service=service,
                public_dir=Path(tmp),
                qdrant_factory=FakeQdrant,
                logger_name="app_test_factory",
                app_settings=app_settings,
            )

            with TestClient(app) as client:
                health = client.get("/health").json()
                lightweight_health = client.get("/health?runtime=false").json()
                config = client.get("/config").json()
                cache = client.post("/graph/cache/clear").json()

        self.assertEqual(health["ok"], True)
        self.assertEqual(health["runtime"], {"mode": "test"})
        self.assertEqual(lightweight_health["ok"], True)
        self.assertEqual(lightweight_health["runtime"], {})
        self.assertEqual(config["provider"], app_settings.rag_default_provider)
        self.assertEqual(config["qdrant_collection"], "app_test")
        self.assertEqual(config["qdrant_vector_size"], 128)
        self.assertEqual(cache["ok"], True)
        self.assertEqual(service.runtime_calls, 1)
        self.assertEqual(service.provider_calls, 1)
        self.assertEqual(service.clear_calls, 1)

    def test_app_query_route_uses_injected_dependencies(self) -> None:
        from api.factory import build_app
        from api.http.schemas import QueryRequest

        class FakeService:
            def __init__(self) -> None:
                self.provider_calls: list[str] = []

            def get_provider(self, name: str) -> object:
                self.provider_calls.append(name)
                return SimpleNamespace(embed_model="query-embed", rag_model="query-rag")

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
        ) -> dict[str, object]:
            captured["body_type"] = type(body).__name__
            captured["body_provider"] = body.provider
            captured["body_top_k"] = body.top_k
            captured["service_is_injected"] = rag_service is service
            captured["provider_fn_called"] = callable(get_provider)
            captured["provider_value"] = get_provider(body.provider)
            return {
                "ok": True,
                "query": body.query,
                "top_k": body.top_k,
            }

        with tempfile.TemporaryDirectory() as tmp:
            service = FakeService()
            app = build_app(
                rag_service=service,
                public_dir=Path(tmp),
                qdrant_factory=object,
                logger_name="app_test_query_route",
            )

            with patch("application.query.query_documents", side_effect=fake_query_documents):
                with TestClient(app) as client:
                    response = client.post(
                        "/query",
                        json=query_body.model_dump(),
                    )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"ok": True, "query": query_body.query, "top_k": 4})
        self.assertEqual(captured["body_type"], "QueryRequest")
        self.assertEqual(captured["body_provider"], query_body.provider)
        self.assertEqual(captured["body_top_k"], 4)
        self.assertEqual(captured["provider_fn_called"], True)
        self.assertEqual(captured["service_is_injected"], True)
        self.assertEqual(captured["provider_value"].embed_model, "query-embed")
        self.assertEqual(service.provider_calls, [query_body.provider])

    def test_app_ingest_route_delegates_with_injected_dependencies(self) -> None:
        from api.factory import build_app
        from api.http.schemas import IngestDoc, IngestRequest

        class FakeService:
            def __init__(self) -> None:
                self.provider_calls: list[str] = []

            def get_provider(self, name: str) -> object:
                self.provider_calls.append(name)
                return SimpleNamespace(embed_model="ingest-embed", rag_model="graph-rag")

        ingest_body = IngestRequest(
            docs=[IngestDoc(id="doc-toy-1", text="Wooden trains and blocks.", payload={"title": "Toys"})],
            provider="ingest-provider",
            collection="toy_docs",
            graph=True,
        )
        captured: dict[str, object] = {}

        def fake_ingest_documents(
            body: IngestRequest,
            rag_service: object,
            get_provider,
            public_dir,
            **kwargs: object,
        ) -> dict[str, object]:
            captured["docs_len"] = len(body.docs)
            captured["provider_arg"] = body.provider
            captured["service_is_injected"] = rag_service is service
            captured["public_dir_path"] = str(public_dir)
            captured["provider_fn_called"] = callable(get_provider)
            captured["provider_fn_value"] = get_provider(body.provider)
            captured["idempotency_key"] = kwargs.get("idempotency_key")
            return {
                "ok": True,
                "collection": body.collection,
                "count": len(body.docs),
            }

        with tempfile.TemporaryDirectory() as tmp:
            service = FakeService()
            app = build_app(
                rag_service=service,
                public_dir=Path(tmp),
                qdrant_factory=object,
                logger_name="app_test_ingest_route",
            )

            with patch("application.ingest.ingest_documents", side_effect=fake_ingest_documents):
                with TestClient(app) as client:
                    response = client.post(
                        "/ingest",
                        headers={"Idempotency-Key": "ingest-route-key"},
                        json=ingest_body.model_dump(),
                    )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"ok": True, "collection": "toy_docs", "count": 1})
        self.assertEqual(captured["docs_len"], 1)
        self.assertEqual(captured["provider_arg"], "ingest-provider")
        self.assertEqual(captured["service_is_injected"], True)
        self.assertEqual(captured["public_dir_path"], tmp)
        self.assertEqual(captured["provider_fn_called"], True)
        self.assertEqual(captured["provider_fn_value"].embed_model, "ingest-embed")
        self.assertEqual(captured["idempotency_key"], "ingest-route-key")
        self.assertEqual(service.provider_calls, [ingest_body.provider])

    def test_app_document_routes_replace_and_delete_contract(self) -> None:
        from api.factory import build_app

        class FakeService:
            def get_provider(self, name: str) -> object:
                return SimpleNamespace(embed_model="ingest-embed")

        with tempfile.TemporaryDirectory() as tmp:
            app = build_app(
                rag_service=FakeService(),
                public_dir=Path(tmp),
                qdrant_factory=object,
                logger_name="app_test_documents_routes",
            )

            with patch(
                "application.documents.build_replacement_ingest_request",
                return_value=SimpleNamespace(
                    docs=[SimpleNamespace(id="doc-replace-1", text="replacement", payload={})],
                    provider="fake",
                    collection="toy_docs",
                    graph=False,
                    graph_engine="raganything",
                    chunk_chars=1200,
                    chunk_overlap=250,
                    dry_run=False,
                    dry_include_graph=False,
                ),
            ) as replacement_builder, patch(
                "application.ingest.delete_document",
                return_value={"qdrant": {"ok": True}, "neo4j": {"ok": True}},
            ) as delete_mock, patch(
                "application.ingest.ingest_documents",
                return_value={"ok": True},
            ) as ingest_mock:
                with TestClient(app) as client:
                    delete_response = client.delete(
                        "/documents/doc-replace-1",
                        params={"collection": "toy_docs", "neo4j_namespace": "toy_graph"},
                    )
                    put_response = client.put(
                        "/documents/doc-replace-1",
                        json={"text": "updated", "collection": "toy_docs", "neo4j_database": "toy_graph"},
                    )

        self.assertEqual(delete_response.status_code, 200)
        self.assertEqual(
            delete_response.json(),
            {
                "ok": True,
                "doc_id": "doc-replace-1",
                "collection": "toy_docs",
                "neo4j_namespace": "toy_graph",
                "qdrant": {"ok": True},
                "neo4j": {"ok": True},
            },
        )
        self.assertEqual(put_response.status_code, 200)
        self.assertEqual(put_response.json()["ok"], True)
        self.assertEqual(put_response.json()["replaced_doc_id"], "doc-replace-1")
        self.assertEqual(put_response.json()["deleted"], {"qdrant": {"ok": True}, "neo4j": {"ok": True}})
        self.assertEqual(put_response.json(), {"ok": True, "replaced_doc_id": "doc-replace-1", "deleted": {"qdrant": {"ok": True}, "neo4j": {"ok": True}}})
        self.assertEqual(delete_mock.call_count, 2)
        delete_calls = delete_mock.call_args_list
        self.assertEqual(delete_calls[0].args[0], "doc-replace-1")
        self.assertEqual(delete_calls[0].kwargs["idempotency_key"], "doc-replace-1")
        self.assertEqual(delete_calls[0].kwargs["collection"], "toy_docs")
        self.assertEqual(delete_calls[0].kwargs["neo4j_namespace"], "toy_graph")
        self.assertEqual(delete_calls[1].args[0], "doc-replace-1")
        self.assertIsNone(delete_calls[1].kwargs["idempotency_key"])
        self.assertEqual(delete_calls[1].kwargs["collection"], "toy_docs")
        self.assertEqual(delete_calls[1].kwargs["neo4j_namespace"], "toy_graph")
        replacement_builder.assert_called_once()
        ingest_mock.assert_called_once()
