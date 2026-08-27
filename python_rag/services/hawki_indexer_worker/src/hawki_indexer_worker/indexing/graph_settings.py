"""Typed settings for ingestion-time graph extraction behavior."""

from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Mapping


def _env_bool(env: Mapping[str, str], name: str, default: bool = False) -> bool:
    value = str(env.get(name, "")).strip().lower()
    if not value:
        return default
    return value in {"1", "true", "yes", "on"}


def _env_float(env: Mapping[str, str], name: str, default: float) -> float:
    raw = env.get(name)
    if raw is None:
        return default
    value = str(raw).strip()
    if not value:
        return default
    try:
        return float(value)
    except ValueError:
        return default


def _env_int(env: Mapping[str, str], name: str, default: int) -> int:
    raw = env.get(name)
    if raw is None:
        return default
    value = str(raw).strip()
    if not value:
        return default
    try:
        return int(value)
    except ValueError:
        return default


@dataclass(frozen=True)
class GraphIngestSettings:
    """Runtime graph extraction defaults for ingestion."""

    graph_debug: bool
    graph_perf_log: bool
    graph_doc_timeout_s: float
    graph_doc_max_chars: int
    graph_doc_max_chunks: int


def load_graph_ingest_settings(
    env: Mapping[str, str] | None = None,
) -> GraphIngestSettings:
    """Load graph ingestion settings with explicit defaults."""

    env_map = os.environ if env is None else env
    return GraphIngestSettings(
        graph_debug=_env_bool(env_map, "GRAPH_DEBUG", False),
        graph_perf_log=_env_bool(env_map, "GRAPH_PERF_LOG", False),
        graph_doc_timeout_s=_env_float(env_map, "GRAPH_DOC_TIMEOUT", 0.0),
        graph_doc_max_chars=_env_int(env_map, "GRAPH_DOC_MAX_CHARS", 0),
        graph_doc_max_chunks=_env_int(env_map, "GRAPH_DOC_MAX_CHUNKS", 0),
    )
