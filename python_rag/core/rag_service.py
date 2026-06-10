import logging
from pathlib import Path
from typing import Any, Dict, List, Optional

from core.graph.orchestration import RAGGraphOrchestrator
from core.graph.reranker import rerank_hits as rerank_hits_impl
from core.providers.factory import get_provider as create_provider
from core.settings import RAGServiceSettings, load_rag_settings

logger = logging.getLogger(__name__)


class RAGService:
    """Facade over provider construction, retrieval reranking, and graph extraction."""

    def __init__(self) -> None:
        self.settings = load_rag_settings()
        self.working_dir = Path(self.settings.rag_working_dir).expanduser()
        self.working_dir.mkdir(parents=True, exist_ok=True)
        self._graph_orchestrator = RAGGraphOrchestrator(
            settings=self.settings,
            working_dir=self.working_dir,
            logger_obj=logger,
        )
        self.raganything = self._graph_orchestrator.raganything_client
        _configure_service_logging(self.settings)

    def _ensure_graph_orchestrator(self) -> RAGGraphOrchestrator:
        orchestrator = getattr(self, "_graph_orchestrator", None)
        if orchestrator is not None:
            return orchestrator

        settings = getattr(self, "settings", None)
        if settings is None:
            settings = load_rag_settings()
            self.settings = settings

        if not hasattr(self, "working_dir") or self.working_dir is None:
            self.working_dir = Path(settings.rag_working_dir).expanduser()
        else:
            self.working_dir = Path(self.working_dir).expanduser()

        self.working_dir.mkdir(parents=True, exist_ok=True)
        orchestrator = RAGGraphOrchestrator(settings=settings, working_dir=self.working_dir, logger_obj=logger)
        self._graph_orchestrator = orchestrator
        self.raganything = orchestrator.raganything_client
        return orchestrator

    def clear_graph_cache(self) -> Dict[str, Any]:
        """Clear persisted RAG-Anything graph extraction state."""
        orchestrator = self._ensure_graph_orchestrator()
        result = orchestrator.clear_graph_cache()
        self.raganything = orchestrator.raganything_client
        return result

    def graph_runtime_summary(self) -> Dict[str, Any]:
        """Return a lightweight runtime summary for the UI monitor."""
        return self._ensure_graph_orchestrator().graph_runtime_summary()

    def get_provider(self, name: str):
        return create_provider(name)

    def extract_triplets(
        self,
        text: str,
        engine: str | None,
        *,
        provider: Any | None = None,
        chunks: List[str] | None = None,
        doc_id: str | None = None,
        file_path: str | None = None,
        neo4j_database: str | None = None,
    ) -> List[tuple[str, str, str]]:
        if not hasattr(self, "settings") or self.settings is None:
            self.settings = load_rag_settings()

        provider = provider or self.get_provider(self.settings.graph_provider)
        orchestrator = self._ensure_graph_orchestrator()
        trips = orchestrator.extract_triplets(
            text,
            engine,
            provider=provider,
            chunks=chunks,
            doc_id=doc_id,
            file_path=file_path,
            neo4j_database=neo4j_database,
        )
        self.raganything = orchestrator.raganything_client
        return trips

    def _triplets_from_raganything_llm_cache(self) -> List[tuple[str, str, str]]:
        return self._ensure_graph_orchestrator().triplets_from_llm_cache()

    def rerank_hits(
        self,
        *,
        hits: List[Dict[str, Any]],
        user_query: str,
        provider: Any,
        query_vector: Optional[List[float]],
        mode: str | None,
        top_n: int,
        mix_mode: bool,
        mix_weight: float,
    ) -> List[Dict[str, Any]]:
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
