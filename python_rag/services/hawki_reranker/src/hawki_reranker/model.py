"""Lazy model adapter; loading occurs once on the first rerank request."""

from __future__ import annotations

from threading import Lock
from typing import Any, Protocol


class RerankingModel(Protocol):
    def predict(self, pairs: list[list[str]]) -> Any:
        """Return one numeric relevance score per query/document pair."""


class LazyCrossEncoder:
    def __init__(self, model_name: str) -> None:
        self.model_name = model_name
        self._model: RerankingModel | None = None
        self._lock = Lock()

    def predict(self, pairs: list[list[str]]) -> Any:
        return self._get_model().predict(pairs)

    def _get_model(self) -> RerankingModel:
        if self._model is not None:
            return self._model
        with self._lock:
            if self._model is None:
                from sentence_transformers import CrossEncoder

                self._model = CrossEncoder(self.model_name)
        return self._model


__all__ = ["LazyCrossEncoder", "RerankingModel"]
