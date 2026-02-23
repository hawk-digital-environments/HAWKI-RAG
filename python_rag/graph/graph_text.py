"""
Graph ingestion from raw text via RAGService triplet extraction, then Neo4j upsert.
"""
from __future__ import annotations

import logging
import os
import time
from typing import Any, Dict

from graph.neo4j_graph import Neo4jGraph
from graph.graph_utils import clean_triplets

logger = logging.getLogger(__name__)
GRAPH_PERF_LOG = os.environ.get("GRAPH_PERF_LOG", "").strip().lower() in ("1", "true", "yes")


def _perf_log(msg: str, *args) -> None:
    if GRAPH_PERF_LOG:
        logger.info(msg, *args)

def graph_from_text(body: Any, *, rag_service: Any) -> Dict[str, Any]:
    fn_start = time.perf_counter()
    _perf_log("perf:graph graph.graph_text.graph_from_text start engine=%s chars=%s", getattr(body, "engine", None), len(getattr(body, "text", "") or ""))
    extract_start = time.perf_counter()
    triplets = rag_service.extract_triplets(body.text, body.engine)
    extract_ms = (time.perf_counter() - extract_start) * 1000
    _perf_log("perf:graph graph.graph_text.graph_from_text step=extract raw_triplets=%s ms=%.2f", len(triplets), extract_ms)
    clean_start = time.perf_counter()
    triplets = clean_triplets(triplets)
    clean_ms = (time.perf_counter() - clean_start) * 1000
    _perf_log("perf:graph graph.graph_text.graph_from_text step=clean triplets=%s ms=%.2f", len(triplets), clean_ms)
    logger.info("graph:from_text triplets=%s", len(triplets))
    g = Neo4jGraph()
    upsert_start = time.perf_counter()
    g.upsert_triplets(triplets)
    upsert_ms = (time.perf_counter() - upsert_start) * 1000
    _perf_log("perf:graph graph.graph_text.graph_from_text step=neo4j_upsert triplets=%s ms=%.2f", len(triplets), upsert_ms)
    g.close()
    _perf_log(
        "perf:graph graph.graph_text.graph_from_text done triplets=%s total_ms=%.2f",
        len(triplets),
        (time.perf_counter() - fn_start) * 1000,
    )
    return {"ok": True, "triplets": len(triplets)}
