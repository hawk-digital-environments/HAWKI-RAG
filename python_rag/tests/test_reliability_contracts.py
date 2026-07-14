from __future__ import annotations

import os
from pathlib import Path
from types import SimpleNamespace
import sys
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


if "neo4j" not in sys.modules:
    neo4j_module = types.ModuleType("neo4j")

    class Neo4jError(Exception):
        pass

    class GraphDatabase:
        @staticmethod
        def driver(*_args, **_kwargs):
            raise RuntimeError("GraphDatabase.driver should not be called in tests")

    neo4j_module.GraphDatabase = GraphDatabase
    neo4j_module.exceptions = types.SimpleNamespace(Neo4jError=Neo4jError)
    sys.modules["neo4j"] = neo4j_module


class ReliabilityContractTests(unittest.TestCase):
    def test_raganything_file_logging_is_opt_in(self) -> None:
        from api.settings import load_app_settings

        settings = load_app_settings({"RAG_DEFAULT_PROVIDER": "fake"})
        configured_settings = load_app_settings(
            {
                "RAG_DEFAULT_PROVIDER": "fake",
                "HAWKI_RAG_RAGANYTHING_LOG_PATH": "/shared/logs/raganything_runtime.log",
            }
        )

        self.assertEqual(settings.raganything_log_path, "")
        self.assertEqual(
            configured_settings.raganything_log_path,
            "/shared/logs/raganything_runtime.log",
        )

    def test_retryable_write_contract_is_idempotency_gated(self) -> None:
        from common.reliability import is_safe_retryable_write

        self.assertTrue(is_safe_retryable_write("qdrant.upsert_points", "op-1"))
        self.assertTrue(is_safe_retryable_write("neo4j.delete_by_doc_id", "op-2"))
        self.assertFalse(is_safe_retryable_write("qdrant.upsert_points", None))
        self.assertFalse(is_safe_retryable_write("unknown.operation", "op-3"))

    def test_qdrant_gateway_marks_write_operations_retryable_only_with_idempotency_key(self) -> None:
        from infrastructure.vectorstore.qdrant_gateway import QdrantHTTPGateway
        from infrastructure.vectorstore.qdrant_requests import QdrantRequest

        class FakeTransport:
            def __init__(self) -> None:
                self.requests: list[QdrantRequest] = []

            def send(self, request: QdrantRequest):
                self.requests.append(request)
                response = SimpleNamespace(status_code=200, json=lambda: {"result": {}})
                return response

        transport = FakeTransport()
        gateway = QdrantHTTPGateway(transport=transport, collection="test")

        gateway.upsert([{"id": "a"}], timeout=1.0, operation_id=None)
        gateway.delete_by_filter({"match": {}}, timeout=1.0, operation_id="req-doc")

        self.assertFalse(transport.requests[0].retryable)
        self.assertTrue(transport.requests[1].retryable)
        self.assertIsNone(transport.requests[0].operation_id)
        self.assertEqual(transport.requests[1].operation_id, "req-doc")

    def test_log_redaction_masks_secrets_in_headers_and_body_snippets(self) -> None:
        from common.reliability import log_redacted_value, preview_request_body, preview_request_headers

        headers = {
            "authorization": "Bearer deadbeef-token",
            "x-request-id": "req-1",
            "api-key": "super-secret-key",
            "content-type": "application/json",
        }
        preview = preview_request_headers(headers)
        self.assertEqual(preview["authorization"], "<redacted>")
        self.assertEqual(preview["api-key"], "<redacted>")
        self.assertEqual(preview["x-request-id"], "req-1")

        body = preview_request_body(
            '{"api_key":"super-secret-key","query":"wood toy"}',
            content_type="application/json",
        )
        self.assertIn("<redacted>", body or "")

        self.assertIn("<redacted>", log_redacted_value("api_key=super-secret-key"))

    def test_neo4j_graph_marks_write_operations_retryable_only_with_request_id(self) -> None:
        from infrastructure.graph.neo4j_graph import Neo4jGraph

        class FakeExecutor:
            def __init__(self) -> None:
                self.requests: list[object] = []

            def run_read(self, request, callback):
                return callback(SimpleNamespace(run=lambda *_a, **_k: None))

            def run_write(self, request, callback):
                self.requests.append(request)
                return callback(SimpleNamespace(run=lambda *_a, **_k: None))

        executor = FakeExecutor()
        graph = Neo4jGraph(
            settings=SimpleNamespace(database=None, retry_attempts=1, log_latency=False, perf_log=False),
            query_executor=executor,  # type: ignore[arg-type]
        )

        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-id", request_id=None)
        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-id", request_id="op-1")

        first_request, second_request = executor.requests[0], executor.requests[1]
        self.assertFalse(first_request.retryable)
        self.assertTrue(second_request.retryable)

    def test_startup_checks_fail_fast_after_retry_cap(self) -> None:
        from api.factory import _run_startup_checks
        from api.settings import load_app_settings

        class FakeService:
            def get_provider(self, _name: str) -> object:
                return SimpleNamespace()

            def graph_runtime_summary(self) -> dict[str, str]:
                return {"mode": "test"}

            def clear_graph_cache(self) -> dict[str, bool]:
                return {"ok": True}

        with patch.dict(os.environ, {"STARTUP_CHECK_ATTEMPTS": "2"}, clear=False):
            settings = load_app_settings()

        with patch("api.factory._check_qdrant", side_effect=RuntimeError("qdrant unavailable")) as check_q:
            with patch("api.factory._check_neo4j") as check_neo:
                with patch("api.factory.time.sleep") as sleep:
                    with self.assertRaises(RuntimeError):
                        _run_startup_checks(
                            settings,
                            service=FakeService(),
                            logger=__import__("logging").getLogger("tests.reliability.startup"),
                        )
            self.assertEqual(check_q.call_count, 2)
            check_neo.assert_not_called()
            self.assertEqual(sleep.call_count, 1)

    def test_startup_checks_skip_provider_probe_for_non_ollama_driver(self) -> None:
        from api.factory import _run_startup_checks
        from api.settings import load_app_settings

        calls = {"provider": 0}

        class FakeService:
            def get_provider(self, _name: str) -> object:
                calls["provider"] += 1
                return SimpleNamespace(base="http://provider")

            def graph_runtime_summary(self) -> dict[str, str]:
                return {"mode": "test"}

            def clear_graph_cache(self) -> dict[str, bool]:
                return {"ok": True}

        with patch.dict(os.environ, {"RAG_DEFAULT_PROVIDER": "openai", "STARTUP_CHECK_ATTEMPTS": "1"}, clear=False):
            settings = load_app_settings()

        with patch("api.factory._check_qdrant"):
            with patch("api.factory._check_neo4j"):
                _run_startup_checks(
                    settings,
                    service=FakeService(),
                    logger=__import__("logging").getLogger("tests.reliability.startup"),
                )

        self.assertEqual(calls["provider"], 0)

    def test_ollama_startup_probe_accepts_base_url_with_api_suffix(self) -> None:
        from api.settings import load_app_settings
        from api.startup_checks import check_provider_availability

        calls: list[str] = []

        class FakeResponse:
            status_code = 200

            def raise_for_status(self) -> None:
                raise AssertionError("raise_for_status should not be called for 200 responses")

        class FakeRequests:
            @staticmethod
            def get(url: str, **_kwargs):
                calls.append(url)
                return FakeResponse()

        class FakeService:
            def get_provider(self, _name: str) -> object:
                return SimpleNamespace(base="http://ollama:11434/api")

        settings = load_app_settings({"RAG_DEFAULT_PROVIDER": "ollama"})

        with patch("api.startup_checks._requests_module", return_value=FakeRequests):
            check_provider_availability(FakeService(), settings, 1.0)

        self.assertEqual(calls, ["http://ollama:11434/api/tags"])

    def test_ollama_startup_probe_accepts_base_url_without_api_suffix(self) -> None:
        from api.settings import load_app_settings
        from api.startup_checks import check_provider_availability

        calls: list[str] = []

        class FakeResponse:
            status_code = 200

            def raise_for_status(self) -> None:
                raise AssertionError("raise_for_status should not be called for 200 responses")

        class FakeRequests:
            @staticmethod
            def get(url: str, **_kwargs):
                calls.append(url)
                return FakeResponse()

        class FakeService:
            def get_provider(self, _name: str) -> object:
                return SimpleNamespace(base="http://ollama:11434")

        settings = load_app_settings({"RAG_DEFAULT_PROVIDER": "ollama"})

        with patch("api.startup_checks._requests_module", return_value=FakeRequests):
            check_provider_availability(FakeService(), settings, 1.0)

        self.assertEqual(calls, ["http://ollama:11434/api/tags"])

    def test_qdrant_transport_emits_retry_attempt_telemetry(self) -> None:
        from infrastructure.vectorstore.qdrant_requests import QdrantRequest
        from infrastructure.vectorstore.qdrant_transport import QdrantHTTPTransport

        class FakeResponse:
            def __init__(self, status_code: int) -> None:
                self.status_code = status_code
                self.text = "ok"

        class FakeSession:
            def __init__(self) -> None:
                self.calls = 0

            def request(self, *_args, **_kwargs):
                self.calls += 1
                if self.calls == 1:
                    return FakeResponse(503)
                return FakeResponse(200)

        session = FakeSession()
        transport = QdrantHTTPTransport(
            base_url="http://qdrant",
            api_key=None,
            default_timeout=1.0,
            operation_attempts={"qdrant.upsert_points": 2},
            backoff_seconds=0.0,
            session=session,
        )
        request = QdrantRequest(
            "PUT",
            "/collections/docs/points",
            json_body={"points": []},
            timeout=1.0,
            operation="qdrant.upsert_points",
            retryable=True,
            operation_id="op-1",
        )

        with self.assertLogs("infrastructure.vectorstore.qdrant_transport", level="INFO") as capture:
            response = transport.send(request)

        self.assertEqual(response.status_code, 200)
        self.assertEqual(session.calls, 2)
        logs = "\n".join(capture.output)
        self.assertIn("event=adapter.qdrant.request", logs)
        self.assertIn("operation=qdrant.upsert_points", logs)
        self.assertIn("request_id=op-1", logs)
        self.assertIn("attempt=1/2", logs)
        self.assertIn("attempt=2/2", logs)
        self.assertIn("retry_after_ms", logs)
