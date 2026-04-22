"""
Shared RabbitMQ job contract for crawl/ingest worker messages.
"""
from __future__ import annotations

import json
import os
from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field, ValidationError


def _int_env(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


class IngestDocSchema(BaseModel):
    id: str | int
    text: str
    payload: Dict[str, Any] = Field(default_factory=dict)


class JobSchema(BaseModel):
    # Worker / transport fields
    job_id: str
    retry_count: int = Field(default=0, ge=0)
    max_retries: Optional[int] = Field(default=None, ge=0)
    metadata: Dict[str, Any] = Field(default_factory=dict)

    # Ingest payload fields (aligned with python_rag/app/main.py IngestRequest)
    docs: List[IngestDocSchema]
    provider: str = Field(default=os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
    embedding_model: Optional[str] = None
    collection: Optional[str] = None
    neo4j_database: Optional[str] = None
    distance: str = Field(default=os.environ.get("QDRANT_DISTANCE", "Cosine"))
    chunk_chars: int = Field(default=_int_env("CHUNK_SIZE", 1200))
    chunk_overlap: int = Field(default=_int_env("CHUNK_OVERLAP_SIZE", 250))
    batch_size: int = Field(default=_int_env("INGEST_BATCH_SIZE", 64))
    graph: bool = False
    graph_engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "raganything"))
    graph_only: bool = False
    dry_run: bool = False
    dry_include_graph: bool = False


def validate_job_payload(payload: Dict[str, Any]) -> JobSchema:
    if hasattr(JobSchema, "model_validate"):
        return JobSchema.model_validate(payload)  # pydantic v2
    return JobSchema.parse_obj(payload)  # pragma: no cover - v1 fallback


def parse_job_message(message_body: bytes) -> JobSchema:
    decoded = message_body.decode("utf-8")
    payload = json.loads(decoded)
    return validate_job_payload(payload)


def job_to_dict(job: JobSchema) -> Dict[str, Any]:
    if hasattr(job, "model_dump"):
        return job.model_dump(mode="json")
    return job.dict()  # pragma: no cover - v1 fallback


def clone_job_with_retry(job: JobSchema, retry_count: int, max_retries: int) -> Dict[str, Any]:
    payload = job_to_dict(job)
    payload["retry_count"] = int(retry_count)
    payload["max_retries"] = int(max_retries)
    return payload


__all__ = [
    "IngestDocSchema",
    "JobSchema",
    "ValidationError",
    "clone_job_with_retry",
    "job_to_dict",
    "parse_job_message",
    "validate_job_payload",
]

