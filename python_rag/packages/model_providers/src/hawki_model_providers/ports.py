"""Persistence/provider contracts (ports) shared by domain logic and adapters."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Protocol


class EmbeddingPort(Protocol):
    """Embed plain text into a numeric vector."""

    def embed(self, text: str) -> list[float]:
        """Return a fixed-dimension embedding vector."""


class ModelProvider(Protocol):
    """Common model surface used by query and ingestion workflows."""

    embed_model: str
    rag_model: str
    vision_model: str

    def embed(self, text: str) -> list[float]:
        """Return a fixed-dimension embedding vector."""

    def chat(
        self,
        system: str,
        messages: list[Mapping[str, object]],
        *,
        temperature: float | None = None,
    ) -> str:
        """Return a chat completion for structured messages."""


class ProviderResolver(Protocol):
    """Resolve a configured model provider by its public name."""

    def get_provider(self, name: str) -> ModelProvider:
        """Return the configured provider or raise ``ValueError``."""
