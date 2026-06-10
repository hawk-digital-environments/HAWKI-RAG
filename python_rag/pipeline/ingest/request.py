"""Request-level helpers for ingestion orchestration."""
from __future__ import annotations

import os
from typing import Any, List


def infer_job_id(body: Any, docs: List[Any]) -> str | None:
    body_job_id = getattr(body, "job_id", None)
    if body_job_id:
        return str(body_job_id)
    for doc in docs:
        payload = getattr(doc, "payload", None) or {}
        if isinstance(payload, dict):
            job_id = payload.get("job_id") or payload.get("trace_id")
            if job_id:
                return str(job_id)
    return None


def apply_provider_overrides(provider: Any, body: Any) -> None:
    if provider is None:
        return
    embedding_model = getattr(body, "embedding_model", None)
    if embedding_model and hasattr(provider, "embed_model"):
        provider.embed_model = str(embedding_model).strip()
    graph_model = getattr(body, "graph_model", None)
    if graph_model and hasattr(provider, "rag_model"):
        graph_model_value = str(graph_model).strip()
        provider.rag_model = graph_model_value
        try:
            provider._explicit_graph_model = graph_model_value
        except Exception:
            pass


def float_env(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return float(raw)
    except ValueError:
        return default
