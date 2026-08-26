"""Explicit model selection applied to a provider instance."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class ProviderModelSelection:
    """Request-authorized model aliases without request or authorization objects."""

    embedding_model: str | None = None
    chat_model: str | None = None
    vision_model: str | None = None


def configure_provider_models(
    provider: object,
    selection: ProviderModelSelection,
) -> None:
    """Apply explicit aliases to one provider without changing credentials."""

    embedding_model = _normalized(selection.embedding_model)
    if embedding_model and hasattr(provider, "embed_model"):
        if getattr(provider, "embed_model", None) != embedding_model and hasattr(
            provider, "_last_embed_dim"
        ):
            setattr(provider, "_last_embed_dim", None)
        setattr(provider, "embed_model", embedding_model)

    chat_model = _normalized(selection.chat_model)
    if chat_model and hasattr(provider, "rag_model"):
        setattr(provider, "rag_model", chat_model)
        if _supports_attribute(provider, "_explicit_graph_model"):
            setattr(provider, "_explicit_graph_model", chat_model)

    vision_model = _normalized(selection.vision_model)
    if vision_model and hasattr(provider, "vision_model"):
        setattr(provider, "vision_model", vision_model)
        if _supports_attribute(provider, "_explicit_vision_model"):
            setattr(provider, "_explicit_vision_model", vision_model)


def _normalized(value: str | None) -> str | None:
    normalized = str(value or "").strip()
    return normalized or None


def _supports_attribute(instance: object, name: str) -> bool:
    """Return whether an instance can store a model-selection marker."""

    if hasattr(instance, "__dict__"):
        return True
    return any(name in base.__dict__ for base in type(instance).__mro__)


__all__ = ["ProviderModelSelection", "configure_provider_models"]
