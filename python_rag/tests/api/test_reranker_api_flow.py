"""Direct FastAPI scenarios for the optional local CrossEncoder reranker."""

from __future__ import annotations

import importlib
import sys
from collections.abc import Iterator
from pathlib import Path
from types import ModuleType

import pytest
from fastapi.testclient import TestClient


PYTHON_RAG_ROOT = Path(__file__).resolve().parents[2]
if str(PYTHON_RAG_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_RAG_ROOT))


class _FakeCrossEncoder:
    """Stand in for model loading while preserving the real HTTP route behavior."""

    def __init__(self, model_name: str) -> None:
        self.model_name = model_name
        self.predict_calls: list[list[list[str]]] = []

    def predict(self, pairs: list[list[str]]) -> list[float]:
        self.predict_calls.append(pairs)
        return [0.15, 0.95, 0.65][: len(pairs)]


@pytest.fixture
def reranker_module(monkeypatch: pytest.MonkeyPatch) -> Iterator[ModuleType]:
    """Import the service only after replacing its download-heavy model boundary."""

    module_name = "infrastructure.rerank.local_reranker.app"
    previous_module = sys.modules.pop(module_name, None)
    fake_sentence_transformers = ModuleType("sentence_transformers")
    fake_sentence_transformers.CrossEncoder = _FakeCrossEncoder  # type: ignore[attr-defined]
    monkeypatch.setitem(
        sys.modules,
        "sentence_transformers",
        fake_sentence_transformers,
    )

    module = importlib.import_module(module_name)
    try:
        yield module
    finally:
        sys.modules.pop(module_name, None)
        if previous_module is not None:
            sys.modules[module_name] = previous_module


class TestRerankerApiFlow:
    """Describe local reranker health, validation, ranking, and client errors."""

    def test_health_reports_ready_without_invoking_the_model(
        self,
        reranker_module: ModuleType,
    ) -> None:
        model = reranker_module.model

        with TestClient(reranker_module.app) as client:
            response = client.get("/health")

        assert response.status_code == 200
        assert response.json() == {"ok": True}
        assert model.model_name == "mixedbread-ai/mxbai-rerank-base-v1"
        assert model.predict_calls == []

    def test_rerank_serializes_ranked_documents_and_scores(
        self,
        reranker_module: ModuleType,
    ) -> None:
        model = reranker_module.model

        with TestClient(reranker_module.app) as client:
            response = client.post(
                "/v1/rerank",
                json={
                    "query": "  Which document explains fees?  ",
                    "documents": [
                        "A general introduction.",
                        "The semester fee is 320 euros.",
                        "Registration information.",
                    ],
                    "top_n": 2,
                    "model": "local-reranker",
                },
            )

        assert response.status_code == 200
        assert response.json() == {
            "results": [
                {
                    "index": 1,
                    "document": "The semester fee is 320 euros.",
                    "relevance_score": 0.95,
                },
                {
                    "index": 2,
                    "document": "Registration information.",
                    "relevance_score": 0.65,
                },
            ]
        }
        assert model.predict_calls == [
            [
                ["Which document explains fees?", "A general introduction."],
                [
                    "Which document explains fees?",
                    "The semester fee is 320 euros.",
                ],
                ["Which document explains fees?", "Registration information."],
            ]
        ]

    def test_missing_documents_is_rejected_before_model_prediction(
        self,
        reranker_module: ModuleType,
    ) -> None:
        model = reranker_module.model

        with TestClient(reranker_module.app) as client:
            response = client.post(
                "/v1/rerank",
                json={"query": "Which document explains fees?"},
            )

        assert response.status_code == 422
        assert any(
            error["loc"] == ["body", "documents"]
            and error["type"] == "missing"
            for error in response.json()["detail"]
        )
        assert model.predict_calls == []

    def test_blank_query_returns_the_service_client_error_without_model_prediction(
        self,
        reranker_module: ModuleType,
    ) -> None:
        model = reranker_module.model

        with TestClient(reranker_module.app) as client:
            response = client.post(
                "/v1/rerank",
                json={"query": "   ", "documents": ["One document."]},
            )

        assert response.status_code == 400
        assert response.json() == {
            "detail": "query and documents are required",
        }
        assert model.predict_calls == []
