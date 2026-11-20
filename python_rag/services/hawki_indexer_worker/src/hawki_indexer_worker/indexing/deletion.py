"""Document deletion orchestration for vector and graph stores."""

from __future__ import annotations

from collections.abc import Mapping
import logging
from typing import NotRequired, TypedDict, cast

from hawki_indexer_worker.domain.ports import (
    GraphWriterFactory,
    VectorWriterFactory,
    VectorWriterPort,
)
from hawki_indexer_worker.indexing.graph_cleanup import close_graph_safely

logger = logging.getLogger(__name__)


class QdrantDeletionSummary(TypedDict):
    """Qdrant portion of a document deletion response."""

    doc_id: str
    collection: str | None
    deleted_points: int | None
    result: object


class Neo4jDeletionSummary(TypedDict):
    """Neo4j portion of a document deletion response."""

    doc_id: str
    namespace: str | None
    relationships_deleted: int
    entities_deleted: int
    result: NotRequired[object]


class DocumentDeletionResult(TypedDict):
    """Stable cross-store document deletion response."""

    qdrant: QdrantDeletionSummary
    neo4j: Neo4jDeletionSummary


class _Neo4jDeletePayload(TypedDict):
    relationships_deleted: int
    entities_deleted: int
    result: NotRequired[object]


def delete_document_entries(
    doc_id: str,
    *,
    idempotency_key: str | None = None,
    collection: str | None = None,
    neo4j_namespace: str | None = None,
    vector_writer_factory: VectorWriterFactory,
    graph_writer_factory: GraphWriterFactory,
) -> DocumentDeletionResult:
    normalized_doc_id = str(doc_id or "").strip()
    normalized_collection = _string_value(collection)
    normalized_namespace = _string_value(neo4j_namespace)
    if not normalized_doc_id:
        qdrant_result: QdrantDeletionSummary = {
            "doc_id": normalized_doc_id,
            "collection": normalized_collection,
            "deleted_points": 0,
            "result": {"status": "skipped", "deleted": 0},
        }
        neo4j_result: Neo4jDeletionSummary = {
            "doc_id": normalized_doc_id,
            "namespace": normalized_namespace,
            "relationships_deleted": 0,
            "entities_deleted": 0,
        }
        return {"qdrant": qdrant_result, "neo4j": neo4j_result}

    qdrant = vector_writer_factory()
    if normalized_collection:
        qdrant.set_collection(normalized_collection)
    qdrant_collection = normalized_collection or _string_value(qdrant.collection)
    deleted_points = _count_qdrant_points(qdrant, normalized_doc_id, qdrant_collection)
    raw_qdrant_result = qdrant.delete_by_doc_id(
        normalized_doc_id,
        idempotency_key=idempotency_key,
    )

    graph = graph_writer_factory(
        database=None,
        dataset_id=None,
        neo4j_namespace=normalized_namespace,
    )
    try:
        raw_neo4j_result = graph.delete_by_doc_id(
            normalized_doc_id, request_id=idempotency_key
        )
    finally:
        close_graph_safely(graph, logger_obj=logger, operation="delete_document")

    qdrant_result: QdrantDeletionSummary = {
        "doc_id": normalized_doc_id,
        "collection": qdrant_collection,
        "deleted_points": _deleted_points(deleted_points, raw_qdrant_result),
        "result": raw_qdrant_result,
    }
    neo4j_payload = _neo4j_delete_payload(raw_neo4j_result)
    neo4j_result: Neo4jDeletionSummary = {
        "doc_id": normalized_doc_id,
        "namespace": normalized_namespace,
        "relationships_deleted": neo4j_payload["relationships_deleted"],
        "entities_deleted": neo4j_payload["entities_deleted"],
    }
    if "result" in neo4j_payload:
        neo4j_result["result"] = neo4j_payload["result"]

    return {"qdrant": qdrant_result, "neo4j": neo4j_result}


def _string_value(value: object) -> str | None:
    if isinstance(value, str):
        stripped = value.strip()
        return stripped or None
    if isinstance(value, (int, float)):
        stripped = str(value).strip()
        return stripped or None
    return None


def _count_qdrant_points(
    qdrant: VectorWriterPort,
    doc_id: str,
    collection: str | None,
) -> int | None:
    try:
        count = qdrant.count_points_by_doc_id(
            doc_id,
            collection=collection,
            exact=True,
        )
    except Exception:
        return None
    return _optional_count(count)


def _deleted_points(count: int | None, result: object) -> int | None:
    if count is not None:
        return count

    result_mapping = _string_key_mapping(result)
    if result_mapping is None:
        return None

    direct = result_mapping.get("deleted")
    if type(direct) is int:
        return direct

    nested_mapping = _string_key_mapping(result_mapping.get("result"))
    if nested_mapping is not None:
        nested_deleted = nested_mapping.get("deleted")
        if type(nested_deleted) is int:
            return nested_deleted

    return None


def _neo4j_delete_payload(result: object) -> _Neo4jDeletePayload:
    result_mapping = _string_key_mapping(result)
    if result_mapping is None:
        return {
            "relationships_deleted": 0,
            "entities_deleted": 0,
            "result": result,
        }

    return {
        "relationships_deleted": _count_or_zero(
            result_mapping.get("relationships_deleted")
        ),
        "entities_deleted": _count_or_zero(result_mapping.get("entities_deleted")),
    }


def _string_key_mapping(value: object) -> Mapping[str, object] | None:
    if not isinstance(value, Mapping):
        return None
    return cast(Mapping[str, object], value)


def _optional_count(value: object) -> int | None:
    if isinstance(value, bool) or not isinstance(value, (int, float, str, bytes)):
        return None
    try:
        return int(value)
    except (OverflowError, TypeError, ValueError):
        return None


def _count_or_zero(value: object) -> int:
    return _optional_count(value) or 0


__all__ = [
    "DocumentDeletionResult",
    "delete_document_entries",
]
