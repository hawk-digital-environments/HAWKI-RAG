from __future__ import annotations

import warnings

from .litellm import LiteLLMProvider
from .ollama import OllamaProvider


def create_model_provider(name: str) -> LiteLLMProvider | OllamaProvider:
    """Create the concrete model client selected by its configured name."""

    key = (name or "").strip().lower()
    if key == "ollama":
        return OllamaProvider()
    if key == "litellm":
        return LiteLLMProvider()
    raise ValueError(f"Unknown provider: {name}")


def get_provider(name: str) -> LiteLLMProvider | OllamaProvider:
    """Compatibility alias for :func:`create_model_provider`."""

    warnings.warn(
        "get_provider() is deprecated; use create_model_provider().",
        DeprecationWarning,
        stacklevel=2,
    )
    return create_model_provider(name)


__all__ = ["create_model_provider", "get_provider"]
