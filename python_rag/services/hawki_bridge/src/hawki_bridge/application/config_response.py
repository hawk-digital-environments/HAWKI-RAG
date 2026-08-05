"""Read-only service configuration response."""

from __future__ import annotations

from typing import Any

from hawki_bridge.settings import BridgeSettings


def build_config_response(
    *,
    get_provider: Any,
    qdrant_factory: Any,
    settings: BridgeSettings,
) -> dict[str, Any]:
    try:
        provider = get_provider(settings.default_provider)
        embedding_model = getattr(provider, "embed_model", None)
    except Exception:
        embedding_model = None
    qdrant = qdrant_factory()
    return {
        "provider": settings.default_provider,
        "embedding_model": embedding_model,
        "qdrant_collection": qdrant.collection,
        "qdrant_vector_size": qdrant.get_vector_size(),
        "reranker": {
            "mode": settings.reranker_mode,
            "mix_mode": settings.reranker_mix_mode,
            "mix_weight": settings.reranker_mix_weight,
            "jina_model": settings.reranker_jina_model,
            "external_url": settings.reranker_api_url,
        },
    }


__all__ = ["build_config_response"]
