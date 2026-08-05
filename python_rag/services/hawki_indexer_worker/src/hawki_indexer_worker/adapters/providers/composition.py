"""Production provider composition for the indexer role."""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

from hawki_model_providers.factory import get_provider as _get_provider

from hawki_indexer_worker.adapters.providers.graph import GraphExtractionFacade


def get_provider(name: str) -> Any:
    return _get_provider(name)


def create_graph_extractor(
    working_dir: Path,
    *,
    graph_perf_log: bool = False,
    logger_obj: logging.Logger | None = None,
) -> GraphExtractionFacade:
    return GraphExtractionFacade(
        working_dir,
        graph_perf_log=graph_perf_log,
        logger_obj=logger_obj,
    )


__all__ = ["create_graph_extractor", "get_provider"]
