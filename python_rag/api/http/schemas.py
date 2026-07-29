"""FastAPI request models for the RAG API."""
from __future__ import annotations

from typing import Any

from pydantic import BaseModel, ConfigDict, Field, FiniteFloat, StrictBool, field_validator, model_validator

from api.settings import AppSettings


def _apply_if_absent(data: BaseModel, field: str, value: object, updates: dict[str, object]) -> None:
    if field not in data.model_fields_set:
        updates[field] = value


class IngestDoc(BaseModel):
    id: str | int
    text: str
    payload: dict[str, Any] = Field(default_factory=dict)


class IngestRequest(BaseModel):
    docs: list[IngestDoc]
    provider: str = "ollama"
    embedding_model: str | None = None
    collection: str | None = None
    dataset_id: str | None = Field(default=None, max_length=191)
    neo4j_namespace: str | None = Field(default=None, max_length=191)
    neo4j_database: str | None = None
    distance: str = "Cosine"
    chunk_chars: int = 1200
    chunk_overlap: int = 250
    batch_size: int = 64
    graph: bool = False
    graph_engine: str = "raganything"
    graph_model: str | None = None
    vision_model: str | None = None
    graph_only: bool = False
    idempotency_key: str | None = None
    dry_run: bool = False
    dry_include_graph: bool = False


class AuthorizedQueryScope(BaseModel):
    """Storage scope that Laravel derived after authorizing a dataset query."""

    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    dataset_id: str = Field(min_length=1, max_length=191)
    qdrant_collection: str = Field(min_length=1, max_length=191)
    neo4j_namespace: str | None = Field(default=None, max_length=191)
    embedding_provider: str = Field(
        min_length=1,
        max_length=80,
        pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]*$",
    )
    embedding_model: str = Field(min_length=1, max_length=160, pattern=r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$")
    graph_enabled: StrictBool = False

    @field_validator("embedding_provider")
    @classmethod
    def normalize_embedding_provider(cls, value: str) -> str:
        return value.lower()

    @model_validator(mode="after")
    def require_graph_namespace(self) -> "AuthorizedQueryScope":
        """Require the server-derived namespace before graph reads can run."""

        if self.graph_enabled and not self.neo4j_namespace:
            raise ValueError("neo4j_namespace is required when graph retrieval is enabled")
        return self


QueryFilterScalar = str | int | FiniteFloat | bool


def apply_ingest_request_settings(body: IngestRequest, settings: AppSettings) -> IngestRequest:
    updates: dict[str, object] = {}
    _apply_if_absent(body, "provider", settings.rag_default_provider, updates)
    _apply_if_absent(body, "distance", settings.qdrant_distance, updates)
    _apply_if_absent(body, "chunk_chars", settings.chunk_size, updates)
    _apply_if_absent(body, "chunk_overlap", settings.chunk_overlap_size, updates)
    _apply_if_absent(body, "batch_size", settings.ingest_batch_size, updates)
    _apply_if_absent(body, "graph_engine", settings.graph_engine, updates)
    return body.model_copy(update=updates)


class QueryRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    query: str
    authorized_scope: AuthorizedQueryScope
    top_k: int = 5
    provider: str = "ollama"
    chat_model: str | None = Field(default=None, max_length=160, pattern=r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$")
    vision_model: str | None = Field(default=None, max_length=160, pattern=r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$")
    filters: dict[str, QueryFilterScalar] = Field(default_factory=dict)
    generate: bool = True
    is_optimized: bool = False
    fast_mode: bool = False
    smart_lookup: bool = False
    structural_hops: int | None = None
    preferred_tags: list[str] | None = None
    # Reranker options: none | cosine | external | jina
    reranker: str = "none"
    rerank_top_n: int = 20
    # Mix mode: blend original vector score with reranker score
    mix_mode: bool = True
    mix_weight: float = 0.5  # 0..1, weight on original score

    @model_validator(mode="after")
    def require_authorized_embedding_provider(self) -> "QueryRequest":
        """Reject cross-provider queries instead of changing vector spaces."""

        provider = self.provider.strip().lower()
        authorized_provider = self.authorized_scope.embedding_provider.strip().lower()
        if provider != authorized_provider:
            raise ValueError(
                "provider must match the authorized dataset embedding provider; "
                "automatic provider fallback is disabled"
            )
        self.provider = authorized_provider
        return self


def apply_query_request_settings(body: QueryRequest, settings: AppSettings) -> QueryRequest:
    updates: dict[str, object] = {}
    _apply_if_absent(body, "reranker", settings.reranker_mode, updates)
    _apply_if_absent(body, "mix_mode", settings.reranker_mix_mode, updates)
    _apply_if_absent(body, "mix_weight", settings.reranker_mix_weight, updates)
    return body.model_copy(update=updates)


class GraphRequest(BaseModel):
    text: str
    engine: str = "raganything"


class DocumentUpsertRequest(BaseModel):
    text: str
    payload: dict[str, Any] = Field(default_factory=dict)
    provider: str | None = None
    collection: str | None = None
    neo4j_database: str | None = None
    distance: str | None = None
    chunk_chars: int | None = None
    chunk_overlap: int | None = None
    graph: bool = False
    graph_engine: str | None = None
    idempotency_key: str | None = None
