"""Temporal activities that warm and validate RAG inference dependencies.

``warm_model`` runs one inference through the provider selected by
configuration (see ``hawki_model_providers``), so switching the backend —
Ollama today, a LiteLLM proxy or any other provider tomorrow — keeps the
warmup working without code changes. ``warm_retrieval`` sends one real
retrieval query to the bridge service API so the production pipeline
(embed -> search -> rerank) runs end to end and loads everything it needs.
Failures raise so Temporal retries per the workflow policy.
"""

from __future__ import annotations

import logging
import os
import time
from typing import Any

import requests
from hawki_model_providers.configuration import (
    ProviderModelSelection,
    configure_provider_models,
    resolve_provider_name_from_env,
    resolve_provider_selection_from_env,
    strip_routing_prefix,
)
from hawki_model_providers.factory import create_model_provider
from temporalio import activity

from hawki_rag_contracts.pipeline.temporal import (
    WARM_MODEL_ACTIVITY,
    WARM_RETRIEVAL_ACTIVITY,
)

logger = logging.getLogger(__name__)

# First inference after a cold model load can take minutes (weight download,
# GPU upload); later calls answer in well under a second.
HTTP_TIMEOUT_SECONDS = 300.0

_WARMUP_SYSTEM_PROMPT = "You are a warmup probe. Reply with OK."


def _environment_value(name: str, default: str) -> str:
    value = os.environ.get(name)
    if isinstance(value, str) and value.strip():
        return value.strip()
    return default


def _bridge_base_url() -> str:
    return _environment_value(
        "HAWKI_RAG_BRIDGE_URL", "http://hawki_rag_bridge"
    ).rstrip("/")


def _qdrant_base_url() -> str:
    return _environment_value("QDRANT_HTTP_URL", "http://qdrant:6333").rstrip("/")


def _elapsed_ms(started: float) -> int:
    return int((time.perf_counter() - started) * 1000)


def _selection_for(kind: str, model: str) -> ProviderModelSelection:
    if kind == "embed":
        return ProviderModelSelection(embedding_model=model)
    if kind == "vision":
        return ProviderModelSelection(vision_model=model)
    return ProviderModelSelection(chat_model=model)


@activity.defn(name=WARM_MODEL_ACTIVITY)
def warm_model(payload: dict[str, Any]) -> dict[str, Any]:
    """Run one inference through the configured provider to load the model."""

    provider_name = str(payload.get("provider") or resolve_provider_name_from_env())
    model = str(payload.get("model") or "").strip()
    kind = str(payload.get("kind") or "chat").strip().lower()
    query = str(payload.get("query") or "Reply with OK.").strip()
    if not model:
        raise RuntimeError("warm_model requires a model name")

    provider = create_model_provider(provider_name)
    configure_provider_models(provider, _selection_for(kind, model))

    started = time.perf_counter()
    if kind == "embed":
        vector = provider.embed(query)
        if not vector:
            raise RuntimeError("Provider returned no embedding")
        detail = f"embedding dim={len(vector)}"
    elif kind == "vision":
        reply = provider.vision_chat(_WARMUP_SYSTEM_PROMPT, query)
        if not str(reply or "").strip():
            raise RuntimeError("Provider returned an empty vision reply")
        detail = f"vision reply={str(reply)[:40]!r}"
    else:
        reply = provider.chat(
            _WARMUP_SYSTEM_PROMPT, [{"role": "user", "content": query}]
        )
        if not str(reply or "").strip():
            raise RuntimeError("Provider returned an empty chat reply")
        detail = f"chat reply={str(reply)[:40]!r}"

    elapsed = _elapsed_ms(started)
    logger.info(
        "model_warmup:provider provider=%s model=%s kind=%s elapsed_ms=%s",
        provider_name,
        model,
        kind,
        elapsed,
    )
    return {
        "activity": WARM_MODEL_ACTIVITY,
        "provider": provider_name,
        "model": model,
        "kind": kind,
        "ok": True,
        "elapsed_ms": elapsed,
        "detail": detail,
    }


def _resolve_warmup_collection() -> str | None:
    """Return the configured warmup collection, or the first existing one."""

    configured = _environment_value("MODEL_WARMUP_QDRANT_COLLECTION", "")
    if configured:
        return configured
    response = requests.get(
        f"{_qdrant_base_url()}/collections", timeout=HTTP_TIMEOUT_SECONDS
    )
    response.raise_for_status()
    result = response.json().get("result") or {}
    collections = result.get("collections")
    if isinstance(collections, list) and collections:
        return str(collections[0].get("name") or "").strip() or None
    return None


@activity.defn(name=WARM_RETRIEVAL_ACTIVITY)
def warm_retrieval(payload: dict[str, Any]) -> dict[str, Any]:
    """Run one real retrieval query through the bridge service API.

    The provider and model names come from the same configuration the
    production stack uses, so the warmup query walks the exact code path a
    user query walks, whatever backend is configured.
    """

    query = str(payload.get("query") or "Reply with OK.").strip()
    provider_name = resolve_provider_name_from_env()
    selection = resolve_provider_selection_from_env()
    # The bridge scope carries bare model names matching the dataset-pinned
    # request contract, so canonical prefixed values are translated here.
    embedding_model = strip_routing_prefix(selection.embedding_model)
    if not embedding_model:
        raise RuntimeError(
            "warm_retrieval requires a configured embedding model (EMBED_MODEL)"
        )
    chat_model = strip_routing_prefix(selection.chat_model) or "llama3.1:8b"
    vision_model = strip_routing_prefix(selection.vision_model) or "qwen2.5vl:7b"

    collection = _resolve_warmup_collection()
    if not collection:
        logger.warning(
            "model_warmup:retrieval skipped; no qdrant collection available"
        )
        return {
            "activity": WARM_RETRIEVAL_ACTIVITY,
            "ok": True,
            "skipped": True,
            "detail": "no qdrant collection available for the warmup query",
        }

    started = time.perf_counter()
    response = requests.post(
        f"{_bridge_base_url()}/query",
        json={
            "query": query,
            "authorized_scope": {
                "dataset_id": _environment_value(
                    "MODEL_WARMUP_DATASET_ID", "model-warmup"
                ),
                "qdrant_collection": collection,
                "embedding_provider": provider_name,
                "embedding_model": embedding_model,
            },
            "top_k": 1,
            "provider": provider_name,
            "chat_model": chat_model,
            "vision_model": vision_model,
            "generate": False,
            "fast_mode": True,
        },
        timeout=HTTP_TIMEOUT_SECONDS,
    )
    response.raise_for_status()
    body = response.json()
    if not body.get("ok"):
        raise RuntimeError("Bridge query returned ok=false")

    elapsed = _elapsed_ms(started)
    logger.info(
        "model_warmup:retrieval collection=%s count=%s elapsed_ms=%s",
        collection,
        body.get("count"),
        elapsed,
    )
    return {
        "activity": WARM_RETRIEVAL_ACTIVITY,
        "ok": True,
        "skipped": False,
        "elapsed_ms": elapsed,
        "hits": int(body.get("count") or 0),
        "collection": collection,
        "provider": provider_name,
        "detail": "retrieval warmup query completed through the bridge API",
    }


__all__ = [
    "HTTP_TIMEOUT_SECONDS",
    "warm_model",
    "warm_retrieval",
]
