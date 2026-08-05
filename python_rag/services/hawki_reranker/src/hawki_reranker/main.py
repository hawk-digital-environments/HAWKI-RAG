"""Cohere-compatible HTTP surface for the local reranking model."""

from __future__ import annotations

from collections.abc import Callable

from fastapi import FastAPI

from hawki_reranker.errors import InvalidRerankRequest
from hawki_reranker.model import LazyCrossEncoder, RerankingModel
from hawki_reranker.schemas import RerankRequest, RerankResponse, RerankResult
from hawki_reranker.settings import RerankerSettings, load_settings


def create_app(
    *,
    settings: RerankerSettings | None = None,
    model_factory: Callable[[str], RerankingModel] = LazyCrossEncoder,
) -> FastAPI:
    active_settings = settings or load_settings()
    application = FastAPI(title="HAWKI Reranker", version="0.1.0")
    application.state.reranking_model = model_factory(active_settings.model_name)

    @application.get("/health")
    def health() -> dict[str, bool]:
        return {"ok": True}

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


app = create_app()


def run() -> None:
    import uvicorn

    settings = load_settings()
    uvicorn.run("hawki_reranker.main:app", host=settings.host, port=settings.port)


__all__ = ["app", "create_app", "run"]
