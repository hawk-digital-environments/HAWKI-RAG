"""Cohere-compatible HTTP surface for the local reranking model."""

from __future__ import annotations

import logging
import os
import time
from collections.abc import Callable
from contextlib import asynccontextmanager

from fastapi import FastAPI
from hawki_rag_contracts.retrieval.rerank import (
    RerankRequest,
    RerankResponse,
    RerankResult,
)

from hawki_reranker.errors import InvalidRerankRequest
from hawki_reranker.model import LazyCrossEncoder, RerankingModel
from hawki_reranker.settings import RerankerSettings, load_settings

logger = logging.getLogger(__name__)

_VALIDATION_PAIR = [["warmup query", "warmup document"]]


def _preload_enabled() -> bool:
    """Whether the model loads during startup instead of the first request."""

    value = os.environ.get("HAWKI_RERANKER_PRELOAD", "").strip().lower()
    if not value:
        return True
    return value in {"1", "true", "yes", "on"}


def create_app(
    *,
    settings: RerankerSettings | None = None,
    model_factory: Callable[[str], RerankingModel] = LazyCrossEncoder,
) -> FastAPI:
    active_settings = settings or load_settings()
    application = FastAPI(
        title="HAWKI Reranker",
        version="0.1.0",
        lifespan=_build_preload_lifespan(),
    )
    application.state.reranking_model = model_factory(active_settings.model_name)

    @application.get("/health")
    def health() -> dict[str, object]:
        return {
            "ok": True,
            "model_loaded": bool(
                getattr(application.state.reranking_model, "is_loaded", True)
            ),
        }

    @application.post("/v1/rerank", response_model=RerankResponse)
    def rerank(request: RerankRequest) -> RerankResponse:
        query = request.query.strip()
        documents = [str(document) for document in request.documents]
        if (
            not query
            or not documents
            or any(not document.strip() for document in documents)
        ):
            raise InvalidRerankRequest()

        pairs = [[query, document] for document in documents]
        scores = application.state.reranking_model.predict(pairs)
        ranked = sorted(
            enumerate(scores), key=lambda item: float(item[1]), reverse=True
        )
        limit = request.top_n or len(ranked)
        return RerankResponse(
            results=[
                RerankResult(
                    index=index,
                    document=documents[index],
                    relevance_score=float(score),
                )
                for index, score in ranked[:limit]
            ]
        )

    return application


def _build_preload_lifespan():
    """Load the model and run one validation rerank before serving.

    The preload only runs for models that support it (the production
    ``LazyCrossEncoder``); injected test fakes without ``preload`` are
    skipped so existing behavior stays untouched. Failures log and fall
    back to lazy loading so the service still starts.
    """

    @asynccontextmanager
    async def preload_model(application: FastAPI):
        model = application.state.reranking_model
        if _preload_enabled() and hasattr(model, "preload"):
            started = time.perf_counter()
            try:
                model.preload()
                model.predict(_VALIDATION_PAIR)
                logger.info(
                    "reranker:preload complete model=%s elapsed_ms=%.0f",
                    getattr(model, "model_name", "unknown"),
                    (time.perf_counter() - started) * 1000,
                )
            except Exception:
                logger.exception(
                    "reranker:preload failed; falling back to lazy loading"
                )
        yield

    return preload_model


app = create_app()


def run() -> None:
    import uvicorn

    settings = load_settings()
    uvicorn.run("hawki_reranker.main:app", host=settings.host, port=settings.port)


__all__ = ["app", "create_app", "run"]
