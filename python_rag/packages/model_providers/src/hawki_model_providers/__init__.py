"""Model provider configuration and the LiteLLM SDK client."""

from __future__ import annotations

from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from hawki_model_providers.factory import create_model_provider
    from hawki_model_providers.litellm_sdk import LiteLLMSDKProvider

__all__ = [
    "LiteLLMSDKProvider",
    "create_model_provider",
]


def __getattr__(name: str) -> object:
    """Load concrete adapters only when a compatibility export is requested."""

    if name == "create_model_provider":
        from hawki_model_providers import factory

        return factory.create_model_provider
    if name == "LiteLLMSDKProvider":
        from hawki_model_providers.litellm_sdk import LiteLLMSDKProvider

        return LiteLLMSDKProvider
    raise AttributeError(f"module {__name__!r} has no attribute {name!r}")
