"""Dependency wiring for the application RAG service facade."""

from __future__ import annotations

import logging
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Protocol

from domain.settings import RAGServiceSettings, load_rag_settings
from infrastructure.providers.factory import get_provider as create_provider
from infrastructure.raganything.extraction import extract_triplets_with_graph_service
from infrastructure.raganything.raganything_client import RagAnythingGraphService
from infrastructure.rerank import rerank_hits as rerank_hits_impl

Triplet = tuple[str, str, str]
SettingsLoader = Callable[[], RAGServiceSettings]
ProviderFactory = Callable[[str], Any]
TripletExtractor = Callable[..., list[Triplet]]
Reranker = Callable[..., list[dict[str, Any]]]


class GraphExtractionService(Protocol):
    """Small graph service surface needed by ``RAGService``."""

    client: object | None

    def clear_graph_cache(self) -> dict[str, Any]:
        """Clear persisted graph extraction state."""
        ...

    def graph_runtime_summary(self) -> dict[str, Any]:
        """Return runtime summary details for graph extraction."""
        ...

    def triplets_from_llm_cache(self) -> list[Triplet]:
        """Return fallback triplets from the persisted LLM cache."""
        ...


GraphServiceFactory = Callable[[Path, logging.Logger], GraphExtractionService]


def create_raganything_graph_service(working_dir: Path, logger_obj: logging.Logger) -> RagAnythingGraphService:
    """Create the concrete RAG-Anything graph service used in production."""
    return RagAnythingGraphService(working_dir, logger_obj=logger_obj)


def configure_service_logging(settings: RAGServiceSettings, logger_obj: logging.Logger) -> None:
    """Apply service-level logging defaults without hiding debug mode."""
    if not (settings.graph_debug or settings.graph_debug_llm):
        logger_obj.setLevel(logging.INFO)


@dataclass(frozen=True)
class RAGServiceDependencies:
    """Concrete dependency factories used by ``RAGService``.

    Keeping this wiring in one value object lets tests and API factories inject fakes
    without patching module globals, while the default constructor preserves runtime
    behavior.
    """

    settings_loader: SettingsLoader = load_rag_settings
    provider_factory: ProviderFactory = create_provider
    graph_service_factory: GraphServiceFactory = create_raganything_graph_service
    triplet_extractor: TripletExtractor = extract_triplets_with_graph_service
    reranker: Reranker = rerank_hits_impl


__all__ = [
    "RAGServiceDependencies",
    "GraphExtractionService",
    "Triplet",
    "configure_service_logging",
    "create_raganything_graph_service",
]
