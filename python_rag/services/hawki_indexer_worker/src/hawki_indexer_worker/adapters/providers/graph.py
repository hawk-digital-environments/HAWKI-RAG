"""Narrow graph-extraction facade used by indexing orchestration."""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

from hawki_indexer_worker.adapters.raganything.client import RagAnythingGraphService
from hawki_indexer_worker.adapters.raganything.extraction import (
    extract_triplets_with_graph_service,
)


class GraphExtractionFacade:
    """Expose only the graph behavior the indexer needs."""

    def __init__(
        self,
        working_dir: Path,
        *,
        graph_perf_log: bool = False,
        logger_obj: logging.Logger | None = None,
    ) -> None:
        self._graph_perf_log = graph_perf_log
        self._service = RagAnythingGraphService(
            working_dir,
            logger_obj=logger_obj,
        )

    def extract_triplets(
        self,
        text: str,
        engine: str | None,
        *,
        provider: Any | None = None,
        chunks: list[str] | None = None,
        doc_id: str | None = None,
        file_path: str | None = None,
        image_paths: list[str] | None = None,
        neo4j_database: str | None = None,
        request_id: str | None = None,
    ) -> list[tuple[str, str, str]]:
        return extract_triplets_with_graph_service(
            self._service,
            text,
            engine,
            provider=provider,
            chunks=chunks,
            doc_id=doc_id,
            file_path=file_path,
            image_paths=image_paths,
            neo4j_database=neo4j_database,
            request_id=request_id,
            graph_perf_log=self._graph_perf_log,
        )


__all__ = ["GraphExtractionFacade"]
