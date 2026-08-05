"""HTTP and lazy-loading contracts for the standalone reranker service."""

from __future__ import annotations

from asgi_client import ASGITestClient as TestClient

from hawki_rag_contracts.rerank import RerankRequest as ContractRerankRequest
from hawki_reranker.main import create_app
from hawki_reranker.schemas import RerankRequest as ServiceRerankRequest
from hawki_reranker.settings import RerankerSettings


class FakeModel:
    def __init__(self, model_name: str) -> None:
        self.model_name = model_name
        self.calls: list[list[list[str]]] = []

    def predict(self, pairs: list[list[str]]) -> list[float]:
        self.calls.append(pairs)
        return [0.2, 0.9, 0.4]


def test_service_reexports_the_canonical_reranker_contract() -> None:
    assert ServiceRerankRequest is ContractRerankRequest


def test_health_does_not_invoke_the_model() -> None:
    model = FakeModel("test-model")
    app = create_app(
        settings=RerankerSettings(model_name="test-model"),
        model_factory=lambda _name: model,
    )

    response = TestClient(app).get("/health")

    assert response.status_code == 200
    assert response.json() == {"ok": True}
    assert model.calls == []


def test_rerank_preserves_document_indices_and_applies_top_n() -> None:
    model = FakeModel("test-model")
    app = create_app(
        settings=RerankerSettings(model_name="test-model"),
        model_factory=lambda _name: model,
    )

    response = TestClient(app).post(
        "/v1/rerank",
        json={
            "query": "authorization",
            "documents": ["first", "best", "middle"],
            "top_n": 2,
        },
    )

    assert response.status_code == 200
    assert response.json() == {
        "results": [
            {"index": 1, "document": "best", "relevance_score": 0.9},
            {"index": 2, "document": "middle", "relevance_score": 0.4},
        ]
    }
    assert model.calls == [
        [
            ["authorization", "first"],
            ["authorization", "best"],
            ["authorization", "middle"],
        ]
    ]


def test_whitespace_only_content_returns_a_client_error() -> None:
    app = create_app(
        settings=RerankerSettings(model_name="test-model"),
        model_factory=FakeModel,
    )

    response = TestClient(app).post(
        "/v1/rerank",
        json={"query": "question", "documents": ["  "]},
    )

    assert response.status_code == 400
    assert response.json() == {"detail": "query and documents are required"}
