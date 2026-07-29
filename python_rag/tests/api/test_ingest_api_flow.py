"""Vertical FastAPI flow tests for document ingestion requests.

The suite exercises validation and HTTP-to-application delegation with an
injected RAG service, while persistence and model providers remain fake.
"""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Any
from unittest.mock import Mock, patch

from fastapi.testclient import TestClient


PYTHON_RAG_ROOT = Path(__file__).resolve().parents[2]
if str(PYTHON_RAG_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_RAG_ROOT))


class _FakeProvider:
    """Provider value returned by the injected RAG service boundary."""

    embed_model = "test-embedding-model"
    rag_model = "test-chat-model"


class _FakeRagService:
    """Injected service fake that records provider resolution."""

    def __init__(self) -> None:
        self.provider = _FakeProvider()
        self.provider_requests: list[str] = []

    def get_provider(self, name: str) -> _FakeProvider:
        self.provider_requests.append(name)
        return self.provider


def _build_test_client(tmp_path: Path, service: _FakeRagService) -> TestClient:
    from api.factory import build_app
    from api.settings import load_app_settings

    settings = load_app_settings({"PYTHON_RAG_API_FLOW_TEST": "1"})
    app = build_app(
        rag_service=service,
        public_dir=tmp_path,
        qdrant_factory=lambda: object(),
        logger_name="test.ingest_api_flow",
        app_settings=settings,
    )
    return TestClient(app)


def _ingest_payload(*, idempotency_key: str = "body-ingest-key") -> dict[str, Any]:
    return {
        "docs": [
            {
                "id": "fee-document-1",
                "text": "The semester fee is listed in this document.",
                "payload": {"title": "Fee schedule"},
            }
        ],
        "provider": "test-provider",
        "collection": "hawki_gebuehren",
        "dataset_id": "Gebuehren",
        "idempotency_key": idempotency_key,
        "graph": False,
    }


class TestIngestApiFlow:
    """Describe ingestion validation, idempotency, delegation, and responses."""

    def test_ingest_delegates_validated_input_and_prefers_header_idempotency(
        self,
        tmp_path: Path,
    ) -> None:
        service = _FakeRagService()
        calls: list[dict[str, Any]] = []

        def ingest_use_case(
            body: Any,
            *,
            rag_service: Any,
            get_provider: Any,
            public_dir: Path,
            idempotency_key: str | None,
            graph_debug: bool,
        ) -> dict[str, Any]:
            calls.append(
                {
                    "body": body,
                    "service": rag_service,
                    "provider": get_provider(body.provider),
                    "public_dir": public_dir,
                    "idempotency_key": idempotency_key,
                    "graph_debug": graph_debug,
                }
            )
            return {
                "ok": True,
                "points": 1,
                "summary": {
                    "collection": body.collection,
                    "processed_docs": len(body.docs),
                },
                "graph_only": False,
            }

        with patch("application.ingest.ingest_documents", side_effect=ingest_use_case) as delegate:
            with _build_test_client(tmp_path, service) as client:
                header_response = client.post(
                    "/ingest",
                    headers={
                        "Idempotency-Key": "header-ingest-key",
                        "X-Request-ID": "ingest-success-1",
                    },
                    json=_ingest_payload(),
                )
                body_response = client.post(
                    "/ingest",
                    headers={"X-Request-ID": "ingest-success-2"},
                    json=_ingest_payload(idempotency_key="body-only-key"),
                )

        expected_response = {
            "ok": True,
            "points": 1,
            "summary": {
                "collection": "hawki_gebuehren",
                "processed_docs": 1,
            },
            "graph_only": False,
        }
        assert header_response.status_code == 200
        assert header_response.json() == expected_response
        assert header_response.headers["X-Request-ID"] == "ingest-success-1"
        assert body_response.status_code == 200
        assert body_response.json() == expected_response
        assert body_response.headers["X-Request-ID"] == "ingest-success-2"
        assert [call["idempotency_key"] for call in calls] == [
            "header-ingest-key",
            "body-only-key",
        ]
        assert all(call["body"].__class__.__name__ == "IngestRequest" for call in calls)
        assert all(call["body"].chunk_chars == 1200 for call in calls)
        assert all(call["service"] is service for call in calls)
        assert all(call["provider"] is service.provider for call in calls)
        assert all(call["public_dir"] == tmp_path for call in calls)
        assert service.provider_requests == ["test-provider", "test-provider"]
        assert delegate.call_count == 2

    def test_invalid_document_is_rejected_before_ingestion_delegation(self, tmp_path: Path) -> None:
        service = _FakeRagService()
        delegate = Mock()
        invalid_payload = _ingest_payload()
        del invalid_payload["docs"][0]["text"]

        with patch("application.ingest.ingest_documents", delegate):
            with _build_test_client(tmp_path, service) as client:
                response = client.post("/ingest", json=invalid_payload)

        assert response.status_code == 422
        assert set(response.json()) == {"detail"}
        assert any(
            error["loc"] == ["body", "docs", 0, "text"] and error["type"] == "missing"
            for error in response.json()["detail"]
        )
        delegate.assert_not_called()

    def test_ingestion_runtime_failure_keeps_the_public_error_envelope(self, tmp_path: Path) -> None:
        service = _FakeRagService()

        with patch(
            "application.ingest.ingest_documents",
            side_effect=RuntimeError("Qdrant write failed."),
        ):
            with _build_test_client(tmp_path, service) as client:
                response = client.post(
                    "/ingest",
                    headers={"X-Request-ID": "ingest-error-1"},
                    json=_ingest_payload(),
                )

        assert response.status_code == 502
        assert response.json() == {
            "error": {
                "type": "RuntimeError",
                "status": 502,
                "message": "Qdrant write failed.",
                "path": "/ingest",
                "request_id": "ingest-error-1",
            }
        }
