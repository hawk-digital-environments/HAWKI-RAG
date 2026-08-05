"""Settings for graph visualization export output."""

from __future__ import annotations

import os
from dataclasses import dataclass


def _bool_env(name: str, default: bool = True) -> bool:
    raw = str(os.environ.get(name, "")).strip().lower()
    if not raw:
        return default
    return raw in ("1", "true", "yes", "on")


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except Exception:
        return default


@dataclass(frozen=True)
class GraphVisualizationSettings:
    enabled: bool
    uri: str
    user: str
    password: str
    database: str | None
    limit: int


def load_graph_visualization_settings(
    database: str | None = None,
) -> GraphVisualizationSettings:
    uri = os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
    user = os.environ.get("NEO4J_USER", os.environ.get("NEO4J_USERNAME", "neo4j"))
    password = os.environ.get("NEO4J_PASSWORD", "password")
    configured_database = (
        database or os.environ.get("NEO4J_DATABASE") or ""
    ).strip() or None
    return GraphVisualizationSettings(
        enabled=_bool_env("NEO4J_GRAPH_VISUALIZATION", True),
        uri=(uri or "").strip() or "bolt://neo4j:7687",
        user=(user or "neo4j").strip() or "neo4j",
        password=(password or "").strip() or "password",
        database=configured_database,
        limit=_int_env("NEO4J_GRAPH_VISUALIZATION_LIMIT", 0),
    )
