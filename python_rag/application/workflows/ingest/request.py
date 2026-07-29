"""Request-level helpers for ingestion orchestration."""
from __future__ import annotations

import hashlib
import os
from typing import Any

from application.workflows.provider_overrides import apply_provider_overrides


def _normalize_idempotency_key(value: object, *, fallback: str | None = None) -> str | None:
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


def infer_operation_id(body: Any, docs: list[Any] | None = None, *, fallback: str | None = None) -> str | None:
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
