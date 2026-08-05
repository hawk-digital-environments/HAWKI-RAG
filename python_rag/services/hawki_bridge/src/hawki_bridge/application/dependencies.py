"""Explicit bridge dependency composition."""

from dataclasses import dataclass
from typing import Callable

from hawki_model_providers.factory import get_provider
from hawki_rag_stores.qdrant.client import QdrantHTTP


@dataclass(frozen=True, slots=True)
class BridgeDependencies:
    provider_factory: Callable = get_provider
    qdrant_factory: Callable = QdrantHTTP


__all__ = ["BridgeDependencies"]
