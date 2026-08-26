"""Operational reliability scenarios for retries, startup checks, redaction, and optional gateways."""

from __future__ import annotations

from pathlib import Path
from types import SimpleNamespace
import sys
import unittest
from unittest.mock import Mock, patch

from neo4j.exceptions import ClientError, ServiceUnavailable
from requests import ConnectionError as RequestsConnectionError

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


class ReliabilityContractTests(unittest.TestCase):
    """Verify production boundaries are retry-safe, observable, secret-safe, and fail predictably."""

    def test_raganything_file_logging_is_not_a_bridge_setting(self) -> None:
        from hawki_bridge.settings import load_settings

        settings = load_settings({})
        configured_settings = load_settings(
            {
                "HAWKI_RAG_RAGANYTHING_LOG_PATH": "/shared/logs/raganything_runtime.log",
            }
        )

        self.assertEqual(configured_settings, settings)
        self.assertFalse(hasattr(configured_settings, "raganything_log_path"))

    def test_qdrant_gateway_marks_write_operations_retryable_only_with_idempotency_key(
        self,
    ) -> None:
        from hawki_vector_store.gateway import QdrantHTTPGateway
        from hawki_vector_store.requests import QdrantRequest

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
        from hawki_rag_resilience.redaction import (
            log_redacted_value,
            preview_request_body,
            preview_request_headers,
            sanitize_for_log,
        )

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

        authorization = log_redacted_value("Authorization: Bearer super-secret-token")
        json_authorization = log_redacted_value(
            '{"authorization":"Bearer super-secret-token"}'
        )
        self.assertEqual(authorization, "Authorization=<redacted>")
        self.assertNotIn("super-secret-token", json_authorization)
        self.assertIn("authorization=<redacted>", json_authorization)

        for unsafe in (
            "token=query-secret-value",
            "https://example.test/path?x=1&token=query-secret-value&safe=1",
            "converter_token=converter-secret-value",
            "Authorization: Basic dXNlcjpzdXBlcnNlY3JldA==",
            'Authorization: Digest username="admin", nonce="digest-secret"',
            "https://user:password@example.test/private",
        ):
            redacted = sanitize_for_log(unsafe)
            self.assertNotIn("secret", redacted)
            self.assertNotIn("password", redacted)

        bounded = sanitize_for_log("x" * 3000, max_length=2048)
        self.assertEqual(len(bounded), 2048)
        self.assertTrue(bounded.endswith("..."))

    def test_neo4j_graph_preserves_request_id_for_managed_write_telemetry(
        self,
    ) -> None:
        from hawki_graph_store.graph import Neo4jGraph

        class FakeExecutor:
            def __init__(self) -> None:
                self.requests: list[object] = []

            def run_read(self, request, callback):
                return callback(SimpleNamespace(run=lambda *_a, **_k: None))

            def run_write(self, request, callback):
                self.requests.append(request)
                result = SimpleNamespace(consume=lambda: None)
                return callback(SimpleNamespace(run=lambda *_a, **_k: result))

        executor = FakeExecutor()
        graph = Neo4jGraph(
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            settings=SimpleNamespace(database=None, log_latency=False, perf_log=False),
            query_executor=executor,  # type: ignore[arg-type]
        )

        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-id", request_id=None)
        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-id", request_id="op-1")

        first_request, second_request = executor.requests[0], executor.requests[1]
        self.assertIsNone(first_request.request_id)
        self.assertEqual(second_request.request_id, "op-1")

    def test_startup_checks_fail_fast_after_retry_cap(self) -> None:
        from hawki_bridge.settings import load_settings
        from hawki_bridge.startup_checks import run_startup_checks

        settings = load_settings({"STARTUP_CHECK_ATTEMPTS": "2"})

        check_qdrant = Mock(side_effect=RequestsConnectionError("qdrant unavailable"))
        check_neo4j = Mock()
        with patch("hawki_bridge.startup_checks.time.sleep") as sleep:
            with self.assertRaises(RequestsConnectionError):
                run_startup_checks(
                    settings,
                    logger=__import__("logging").getLogger("tests.reliability.startup"),
                    check_qdrant_fn=check_qdrant,
                    check_neo4j_fn=check_neo4j,
                )
        self.assertEqual(check_qdrant.call_count, 2)
        check_neo4j.assert_not_called()
        self.assertEqual(sleep.call_count, 1)

    def test_neo4j_startup_check_uses_driver_retryability(
        self,
    ) -> None:
        from hawki_bridge.settings import load_settings
        from hawki_bridge.startup_checks import run_startup_checks

        settings = load_settings({"STARTUP_CHECK_ATTEMPTS": "2"})
        logger = __import__("logging").getLogger("tests.reliability.neo4j-startup")

        retryable_check = Mock(side_effect=ServiceUnavailable("not ready"))
        with patch("hawki_bridge.startup_checks.time.sleep") as sleep:
            with self.assertRaises(ServiceUnavailable):
                run_startup_checks(
                    settings,
                    logger=logger,
                    check_qdrant_fn=Mock(),
                    check_neo4j_fn=retryable_check,
                )
        self.assertEqual(retryable_check.call_count, 2)
        self.assertEqual(sleep.call_count, 1)

        fail_fast_check = Mock(side_effect=ClientError("bad query or credentials"))
        with patch("hawki_bridge.startup_checks.time.sleep") as sleep:
            with self.assertRaises(ClientError):
                run_startup_checks(
                    settings,
                    logger=logger,
                    check_qdrant_fn=Mock(),
                    check_neo4j_fn=fail_fast_check,
                )
        self.assertEqual(fail_fast_check.call_count, 1)
        sleep.assert_not_called()

    def test_qdrant_transport_emits_retry_attempt_telemetry(self) -> None:
        from hawki_vector_store.requests import QdrantRequest
        from hawki_vector_store.transport import QdrantHTTPTransport

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

        with self.assertLogs("hawki_vector_store.transport", level="INFO") as capture:
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
