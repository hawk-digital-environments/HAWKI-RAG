"""FastAPI request models for the RAG API."""
from __future__ import annotations

from typing import Any

from pydantic import AliasChoices, BaseModel, Field

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
    neo4j_database: str | None = None
    distance: str = "Cosine"
    chunk_chars: int = 1200
    chunk_overlap: int = 250
    batch_size: int = 64
    graph: bool = False
    graph_engine: str = "raganything"
    graph_model: str | None = None
    graph_only: bool = False
    idempotency_key: str | None = None
    dry_run: bool = False
    dry_include_graph: bool = False


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
    query: str
    limit: int = Field(default=5, validation_alias=AliasChoices("limit", "top_k"))
    provider: str = "ollama"
    filters: dict[str, Any] = Field(default_factory=dict)
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

    @property
    def top_k(self) -> int:
        return int(self.limit)


def apply_query_request_settings(body: QueryRequest, settings: AppSettings) -> QueryRequest:
    updates: dict[str, object] = {}
    _apply_if_absent(body, "provider", settings.rag_default_provider, updates)
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
    distance: str | None = None
    chunk_chars: int | None = None
    chunk_overlap: int | None = None
    graph: bool = False
    graph_engine: str | None = None
    idempotency_key: str | None = None
