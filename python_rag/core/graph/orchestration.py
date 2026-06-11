"""Graph service orchestration boundary for `RAGService`.

The coordinator owns graph service lifecycle and the graph-specific API used by the
service facade.
"""
from __future__ import annotations

from dataclasses import dataclass
import logging
from pathlib import Path
from typing import Any, Dict, List

from core.graph.raganything_client import RagAnythingGraphService
from core.graph.extraction import extract_triplets_with_graph_service
from core.settings import RAGServiceSettings

logger = logging.getLogger(__name__)


@dataclass
class _RAGGraphOrchestratorState:
    """Mutable coordinator state intentionally owned by one orchestrator/service instance."""

    settings: RAGServiceSettings
    working_dir: Path
    logger_obj: logging.Logger | None
    graph_service: RagAnythingGraphService | None = None
    raganything_client: Any | None = None


def _build_orchestrator_state(
    *,
    settings: RAGServiceSettings,
    working_dir: Path,
    logger_obj: logging.Logger | None = None,
) -> _RAGGraphOrchestratorState:
    return _RAGGraphOrchestratorState(
        settings=settings,
        working_dir=Path(working_dir).expanduser(),
        logger_obj=logger_obj,
    )


def _resolve_graph_service(state: _RAGGraphOrchestratorState) -> RagAnythingGraphService:
    if state.graph_service is None:
        state.graph_service = RagAnythingGraphService(
            state.working_dir,
            logger_obj=state.logger_obj,
        )
    state.raganything_client = state.graph_service.client
    return state.graph_service


def clear_graph_cache(state: _RAGGraphOrchestratorState) -> Dict[str, Any]:
    return _resolve_graph_service(state).clear_graph_cache()


def graph_runtime_summary(state: _RAGGraphOrchestratorState) -> Dict[str, Any]:
    return _resolve_graph_service(state).graph_runtime_summary()


def extract_triplets(
    state: _RAGGraphOrchestratorState,
    text: str,
    engine: str | None,
    *,
    provider: Any,
    chunks: List[str] | None = None,
    doc_id: str | None = None,
    file_path: str | None = None,
    neo4j_database: str | None = None,
) -> List[tuple[str, str, str]]:
    return extract_triplets_with_graph_service(
        _resolve_graph_service(state),
        text,
        engine,
        provider=provider,
        chunks=chunks,
        doc_id=doc_id,
        file_path=file_path,
        neo4j_database=neo4j_database,
        graph_perf_log=state.settings.graph_perf_log,
    )


def triplets_from_llm_cache(state: _RAGGraphOrchestratorState) -> List[tuple[str, str, str]]:
    return _resolve_graph_service(state).triplets_from_llm_cache()


class RAGGraphOrchestrator:
    """Compatibility wrapper around function-based orchestration helpers."""

    def __init__(
        self,
        *,
        settings: RAGServiceSettings,
        working_dir: Path,
        logger_obj: logging.Logger | None = None,
    ) -> None:
        self._state = _build_orchestrator_state(
            settings=settings,
            working_dir=working_dir,
            logger_obj=logger_obj or logger,
        )
        self._state.working_dir.mkdir(parents=True, exist_ok=True)
        self.settings = self._state.settings
        self.working_dir = self._state.working_dir
        self.logger = self._state.logger_obj
        self.raganything_client = self._state.raganything_client
        self._ensure_graph_service()

    def _ensure_graph_service(self) -> RagAnythingGraphService:
        service = _resolve_graph_service(self._state)
        self.raganything_client = self._state.raganything_client
        return service

    def _sync_client(self) -> None:
        self._ensure_graph_service()

    def clear_graph_cache(self) -> Dict[str, Any]:
        result = clear_graph_cache(self._state)
        self._sync_client()
        return result

    def graph_runtime_summary(self) -> Dict[str, Any]:
        return graph_runtime_summary(self._state)

    def extract_triplets(
        self,
        text: str,
        engine: str | None,
        *,
        provider: Any,
        chunks: List[str] | None = None,
        doc_id: str | None = None,
        file_path: str | None = None,
        neo4j_database: str | None = None,
    ) -> List[tuple[str, str, str]]:
        trips = extract_triplets(
            self._state,
            text,
            engine,
            provider=provider,
            chunks=chunks,
            doc_id=doc_id,
            file_path=file_path,
            neo4j_database=neo4j_database,
        )
        self._sync_client()
        return trips

    def triplets_from_llm_cache(self) -> List[tuple[str, str, str]]:
        return triplets_from_llm_cache(self._state)
