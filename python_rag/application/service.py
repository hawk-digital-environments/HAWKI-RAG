from __future__ import annotations

import logging
from pathlib import Path
from typing import Any, Optional

from application.service_dependencies import GraphExtractionService, RAGServiceDependencies, configure_service_logging

logger = logging.getLogger(__name__)


class RAGService:
    """Facade over provider construction, retrieval reranking, and graph extraction."""

    def __init__(self, dependencies: RAGServiceDependencies | None = None) -> None:
        self._dependencies = dependencies or RAGServiceDependencies()
        self.settings = self._dependencies.settings_loader()
        self.working_dir = Path(self.settings.rag_working_dir).expanduser()
        self.working_dir.mkdir(parents=True, exist_ok=True)
        self._graph_service: GraphExtractionService | None = None
        self.raganything: Any | None = None
        configure_service_logging(self.settings, logger)

    def _service_dependencies(self) -> RAGServiceDependencies:
        dependencies = getattr(self, "_dependencies", None)
        if dependencies is None:
            dependencies = RAGServiceDependencies()
            self._dependencies = dependencies
        return dependencies

    def _ensure_graph_service(self) -> GraphExtractionService:
        dependencies = self._service_dependencies()
        service = getattr(self, "_graph_service", None)
        if service is not None:
            return service

        settings = getattr(self, "settings", None)
        if settings is None:
            settings = dependencies.settings_loader()
            self.settings = settings

        if not hasattr(self, "working_dir") or self.working_dir is None:
            self.working_dir = Path(settings.rag_working_dir).expanduser()
        else:
            self.working_dir = Path(self.working_dir).expanduser()

        self.working_dir.mkdir(parents=True, exist_ok=True)
        service = dependencies.graph_service_factory(self.working_dir, logger)
        self._graph_service = service
        self.raganything = service.client
        return service

    def clear_graph_cache(self) -> dict[str, Any]:
        """Clear persisted RAG-Anything graph extraction state."""
        service = self._ensure_graph_service()
        result = service.clear_graph_cache()
        self.raganything = service.client
        return result

    def graph_runtime_summary(self) -> dict[str, Any]:
        """Return a lightweight runtime summary for the UI monitor."""
        return self._ensure_graph_service().graph_runtime_summary()

    def get_provider(self, name: str):
        return self._service_dependencies().provider_factory(name)

    def extract_triplets(
        self,
        text: str,
        engine: str | None,
        *,
        provider: Any | None = None,
        chunks: list[str] | None = None,
        doc_id: str | None = None,
        file_path: str | None = None,
        neo4j_database: str | None = None,
    ) -> list[tuple[str, str, str]]:
        dependencies = self._service_dependencies()
        if not hasattr(self, "settings") or self.settings is None:
            self.settings = dependencies.settings_loader()

        provider = provider or self.get_provider(self.settings.graph_provider)
        service = self._ensure_graph_service()
        trips = dependencies.triplet_extractor(
            service,
            text,
            engine,
            provider=provider,
            chunks=chunks,
            doc_id=doc_id,
            file_path=file_path,
            neo4j_database=neo4j_database,
            graph_perf_log=self.settings.graph_perf_log,
        )
        self.raganything = service.client
        return trips

    def _triplets_from_raganything_llm_cache(self) -> list[tuple[str, str, str]]:
        return self._ensure_graph_service().triplets_from_llm_cache()

    def rerank_hits(
        self,
        *,
        hits: list[dict[str, Any]],
        user_query: str,
        provider: Any,
        query_vector: Optional[list[float]],
        mode: str | None,
        top_n: int,
        mix_mode: bool,
        mix_weight: float,
    ) -> list[dict[str, Any]]:
        return self._service_dependencies().reranker(
            hits=hits,
            user_query=user_query,
            provider=provider,
            query_vector=query_vector,
            mode=mode,
            top_n=top_n,
            mix_mode=mix_mode,
            mix_weight=mix_weight,
        )
