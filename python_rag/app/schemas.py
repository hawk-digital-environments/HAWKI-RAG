"""FastAPI request models for the RAG API."""
from __future__ import annotations

import os
from typing import Any, Dict, List

from pydantic import BaseModel, Field


def int_env(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


class IngestDoc(BaseModel):
    id: str | int
    text: str
    payload: Dict[str, Any] = Field(default_factory=dict)


class IngestRequest(BaseModel):
    docs: List[IngestDoc]
    provider: str = Field(default=os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
    embedding_model: str | None = None
    collection: str | None = None
    neo4j_database: str | None = None
    distance: str = Field(default=os.environ.get("QDRANT_DISTANCE", "Cosine"))
    chunk_chars: int = Field(default=int_env("CHUNK_SIZE", 1200))
    chunk_overlap: int = Field(default=int_env("CHUNK_OVERLAP_SIZE", 250))
    batch_size: int = Field(default=int_env("INGEST_BATCH_SIZE", 64))
    graph: bool = False
    graph_engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "raganything"))
    graph_model: str | None = None
    graph_only: bool = False
    dry_run: bool = False
    dry_include_graph: bool = False


class QueryRequest(BaseModel):
    query: str
    top_k: int = 5
    provider: str = Field(default=os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
    filters: Dict[str, Any] = Field(default_factory=dict)
    generate: bool = True
    is_optimized: bool = False
    fast_mode: bool = False
    smart_lookup: bool = False
    structural_hops: int | None = None
    preferred_tags: List[str] | None = None
    # Reranker options: none | cosine | external | jina
    reranker: str = Field(default=os.environ.get("RERANKER_MODE", "none"))
    rerank_top_n: int = 20
    # Mix mode: blend original vector score with reranker score
    mix_mode: bool = Field(default=bool(os.environ.get("RERANKER_MIX_MODE", "true").lower() in ("1", "true", "yes")))
    mix_weight: float = Field(default=float(os.environ.get("RERANKER_MIX_WEIGHT", 0.5)))  # 0..1, weight on original score


class GraphRequest(BaseModel):
    text: str
    engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "raganything"))


class DocumentUpsertRequest(BaseModel):
    text: str
    payload: Dict[str, Any] = Field(default_factory=dict)
    provider: str | None = None
    collection: str | None = None
    distance: str | None = None
    chunk_chars: int | None = None
    chunk_overlap: int | None = None
    graph: bool = False
    graph_engine: str | None = None
