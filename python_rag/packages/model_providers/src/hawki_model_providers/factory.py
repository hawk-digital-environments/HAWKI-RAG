"""Factory for the concrete model client selected by configuration."""

from __future__ import annotations

from .litellm_sdk import LiteLLMSDKProvider

_SUPPORTED_PROVIDERS = frozenset({"ollama", "litellm"})


def create_model_provider(name: str) -> LiteLLMSDKProvider:
    """Create the model client for a configured provider name.

    Every provider name (``ollama``, ``litellm``) is served by the
    LiteLLM SDK client, which routes on the model string's prefix and
    resolves its endpoint from ``OLLAMA_API_URL`` / ``LITELLM_API_URL``.
    """

    key = (name or "").strip().lower()
    if key not in _SUPPORTED_PROVIDERS:
        raise ValueError(
            f"Unknown provider: {name} (expected one of {sorted(_SUPPORTED_PROVIDERS)})"
        )
    return LiteLLMSDKProvider(key)


__all__ = ["create_model_provider"]
