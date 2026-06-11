"""
Graph ingestion from raw text via the RAGService RAG-Anything KG pipeline, then Neo4j upsert.

The HTTP contract stays the same, but the internals now use RAG-Anything content insertion
and graph export instead of a direct LightRAG extraction wrapper.
"""
from __future__ import annotations

import logging
import time
import os
from collections.abc import Mapping
from typing import Any

from graph.neo4j_graph import Neo4jGraph
from graph.graph_utils import clean_triplets

logger = logging.getLogger(__name__)


def _env_bool(env: Mapping[str, str], name: str, default: bool = False) -> bool:
    value = str(env.get(name, "")).strip().lower()
    if not value:
        return default
    return value in {"1", "true", "yes", "on"}


def _perf_log(enabled: bool, msg: str, *args: object) -> None:
    if enabled:
        logger.info(msg, *args)


def graph_from_text(
    body: Any,
    *,
    rag_service: Any,
    graph_perf_log: bool | None = None,
) -> dict[str, Any]:
    if graph_perf_log is None:
        graph_perf_log = _env_bool(os.environ, "GRAPH_PERF_LOG")
    fn_start = time.perf_counter()
    _perf_log(
        graph_perf_log,
        "perf:graph graph.graph_text.graph_from_text start engine=%s chars=%s",
        getattr(body, "engine", None),
        len(getattr(body, "text", "") or ""),
    )
    extract_start = time.perf_counter()
    triplets = rag_service.extract_triplets(body.text, body.engine)
    extract_ms = (time.perf_counter() - extract_start) * 1000
    _perf_log(
        graph_perf_log,
        "perf:graph graph.graph_text.graph_from_text step=extract raw_triplets=%s ms=%.2f",
        len(triplets),
        extract_ms,
    )
    clean_start = time.perf_counter()
    triplets = clean_triplets(triplets)
    clean_ms = (time.perf_counter() - clean_start) * 1000
    _perf_log(
        graph_perf_log,
        "perf:graph graph.graph_text.graph_from_text step=clean triplets=%s ms=%.2f",
        len(triplets),
        clean_ms,
    )
    logger.info("graph:from_text triplets=%s", len(triplets))
    g = Neo4jGraph()
    upsert_start = time.perf_counter()
    g.upsert_triplets(triplets)
    upsert_ms = (time.perf_counter() - upsert_start) * 1000
    _perf_log(
        graph_perf_log,
        "perf:graph graph.graph_text.graph_from_text step=neo4j_upsert triplets=%s ms=%.2f",
        len(triplets),
        upsert_ms,
    )
    g.close()
    _perf_log(
        graph_perf_log,
        "perf:graph graph.graph_text.graph_from_text done triplets=%s total_ms=%.2f",
        len(triplets),
        (time.perf_counter() - fn_start) * 1000,
    )
    return {"ok": True, "triplets": len(triplets)}
