"""Cohere-compatible reranker wire contracts."""

from __future__ import annotations

from pydantic import BaseModel, ConfigDict, Field, FiniteFloat


class RerankRequest(BaseModel):
    """Request accepted by the dedicated reranker service."""

    model_config = ConfigDict(extra="forbid")

    query: str = Field(min_length=1)
    documents: list[str] = Field(min_length=1)
    top_n: int | None = Field(default=None, gt=0)
    model: str | None = Field(default=None, max_length=191)


class RerankResult(BaseModel):
    """One ranked document in the Cohere-compatible response."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    index: int = Field(ge=0)
    document: str
    relevance_score: FiniteFloat


class RerankResponse(BaseModel):
    """Ranked reranker response."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    results: list[RerankResult]


__all__ = ["RerankRequest", "RerankResponse", "RerankResult"]
