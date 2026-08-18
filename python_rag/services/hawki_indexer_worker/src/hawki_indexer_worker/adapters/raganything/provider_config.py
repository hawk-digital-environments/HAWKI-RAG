"""Graph-scoped provider configuration helpers."""

from __future__ import annotations

from typing import Any


def graph_model_override(provider: object) -> str | None:
    """Return the request-provided graph model, if any."""

    explicit = str(getattr(provider, "_explicit_graph_model", "") or "").strip()
    return explicit or None


def graph_vision_model_override(provider: object) -> str | None:
    """Return the request-provided vision model, if any."""

    explicit = str(getattr(provider, "_explicit_vision_model", "") or "").strip()
    return explicit or None


def provider_fingerprint(provider: object) -> str:
    parts = [
        provider.__class__.__name__,
        str(getattr(provider, "base", "")),
        str(getattr(provider, "rag_model", "")),
        str(getattr(provider, "vision_model", "")),
        str(getattr(provider, "embed_model", "")),
        str(getattr(provider, "key", ""))[
            :8
        ],  # enough to detect config changes, avoids logging secrets
    ]
    return "|".join(parts)


def clone_provider_for_graph(provider: Any) -> Any:
    """Clone a provider and apply graph-specific model overrides.

    The clone protects request/query provider instances from graph extraction
    mutating their configured generation model.
    """
    try:
        clone = provider.__class__()  # re-read env-backed config
    except Exception:
        clone = provider
    for attr in (
        "base",
        "key",
        "rag_model",
        "vision_model",
        "embed_model",
        "_explicit_graph_model",
        "_explicit_vision_model",
        "_last_embed_dim",
    ):
        if hasattr(provider, attr):
            try:
                setattr(clone, attr, getattr(provider, attr))
            except Exception:
                pass
    graph_model = graph_model_override(clone)
    if graph_model and hasattr(clone, "rag_model"):
        setattr(clone, "rag_model", graph_model)
    vision_model = graph_vision_model_override(clone)
    if vision_model and hasattr(clone, "vision_model"):
        setattr(clone, "vision_model", vision_model)
    return clone
