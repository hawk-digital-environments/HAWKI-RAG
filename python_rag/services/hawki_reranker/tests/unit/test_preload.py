"""Startup preload behavior of the reranker service."""

from __future__ import annotations

from typing import Any

import pytest

from hawki_reranker import main
from hawki_reranker.settings import RerankerSettings


class _FakeLazyModel:
    """Mimics LazyCrossEncoder: preload() plus is_loaded."""

    def __init__(self) -> None:
        self.preload_calls = 0
        self.predict_calls: list[list[list[str]]] = []

    @property
    def is_loaded(self) -> bool:
        return self.preload_calls > 0

    @property
    def model_name(self) -> str:
        return "test-model"

    def preload(self) -> None:
        self.preload_calls += 1

    def predict(self, pairs: list[list[str]]) -> list[float]:
        self.predict_calls.append(pairs)
        return [0.5]


@pytest.fixture(autouse=True)
def _clean_preload_env(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("HAWKI_RERANKER_PRELOAD", raising=False)


def test_lifespan_preloads_and_validates_the_model(
    asgi_test_client_class: type[Any],
) -> None:
    model = _FakeLazyModel()
    app = main.create_app(
        settings=RerankerSettings(model_name="test-model"),
        model_factory=lambda _name: model,
    )

    response = asgi_test_client_class(app).get("/health")

    assert response.status_code == 200
    assert response.json() == {"ok": True, "model_loaded": True}
    assert model.preload_calls == 1
    assert model.predict_calls == [[["warmup query", "warmup document"]]]


def test_lifespan_skips_preload_when_disabled(
    asgi_test_client_class: type[Any],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("HAWKI_RERANKER_PRELOAD", "false")
    model = _FakeLazyModel()
    app = main.create_app(
        settings=RerankerSettings(model_name="test-model"),
        model_factory=lambda _name: model,
    )

    response = asgi_test_client_class(app).get("/health")

    assert response.status_code == 200
    assert response.json() == {"ok": True, "model_loaded": False}
    assert model.preload_calls == 0
    assert model.predict_calls == []


def test_lifespan_falls_back_to_lazy_loading_on_preload_failure(
    asgi_test_client_class: type[Any],
) -> None:
    class _BrokenModel(_FakeLazyModel):
        def preload(self) -> None:
            raise RuntimeError("weights unavailable")

    model = _BrokenModel()
    app = main.create_app(
        settings=RerankerSettings(model_name="test-model"),
        model_factory=lambda _name: model,
    )

    response = asgi_test_client_class(app).get("/health")

    # The service still serves; the first rerank request will retry loading.
    assert response.status_code == 200
    assert response.json() == {"ok": True, "model_loaded": False}
