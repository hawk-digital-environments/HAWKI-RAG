"""Document deletion orchestration for vector and graph stores."""
from __future__ import annotations

from typing import Any, Dict

from graph.neo4j_graph import Neo4jGraph
from vectorstore.qdrant_http import QdrantHTTP


def delete_document_entries(
    doc_id: str,
    *,
    qdrant_factory: Any = QdrantHTTP,
    graph_factory: Any = Neo4jGraph,
) -> Dict[str, Any]:
    qdrant = qdrant_factory()
    qdrant_result = qdrant.delete_by_doc_id(doc_id)
    graph = graph_factory()
    try:
        neo4j_result = graph.delete_by_doc_id(doc_id)
    finally:
        try:
            graph.close()
        except Exception:
            pass
    return {"qdrant": qdrant_result, "neo4j": neo4j_result}
