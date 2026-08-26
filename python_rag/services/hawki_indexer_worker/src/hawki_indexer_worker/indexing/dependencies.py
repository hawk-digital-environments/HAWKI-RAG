"""Indexer application dependency contracts."""

from __future__ import annotations

from dataclasses import dataclass

from hawki_indexer_worker.domain.ports import (
    GraphWriterFactory,
    PageStateFactory,
    VectorWriterFactory,
)
from hawki_indexer_worker.indexing.graph_settings import (
    GraphIngestSettings,
    load_graph_ingest_settings,
)
from collections.abc import Callable

GraphSettingsLoader = Callable[[], GraphIngestSettings]


@dataclass(frozen=True, slots=True)
class IngestWorkflowDependencies:
    """Infrastructure supplied to vector and graph ingestion workflows."""

    vector_writer_factory: VectorWriterFactory
    graph_writer_factory: GraphWriterFactory
    page_state_factory: PageStateFactory
    graph_settings_loader: GraphSettingsLoader = load_graph_ingest_settings


__all__ = [
    "IngestWorkflowDependencies",
]
