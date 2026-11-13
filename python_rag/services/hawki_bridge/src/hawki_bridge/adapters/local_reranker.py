"""In-process CrossEncoder reranking with LiteLLM response types."""

from collections.abc import Callable, Iterable
from math import isfinite
from threading import Lock
from typing import Protocol

from litellm.types.rerank import RerankResponse


class _RerankingModel(Protocol):
    def predict(self, pairs: list[tuple[str, str]]) -> Iterable[float]: ...


def _create_cross_encoder(model_name: str) -> _RerankingModel:
    # Keep model dependencies and loading out of adapter construction.
    from sentence_transformers import CrossEncoder

    return CrossEncoder(model_name)


class LocalReranker:
    """Load one model lazily and serialize inference within this instance."""

    def __init__(
        self,
        model_name: str = "mixedbread-ai/mxbai-rerank-base-v1",
        *,
        model_factory: Callable[[str], _RerankingModel] | None = None,
    ) -> None:
        if not model_name.strip():
            raise ValueError("model_name must be non-empty")
        self.model_name = model_name
        self._model_factory = model_factory or _create_cross_encoder
        self._model: _RerankingModel | None = None
        self._inference_lock = Lock()

    def rerank(
        self,
        query: str,
        documents: list[str],
        top_n: int | None = None,
    ) -> RerankResponse:
        """Rank all documents, then limit results; zero skips model loading."""
        if top_n is not None and top_n < 0:
            raise ValueError("top_n must be non-negative when supplied")
        if not documents or top_n == 0:
            return RerankResponse(results=[])

        pairs = [(query, document) for document in documents]
        # One lock protects both first-time loading and every prediction.
        with self._inference_lock:
            if self._model is None:
                self._model = self._model_factory(self.model_name)
            scores = [float(score) for score in self._model.predict(pairs)]

        if len(scores) != len(documents) or not all(map(isfinite, scores)):
            raise ValueError("model must return one finite score per document")
        ranked = sorted(enumerate(scores), key=lambda item: item[1], reverse=True)
        return RerankResponse(
            results=[
                {"index": index, "relevance_score": score}
                for index, score in ranked[:top_n]
            ]
        )
