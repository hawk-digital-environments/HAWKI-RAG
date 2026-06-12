"""Concrete dependency factories for ingestion orchestration."""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

from application.workflows.ingest.settings import GraphIngestSettings, load_graph_ingest_settings
from infrastructure.graph.neo4j_graph import Neo4jGraph
from infrastructure.vectorstore.qdrant_http import QdrantHTTP

GraphSettingsLoader = Callable[[], GraphIngestSettings]
QdrantFactory = Callable[[], Any]
GraphFactory = Callable[[str | None], Any]


def create_qdrant_http() -> QdrantHTTP:
    """Create the production Qdrant adapter."""
    return QdrantHTTP()


def create_neo4j_graph(database: str | None = None) -> Neo4jGraph:
    """Create the production Neo4j graph adapter."""
    return Neo4jGraph(database=database)


@dataclass(frozen=True)
class IngestWorkflowDependencies:
    """Factories and settings loaders used by ingestion orchestration."""

    graph_settings_loader: GraphSettingsLoader = load_graph_ingest_settings
    qdrant_factory: QdrantFactory = create_qdrant_http
    graph_factory: GraphFactory = create_neo4j_graph


__all__ = [
    "IngestWorkflowDependencies",
    "create_neo4j_graph",
    "create_qdrant_http",
]
