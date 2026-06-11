import logging
from pathlib import Path
from typing import Any, Dict, List, Optional

from core.graph.extraction import extract_triplets_with_graph_service
from core.graph.raganything_client import RagAnythingGraphService
from infrastructure.rerank import rerank_hits as rerank_hits_impl
from core.providers.factory import get_provider as create_provider
from domain.settings import RAGServiceSettings, load_rag_settings

logger = logging.getLogger(__name__)


class RAGService:
    """Facade over provider construction, retrieval reranking, and graph extraction."""

    def __init__(self) -> None:
        self.settings = load_rag_settings()
        self.working_dir = Path(self.settings.rag_working_dir).expanduser()
        self.working_dir.mkdir(parents=True, exist_ok=True)
        self._graph_service: RagAnythingGraphService | None = None
        self.raganything: Any | None = None
        _configure_service_logging(self.settings)

    def _ensure_graph_service(self) -> RagAnythingGraphService:
        service = getattr(self, "_graph_service", None)
        if service is not None:
            return service

        settings = getattr(self, "settings", None)
        if settings is None:
            settings = load_rag_settings()
            self.settings = settings

        if not hasattr(self, "working_dir") or self.working_dir is None:
            self.working_dir = Path(settings.rag_working_dir).expanduser()
        else:
            self.working_dir = Path(self.working_dir).expanduser()

        self.working_dir.mkdir(parents=True, exist_ok=True)
        service = RagAnythingGraphService(self.working_dir, logger_obj=logger)
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
        return create_provider(name)

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
        if not hasattr(self, "settings") or self.settings is None:
            self.settings = load_rag_settings()

        provider = provider or self.get_provider(self.settings.graph_provider)
        service = self._ensure_graph_service()
        trips = extract_triplets_with_graph_service(
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
        return rerank_hits_impl(
            hits=hits,
            user_query=user_query,
            provider=provider,
            query_vector=query_vector,
            mode=mode,
            top_n=top_n,
            mix_mode=mix_mode,
            mix_weight=mix_weight,
        )


def _configure_service_logging(settings: RAGServiceSettings) -> None:
    if not (settings.graph_debug or settings.graph_debug_llm):
        logger.setLevel(logging.INFO)
