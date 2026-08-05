"""Concrete, injectable dependency factories for indexing orchestration."""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

from hawki_indexer_worker.adapters.neo4j_writer import create_neo4j_writer
from hawki_indexer_worker.adapters.qdrant_writer import create_qdrant_writer
from hawki_indexer_worker.indexing.graph_settings import (
    GraphIngestSettings,
    load_graph_ingest_settings,
)
from hawki_indexer_worker.indexing.page_state import QdrantPageState

GraphSettingsLoader = Callable[[], GraphIngestSettings]
QdrantFactory = Callable[[], Any]
GraphFactory = Callable[..., Any]
PageStateFactory = Callable[[Any], Any | None]


def create_page_state(qdrant: Any) -> QdrantPageState:
    return QdrantPageState(qdrant)


@dataclass(frozen=True, slots=True)
class IngestWorkflowDependencies:
    graph_settings_loader: GraphSettingsLoader = load_graph_ingest_settings
    qdrant_factory: QdrantFactory = create_qdrant_writer
    graph_factory: GraphFactory = create_neo4j_writer
    page_state_factory: PageStateFactory = create_page_state


__all__ = [
    "IngestWorkflowDependencies",
    "create_neo4j_writer",
    "create_page_state",
    "create_qdrant_writer",
]
