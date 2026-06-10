"""Graph service orchestration boundary for `RAGService`.

The coordinator owns graph service lifecycle and the graph-specific API used by the
service facade.
"""
from __future__ import annotations

import logging
from pathlib import Path
from typing import Any, Dict, List

from core.graph import RagAnythingGraphService
from core.graph.extraction import extract_triplets_with_graph_service
from core.settings import RAGServiceSettings

logger = logging.getLogger(__name__)


class RAGGraphOrchestrator:
    """Coordinate graph client lifecycle and public graph helper operations."""

    def __init__(
        self,
        *,
        settings: RAGServiceSettings,
        working_dir: Path,
        logger_obj: logging.Logger | None = None,
    ) -> None:
        self.settings = settings
        self.working_dir = Path(working_dir).expanduser()
        self.working_dir.mkdir(parents=True, exist_ok=True)
        self.logger = logger_obj or logger
        self._graph_service: RagAnythingGraphService | None = None
        self.raganything_client: Any | None = None
        self._ensure_graph_service()

    def _ensure_graph_service(self) -> RagAnythingGraphService:
        if self._graph_service is not None:
            return self._graph_service

        self._graph_service = RagAnythingGraphService(self.working_dir, logger_obj=self.logger)
        self.raganything_client = self._graph_service.client
        return self._graph_service

    def _sync_client(self) -> None:
        service = self._ensure_graph_service()
        self.raganything_client = service.client

    def clear_graph_cache(self) -> Dict[str, Any]:
        service = self._ensure_graph_service()
        result = service.clear_graph_cache()
        self._sync_client()
        return result

    def graph_runtime_summary(self) -> Dict[str, Any]:
        return self._ensure_graph_service().graph_runtime_summary()

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
        trips = extract_triplets_with_graph_service(
            self._ensure_graph_service(),
            text,
            engine,
            provider=provider,
            chunks=chunks,
            doc_id=doc_id,
            file_path=file_path,
            neo4j_database=neo4j_database,
            graph_perf_log=self.settings.graph_perf_log,
        )
        self._sync_client()
        return trips

    def triplets_from_llm_cache(self) -> List[tuple[str, str, str]]:
        return self._ensure_graph_service().triplets_from_llm_cache()
