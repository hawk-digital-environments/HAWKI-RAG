"""Build API configuration responses."""
from __future__ import annotations

from typing import Any

from app.settings import AppSettings


def build_config_response(*, get_provider: Any, qdrant_factory: Any, app_settings: AppSettings) -> dict[str, Any]:
    provider_name = app_settings.rag_default_provider
    try:
        provider = get_provider(provider_name)
        embed_model = getattr(provider, "embed_model", None)
    except Exception:
        embed_model = None

    qdrant = qdrant_factory()
    qdrant_collection = qdrant.collection
    vector_size = qdrant.get_vector_size()

    return {
        "provider": provider_name,
        "embedding_model": embed_model,
        "qdrant_collection": qdrant_collection,
        "qdrant_vector_size": vector_size,
        "reranker": {
            "mode": app_settings.reranker_mode,
            "mix_mode": app_settings.reranker_mix_mode,
            "mix_weight": app_settings.reranker_mix_weight,
            "jina_model": app_settings.reranker_jina_model,
            "external_url": app_settings.reranker_api_url,
        },
    }
