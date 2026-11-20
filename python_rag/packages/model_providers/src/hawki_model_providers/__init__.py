"""Concrete model clients with lazy compatibility exports."""

from __future__ import annotations

from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from hawki_model_providers.factory import create_model_provider, get_provider
    from hawki_model_providers.litellm import LiteLLMProvider
    from hawki_model_providers.ollama import OllamaProvider

__all__ = [
    "LiteLLMProvider",
    "OllamaProvider",
    "create_model_provider",
    "get_provider",
]


def __getattr__(name: str) -> object:
    """Load concrete adapters only when a compatibility export is requested."""

    if name in {"create_model_provider", "get_provider"}:
        from hawki_model_providers import factory

        value = getattr(factory, name)
    elif name == "LiteLLMProvider":
        from hawki_model_providers.litellm import LiteLLMProvider

        value = LiteLLMProvider
    elif name == "OllamaProvider":
        from hawki_model_providers.ollama import OllamaProvider

        value = OllamaProvider
    else:
        raise AttributeError(f"module {__name__!r} has no attribute {name!r}")

    globals()[name] = value
    return value
