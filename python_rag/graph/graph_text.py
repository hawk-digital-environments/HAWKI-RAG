"""
Graph ingestion from raw text via RAGService triplet extraction, then Neo4j upsert.
"""
from __future__ import annotations

import logging
from typing import Any, Dict

from graph.neo4j_graph import Neo4jGraph
from graph.graph_utils import clean_triplets

logger = logging.getLogger(__name__)

def graph_from_text(body: Any, *, rag_service: Any) -> Dict[str, Any]:
    triplets = rag_service.extract_triplets(body.text, body.engine)
    triplets = clean_triplets(triplets)
    logger.info("graph:from_text triplets=%s", len(triplets))
    g = Neo4jGraph()
    g.upsert_triplets(triplets)
    g.close()
    return {"ok": True, "triplets": len(triplets)}
