"""Request-level helpers for ingestion orchestration."""

from __future__ import annotations

import hashlib
import os
from dataclasses import dataclass, field
from typing import Any

from hawki_indexer_worker.domain.errors import IndexingValidationError
from hawki_indexer_worker.domain.models import IngestDocument
from hawki_model_providers.configuration import (
    ProviderModelSelection,
    configure_provider_models,
)


@dataclass(slots=True)
class IndexRequest:
    """Transport-neutral equivalent of the former bridge ingest body."""

    docs: list[IngestDocument]
    provider: str = "ollama"
    embedding_model: str | None = None
    collection: str | None = None
    dataset_id: str | None = None
    neo4j_namespace: str | None = None
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
    job_id: str | None = None
    dry_run: bool = False
    dry_include_graph: bool = False
    metadata: dict[str, Any] = field(default_factory=dict)

    def __post_init__(self) -> None:
        self.provider = str(self.provider or "").strip().lower()
        self.collection = _optional_text(self.collection)
        self.dataset_id = _optional_text(self.dataset_id)
        self.neo4j_namespace = _optional_text(self.neo4j_namespace)
        if not self.provider:
            raise IndexingValidationError("provider is required")
        if self.chunk_chars < 1:
            raise IndexingValidationError("chunk_chars must be positive")
        if self.chunk_overlap < 0 or self.chunk_overlap >= self.chunk_chars:
            raise IndexingValidationError(
                "chunk_overlap must be non-negative and smaller than chunk_chars"
            )
        if self.batch_size < 1:
            raise IndexingValidationError("batch_size must be positive")
        if self.graph and (not self.dataset_id or not self.neo4j_namespace):
            raise IndexingValidationError(
                "dataset_id and neo4j_namespace are required for graph indexing"
            )

    @classmethod
    def from_options(
        cls,
        docs: list[IngestDocument],
        *,
        workflow_input: dict[str, Any],
        options: dict[str, Any],
        operation_id: str,
        requires_graph: bool = False,
    ) -> "IndexRequest":
        """Build a request while preserving legacy ingestion option defaults."""

        return cls(
            docs=docs,
            provider=str(options.get("provider") or "ollama"),
            embedding_model=_optional_text(options.get("embedding_model")),
            collection=_optional_text(options.get("collection")),
            dataset_id=_optional_text(workflow_input.get("dataset_id")),
            neo4j_namespace=_optional_text(options.get("neo4j_namespace")),
            neo4j_database=_optional_text(options.get("neo4j_database")),
            distance=str(options.get("distance") or "Cosine"),
            chunk_chars=int(options.get("chunk_chars") or 1200),
            chunk_overlap=int(options.get("chunk_overlap") or 250),
            batch_size=int(options.get("batch_size") or 64),
            graph=bool(options.get("graph", False)) or requires_graph,
            graph_engine=str(options.get("graph_engine") or "raganything"),
            graph_model=_optional_text(options.get("graph_model")),
            vision_model=_optional_text(options.get("vision_model")),
            graph_only=bool(options.get("graph_only", False)),
            idempotency_key=operation_id,
            job_id=_optional_text(workflow_input.get("job_id")),
            dry_run=bool(options.get("dry_run", False)),
            dry_include_graph=bool(options.get("dry_include_graph", False)),
        )


def _optional_text(value: object) -> str | None:
    text = str(value or "").strip()
    return text or None


def _normalize_idempotency_key(
    value: object, *, fallback: str | None = None
) -> str | None:
    raw = str(value or "").strip() if value is not None else ""
    if not raw:
        raw = str(fallback or "").strip()
    if not raw:
        return None
    if len(raw) > 180:
        raw = hashlib.sha256(raw.encode("utf-8")).hexdigest()
    return raw


def infer_job_id(body: Any, docs: list[Any]) -> str | None:
    body_job_id = getattr(body, "job_id", None)
    if body_job_id:
        return str(body_job_id)
    for doc in docs:
        payload = getattr(doc, "payload", None) or {}
        if isinstance(payload, dict):
            job_id = payload.get("job_id") or payload.get("trace_id")
            if job_id:
                return str(job_id)
    doc_ids = [str(getattr(doc, "id", "")).strip() for doc in docs]
    doc_ids = [doc_id for doc_id in doc_ids if doc_id]
    if doc_ids:
        return "|".join(doc_ids)
    return None


def infer_operation_id(
    body: Any, docs: list[Any] | None = None, *, fallback: str | None = None
) -> str | None:
    explicit_key = _normalize_idempotency_key(getattr(body, "idempotency_key", None))
    if explicit_key is not None:
        return explicit_key

    if docs is not None:
        body_docs = docs if isinstance(docs, list) else list(docs)
    else:
        body_docs = []
    raw_job_id = infer_job_id(body, body_docs)
    if raw_job_id:
        return _normalize_idempotency_key(raw_job_id)
    return _normalize_idempotency_key(fallback)


def float_env(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return float(raw)
    except ValueError:
        return default


def apply_provider_overrides(provider: object, body: object) -> None:
    """Translate indexer request fields into explicit provider model selection."""

    configure_provider_models(
        provider,
        ProviderModelSelection(
            embedding_model=getattr(body, "embedding_model", None),
            chat_model=(
                getattr(body, "chat_model", None) or getattr(body, "graph_model", None)
            ),
            vision_model=getattr(body, "vision_model", None),
        ),
    )


__all__ = [
    "IndexRequest",
    "apply_provider_overrides",
    "float_env",
    "infer_job_id",
    "infer_operation_id",
]
