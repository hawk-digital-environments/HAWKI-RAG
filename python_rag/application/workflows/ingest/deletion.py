"""Document deletion orchestration for vector and graph stores."""
from __future__ import annotations

from inspect import signature
from typing import Any

from infrastructure.graph.neo4j_graph import Neo4jGraph
from infrastructure.vectorstore.qdrant_http import QdrantHTTP


def _method_supports_kwarg(target: Any, method_name: str, kwarg: str) -> bool:
    method = getattr(target, method_name, None)
    if method is None:
        return False
    try:
        params = signature(method).parameters.values()
    except (TypeError, ValueError):
        return False
    return any(param.name == kwarg or param.kind == param.VAR_KEYWORD for param in params)


def delete_document_entries(
    doc_id: str,
    *,
    idempotency_key: str | None = None,
    qdrant_factory: Any = QdrantHTTP,
    graph_factory: Any = Neo4jGraph,
) -> dict[str, Any]:
    normalized_doc_id = str(doc_id or "").strip()
    if not normalized_doc_id:
        return {
            "qdrant": {"result": {"status": "skipped", "deleted": 0}, "deleted_docs": 0},
            "neo4j": {"relationships_deleted": 0, "entities_deleted": 0},
        }

    qdrant = qdrant_factory()
    if _method_supports_kwarg(qdrant, "delete_by_doc_id", "idempotency_key"):
        qdrant_result = qdrant.delete_by_doc_id(
            normalized_doc_id,
            idempotency_key=idempotency_key,
        )
    else:
        qdrant_result = qdrant.delete_by_doc_id(normalized_doc_id)
    graph = graph_factory()
    try:
        if _method_supports_kwarg(graph, "delete_by_doc_id", "request_id"):
            neo4j_result = graph.delete_by_doc_id(normalized_doc_id, request_id=idempotency_key)
        else:
            neo4j_result = graph.delete_by_doc_id(normalized_doc_id)
    finally:
        try:
            graph.close()
        except Exception:
            pass
    return {"qdrant": qdrant_result, "neo4j": neo4j_result}
