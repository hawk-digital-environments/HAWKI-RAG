"""Typed query request and response contracts."""

from __future__ import annotations

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    FiniteFloat,
    JsonValue,
    model_validator,
)

from hawki_rag_contracts.auth_scope import AuthorizedQueryScope


QueryFilterScalar = str | int | FiniteFloat | bool


class QueryRequest(BaseModel):
    """Trusted request accepted by the internal query bridge."""

    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    query: str = Field(min_length=1)
    authorized_scope: AuthorizedQueryScope
    top_k: int = Field(default=5, gt=0)
    provider: str = Field(min_length=1, max_length=80)
    chat_model: str = Field(
        min_length=1,
        max_length=160,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$",
    )
    vision_model: str = Field(
        min_length=1,
        max_length=160,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$",
    )
    filters: dict[str, QueryFilterScalar] = Field(default_factory=dict)
    generate: bool = True
    is_optimized: bool = False
    fast_mode: bool = False
    smart_lookup: bool = False
    structural_hops: int | None = Field(default=None, ge=0)
    preferred_tags: list[str] | None = None
    reranker: str = Field(default="none", max_length=32)
    rerank_top_n: int = Field(default=20, gt=0)
    mix_mode: bool = True
    mix_weight: float = Field(default=0.5, ge=0.0, le=1.0)

    @model_validator(mode="after")
    def require_authorized_embedding_provider(self) -> "QueryRequest":
        """Reject provider fallback into a different embedding vector space."""

        provider = self.provider.lower()
        authorized_provider = self.authorized_scope.embedding_provider
        if provider != authorized_provider:
            raise ValueError(
                "provider must match the authorized dataset embedding provider; "
                "automatic provider fallback is disabled"
            )
        self.provider = authorized_provider
        return self


class QueryHit(BaseModel):
    """Storage-neutral retrieval hit returned by the bridge."""

    model_config = ConfigDict(extra="allow", frozen=True)

    id: str | int | None = None
    score: FiniteFloat | None = None
    payload: dict[str, JsonValue] = Field(default_factory=dict)


class QueryResponse(BaseModel):
    """Stable outer query response; detailed telemetry remains extensible."""

    model_config = ConfigDict(extra="allow", frozen=True)

    ok: bool
    count: int = Field(ge=0)
    hits: list[QueryHit] = Field(default_factory=list)
    kg: list[dict[str, str]] = Field(default_factory=list)
    answer: str = ""
    retrieval: dict[str, JsonValue] = Field(default_factory=dict)


__all__ = ["QueryFilterScalar", "QueryHit", "QueryRequest", "QueryResponse"]
