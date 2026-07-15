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
    collection: str | None = None,
    neo4j_namespace: str | None = None,
    qdrant_factory: Any = QdrantHTTP,
    graph_factory: Any = Neo4jGraph,
) -> dict[str, Any]:
    normalized_doc_id = str(doc_id or "").strip()
    normalized_collection = _string_value(collection)
    normalized_namespace = _string_value(neo4j_namespace)
    if not normalized_doc_id:
        return {
            "qdrant": {
                "doc_id": normalized_doc_id,
                "collection": normalized_collection,
                "deleted_points": 0,
                "result": {"status": "skipped", "deleted": 0},
            },
            "neo4j": {
                "doc_id": normalized_doc_id,
                "namespace": normalized_namespace,
                "relationships_deleted": 0,
                "entities_deleted": 0,
            },
        }

    qdrant = qdrant_factory()
    _apply_collection_scope(qdrant, normalized_collection)
    qdrant_collection = normalized_collection or _string_value(getattr(qdrant, "collection", None))
    deleted_points = _count_qdrant_points(qdrant, normalized_doc_id, qdrant_collection)
    if _method_supports_kwarg(qdrant, "delete_by_doc_id", "idempotency_key"):
        raw_qdrant_result = qdrant.delete_by_doc_id(
            normalized_doc_id,
            idempotency_key=idempotency_key,
        )
    else:
        raw_qdrant_result = qdrant.delete_by_doc_id(normalized_doc_id)

    graph = _instantiate_graph(graph_factory, normalized_namespace)
    graph_namespace = normalized_namespace or _graph_namespace(graph)
    try:
        if _method_supports_kwarg(graph, "delete_by_doc_id", "request_id"):
            raw_neo4j_result = graph.delete_by_doc_id(normalized_doc_id, request_id=idempotency_key)
        else:
            raw_neo4j_result = graph.delete_by_doc_id(normalized_doc_id)
    finally:
        try:
            graph.close()
        except Exception:
            pass

    qdrant_result = {
        "doc_id": normalized_doc_id,
        "collection": qdrant_collection,
        "deleted_points": _deleted_points(deleted_points, raw_qdrant_result),
        "result": raw_qdrant_result,
    }
    neo4j_result = {
        "doc_id": normalized_doc_id,
        "namespace": graph_namespace,
        **_neo4j_delete_payload(raw_neo4j_result),
    }
    return {"qdrant": qdrant_result, "neo4j": neo4j_result}


def _string_value(value: Any) -> str | None:
    if isinstance(value, str):
        stripped = value.strip()
        return stripped or None
    if isinstance(value, (int, float)):
        stripped = str(value).strip()
        return stripped or None
    return None


def _apply_collection_scope(qdrant: Any, collection: str | None) -> None:
    if not collection:
        return
    if hasattr(qdrant, "set_collection"):
        qdrant.set_collection(collection)
        return
    if hasattr(qdrant, "collection"):
        qdrant.collection = collection


def _factory_supports_kwarg(factory: Any, kwarg: str) -> bool:
    try:
        params = signature(factory).parameters.values()
    except (TypeError, ValueError):
        return False
    return any(param.name == kwarg or param.kind == param.VAR_KEYWORD for param in params)


def _instantiate_graph(graph_factory: Any, namespace: str | None) -> Any:
    if namespace and _factory_supports_kwarg(graph_factory, "neo4j_namespace"):
        return graph_factory(neo4j_namespace=namespace)
    return graph_factory()


def _graph_namespace(graph: Any) -> str | None:
    return _string_value(getattr(graph, "_neo4j_namespace", None))


def _count_qdrant_points(qdrant: Any, doc_id: str, collection: str | None) -> int | None:
    if hasattr(qdrant, "count_points_by_doc_id"):
        count_kwargs: dict[str, Any] = {}
        if collection and _method_supports_kwarg(qdrant, "count_points_by_doc_id", "collection"):
            count_kwargs["collection"] = collection
        if _method_supports_kwarg(qdrant, "count_points_by_doc_id", "exact"):
            count_kwargs["exact"] = True
        try:
            count = qdrant.count_points_by_doc_id(doc_id, **count_kwargs)
        except Exception:
            return None
        return int(count) if count is not None else None

    if hasattr(qdrant, "find_points_by_payload"):
        try:
            return len(qdrant.find_points_by_payload({"doc_id": doc_id}, limit=100000))
        except Exception:
            return None

    return None


def _deleted_points(count: int | None, result: Any) -> int | None:
    if count is not None:
        return int(count)
    if isinstance(result, dict):
        direct = result.get("deleted")
        if isinstance(direct, int):
            return direct
        nested = result.get("result")
        if isinstance(nested, dict):
            nested_deleted = nested.get("deleted")
            if isinstance(nested_deleted, int):
                return nested_deleted
    return None


def _neo4j_delete_payload(result: Any) -> dict[str, Any]:
    if not isinstance(result, dict):
        return {
            "relationships_deleted": 0,
            "entities_deleted": 0,
            "result": result,
        }

    relationships_deleted = result.get("relationships_deleted")
    entities_deleted = result.get("entities_deleted")
    return {
        "relationships_deleted": int(relationships_deleted or 0),
        "entities_deleted": int(entities_deleted or 0),
    }
