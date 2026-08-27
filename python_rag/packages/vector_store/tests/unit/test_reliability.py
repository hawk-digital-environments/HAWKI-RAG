"""Vector transport retry ownership and retry telemetry."""

from __future__ import annotations

from types import SimpleNamespace
import unittest


class VectorReliabilityTests(unittest.TestCase):
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
