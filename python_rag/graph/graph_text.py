"""
Graph ingestion from raw text via RAGService triplet extraction, then Neo4j upsert.
"""
from __future__ import annotations

from typing import Any, Dict

from graph.neo4j_graph import Neo4jGraph


def graph_from_text(body: Any, *, rag_service: Any) -> Dict[str, Any]:
    triplets = rag_service.extract_triplets(body.text, body.engine)
    g = Neo4jGraph()
    g.upsert_triplets(triplets)
    g.close()
    return {"ok": True, "triplets": len(triplets)}
