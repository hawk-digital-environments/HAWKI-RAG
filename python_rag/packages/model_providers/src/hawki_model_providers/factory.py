from __future__ import annotations

from .litellm import LiteLLMProvider
from .ollama import OllamaProvider


def get_provider(name: str):
    key = (name or "").strip().lower()
    if key == "ollama":
        return OllamaProvider()
    if key == "litellm":
        return LiteLLMProvider()
    raise ValueError(f"Unknown provider: {name}")
