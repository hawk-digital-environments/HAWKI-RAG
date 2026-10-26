"""Explicit model selection applied to a provider instance."""

from __future__ import annotations

from dataclasses import dataclass
import os


@dataclass(frozen=True, slots=True)
class ProviderModelSelection:
    """Request-authorized model aliases without request or authorization objects."""

    embedding_model: str | None = None
    chat_model: str | None = None
    vision_model: str | None = None


def resolve_provider_name_from_env(default: str = "ollama") -> str:
    """Return the globally configured provider name.

    ``RAG_DEFAULT_PROVIDER`` is the stack-wide provider switch (the same
    key Laravel reads in ``config/temporal.php``); accepted values today are
    ``ollama`` and ``litellm``. It is deliberately NOT inferred from other
    keys such as ``LITELLM_API_URL``: deployments keep those set even while
    running the direct Ollama provider.
    """

    value = os.environ.get("RAG_DEFAULT_PROVIDER", "").strip().lower()
    return value or default


def resolve_provider_selection_from_env() -> ProviderModelSelection:
    """Resolve the canonical model configuration.

    ``EMBED_MODEL``, ``CHAT_MODEL``, and ``VISION_MODEL`` hold
    provider-prefixed values (``ollama/bge-m3``, ``openai/gpt-4.1-mini``,
    ...) whose prefix routes the request, so one set of keys serves every
    backend. Providers normalize the values they receive: the LiteLLM SDK
    client routes on the prefix, the native clients strip it. Bare values
    (proxy aliases) pass through unchanged everywhere.
    """

    return ProviderModelSelection(
        embedding_model=_env("EMBED_MODEL"),
        chat_model=_env("CHAT_MODEL"),
        vision_model=_env("VISION_MODEL"),
    )


def _env(name: str) -> str | None:
    value = os.environ.get(name, "").strip()
    return value or None


_ROUTING_PREFIXES = frozenset(
    {
        "ollama",
        "ollama_chat",
        "ollama_embed",
        "openai",
        "anthropic",
        "gemini",
        "vertex_ai",
        "azure",
    }
)


def strip_routing_prefix(model: str | None) -> str | None:
    """Drop a LiteLLM routing prefix (``ollama/bge-m3`` -> ``bge-m3``).

    Canonical model values are provider-prefixed for SDK routing; the
    hand-rolled native clients and dataset-scoped bridge requests expect the
    bare model name or proxy alias, so callers translate at the boundary.
    Values without a known routing prefix pass through unchanged.
    """

    value = str(model or "").strip()
    if not value or "/" not in value:
        return value or None
    prefix, _, remainder = value.partition("/")
    if remainder and prefix in _ROUTING_PREFIXES:
        return remainder
    return value or None


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
