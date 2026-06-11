from __future__ import annotations

from .ollama_provider import OllamaProvider


def get_provider(name: str):
    key = (name or "").strip().lower()
    if key == "ollama":
        return OllamaProvider()
    raise ValueError(f"Unknown provider: {name}")
