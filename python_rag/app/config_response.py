"""Build API configuration responses."""
from __future__ import annotations

import os
from typing import Any


def build_config_response(*, get_provider: Any, qdrant_factory: Any) -> dict[str, Any]:
    provider_name = os.environ.get("RAG_DEFAULT_PROVIDER", "ollama").strip()
    try:
        provider = get_provider(provider_name)
        embed_model = getattr(provider, "embed_model", None)
    except Exception:
        embed_model = None

    reranker_mode = os.environ.get("RERANKER_MODE", "none")
    mix_mode = str(os.environ.get("RERANKER_MIX_MODE", "true")).lower() in ("1", "true", "yes")
    try:
        mix_weight = float(os.environ.get("RERANKER_MIX_WEIGHT", 0.5))
    except Exception:
        mix_weight = 0.5
    jina_model = os.environ.get("JINA_RERANKER_MODEL", "jina-reranker-v2-base-multilingual")
    external_url = os.environ.get("RERANKER_API_URL", "")

    qdrant = qdrant_factory()
    qdrant_collection = qdrant.collection
    vector_size = qdrant.get_vector_size()

    return {
        "provider": provider_name,
        "embedding_model": embed_model,
        "qdrant_collection": qdrant_collection,
        "qdrant_vector_size": vector_size,
        "reranker": {
            "mode": reranker_mode,
            "mix_mode": mix_mode,
            "mix_weight": mix_weight,
            "jina_model": jina_model,
            "external_url": external_url,
        },
    }
