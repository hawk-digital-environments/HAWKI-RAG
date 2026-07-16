"""Direct FastAPI scenarios for graph extraction requests and public failures."""

from __future__ import annotations

import sys
from pathlib import Path

from fastapi.testclient import TestClient


PYTHON_RAG_ROOT = Path(__file__).resolve().parents[2]
if str(PYTHON_RAG_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_RAG_ROOT))


class _FakeGraphService:
    """Record graph extraction calls without loading a model or contacting Neo4j."""

    def __init__(
        self,
        *,
        triplets: list[tuple[str, str, str]] | None = None,
        failure: RuntimeError | None = None,
    ) -> None:
        self.triplets = triplets if triplets is not None else [
            ("HAWKI", "uses", "Qdrant"),
            ("HAWKI", "stores relations in", "Neo4j"),
        ]
        self.failure = failure
        self.extraction_calls: list[tuple[str, str]] = []

    def extract_triplets(self, text: str, engine: str) -> list[tuple[str, str, str]]:
        self.extraction_calls.append((text, engine))
        if self.failure is not None:
            raise self.failure
        return self.triplets


def _build_test_client(tmp_path: Path, service: _FakeGraphService) -> TestClient:
    from api.factory import build_app
    from api.settings import load_app_settings

    settings = load_app_settings({"PYTHON_RAG_API_FLOW_TEST": "1"})
    app = build_app(
        rag_service=service,
        public_dir=tmp_path,
        qdrant_factory=lambda: object(),
        logger_name="test.graph_api_flow",
        app_settings=settings,
    )
    return TestClient(app)


class TestGraphApiFlow:
    """Describe graph HTTP validation, delegation, and normalized error output."""

    def test_valid_text_runs_real_graph_flow_without_persisting_unscoped_facts(
        self,
        tmp_path: Path,
    ) -> None:
        service = _FakeGraphService()

        with _build_test_client(tmp_path, service) as client:
            response = client.post(
                "/graph/from-text",
                headers={"X-Request-ID": "graph-success-1"},
                json={
                    "text": "HAWKI uses Qdrant and stores relations in Neo4j.",
                    "engine": "raganything",
                },
            )

        assert response.status_code == 200
        assert response.json() == {
            "ok": True,
            "triplets": 2,
            "persisted": False,
        }
        assert response.headers["X-Request-ID"] == "graph-success-1"
        assert service.extraction_calls == [
            (
                "HAWKI uses Qdrant and stores relations in Neo4j.",
                "raganything",
            )
        ]

    def test_missing_text_is_rejected_before_graph_extraction(self, tmp_path: Path) -> None:
        service = _FakeGraphService()

        with _build_test_client(tmp_path, service) as client:
            response = client.post(
                "/graph/from-text",
                json={"engine": "raganything"},
            )

        assert response.status_code == 422
        assert any(
            error["loc"] == ["body", "text"] and error["type"] == "missing"
            for error in response.json()["detail"]
        )
        assert service.extraction_calls == []

    def test_extraction_failure_uses_the_main_bridge_error_envelope(
        self,
        tmp_path: Path,
    ) -> None:
        service = _FakeGraphService(
            failure=RuntimeError("Graph extraction backend unavailable."),
        )

        with _build_test_client(tmp_path, service) as client:
            response = client.post(
                "/graph/from-text",
                headers={"X-Request-ID": "graph-error-1"},
                json={"text": "Extract this graph."},
            )

        assert response.status_code == 502
        assert response.json() == {
            "error": {
                "type": "RuntimeError",
                "status": 502,
                "message": "Graph extraction backend unavailable.",
                "path": "/graph/from-text",
                "request_id": "graph-error-1",
            }
        }
        assert service.extraction_calls == [
            ("Extract this graph.", "raganything")
        ]
