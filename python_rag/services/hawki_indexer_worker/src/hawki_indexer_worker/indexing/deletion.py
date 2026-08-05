"""Document deletion orchestration for vector and graph stores."""

from __future__ import annotations

from collections.abc import Callable, Mapping, Sequence
from inspect import signature
from typing import NotRequired, Protocol, TypedDict, cast, runtime_checkable

from hawki_rag_stores.neo4j.graph import Neo4jGraph
from hawki_rag_stores.qdrant.client import QdrantHTTP


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


class QdrantDeletionStore(Protocol):
    """Required Qdrant operation for deleting one document."""

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        idempotency_key: str | None = None,
    ) -> object:
        """Delete all vector points belonging to ``doc_id``."""


class Neo4jDeletionStore(Protocol):
    """Required Neo4j operations for deleting one document."""

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        request_id: str | None = None,
    ) -> object:
        """Delete graph facts belonging to ``doc_id``."""

    def close(self) -> None:
        """Release graph driver resources."""


@runtime_checkable
class _CollectionSetter(Protocol):
    def set_collection(self, collection: str) -> None:
        """Select the Qdrant collection used by subsequent operations."""


@runtime_checkable
class _QdrantPointCounter(Protocol):
    def count_points_by_doc_id(self, doc_id: str, **kwargs: object) -> object:
        """Return the number of points currently stored for ``doc_id``."""


@runtime_checkable
class _QdrantPayloadFinder(Protocol):
    def find_points_by_payload(
        self,
        filters: Mapping[str, object],
        *,
        limit: int = 1,
    ) -> Sequence[object]:
        """Return points matching a payload filter."""


QdrantDeletionFactory = Callable[[], QdrantDeletionStore]
Neo4jDeletionFactory = Callable[..., Neo4jDeletionStore]


def _method_supports_kwarg(target: object, method_name: str, kwarg: str) -> bool:
    method = getattr(target, method_name, None)
    if not callable(method):
        return False
    try:
        params = signature(method).parameters.values()
    except (TypeError, ValueError):
        return False
    return any(
        param.name == kwarg or param.kind == param.VAR_KEYWORD for param in params
    )


def delete_document_entries(
    doc_id: str,
    *,
    idempotency_key: str | None = None,
    collection: str | None = None,
    neo4j_namespace: str | None = None,
    qdrant_factory: QdrantDeletionFactory = QdrantHTTP,
    graph_factory: Neo4jDeletionFactory = Neo4jGraph,
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

    qdrant = qdrant_factory()
    _apply_collection_scope(qdrant, normalized_collection)
    qdrant_collection = normalized_collection or _string_value(
        getattr(qdrant, "collection", None)
    )
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
            raw_neo4j_result = graph.delete_by_doc_id(
                normalized_doc_id, request_id=idempotency_key
            )
        else:
            raw_neo4j_result = graph.delete_by_doc_id(normalized_doc_id)
    finally:
        try:
            graph.close()
        except Exception:
            pass

    qdrant_result: QdrantDeletionSummary = {
        "doc_id": normalized_doc_id,
        "collection": qdrant_collection,
        "deleted_points": _deleted_points(deleted_points, raw_qdrant_result),
        "result": raw_qdrant_result,
    }
    neo4j_payload = _neo4j_delete_payload(raw_neo4j_result)
    neo4j_result: Neo4jDeletionSummary = {
        "doc_id": normalized_doc_id,
        "namespace": graph_namespace,
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


def _apply_collection_scope(
    qdrant: QdrantDeletionStore, collection: str | None
) -> None:
    if not collection:
        return
    if isinstance(qdrant, _CollectionSetter):
        qdrant.set_collection(collection)
        return
    if hasattr(qdrant, "collection"):
        setattr(qdrant, "collection", collection)


def _factory_supports_kwarg(factory: Callable[..., object], kwarg: str) -> bool:
    try:
        params = signature(factory).parameters.values()
    except (TypeError, ValueError):
        return False
    return any(
        param.name == kwarg or param.kind == param.VAR_KEYWORD for param in params
    )


def _instantiate_graph(
    graph_factory: Neo4jDeletionFactory,
    namespace: str | None,
) -> Neo4jDeletionStore:
    if namespace and _factory_supports_kwarg(graph_factory, "neo4j_namespace"):
        return graph_factory(neo4j_namespace=namespace)
    return graph_factory()


def _graph_namespace(graph: Neo4jDeletionStore) -> str | None:
    return _string_value(getattr(graph, "_neo4j_namespace", None))


def _count_qdrant_points(
    qdrant: QdrantDeletionStore,
    doc_id: str,
    collection: str | None,
) -> int | None:
    if isinstance(qdrant, _QdrantPointCounter):
        count_kwargs: dict[str, object] = {}
        if collection and _method_supports_kwarg(
            qdrant, "count_points_by_doc_id", "collection"
        ):
            count_kwargs["collection"] = collection
        if _method_supports_kwarg(qdrant, "count_points_by_doc_id", "exact"):
            count_kwargs["exact"] = True
        try:
            count = qdrant.count_points_by_doc_id(doc_id, **count_kwargs)
        except Exception:
            return None
        return _optional_count(count)

    if isinstance(qdrant, _QdrantPayloadFinder):
        try:
            return len(qdrant.find_points_by_payload({"doc_id": doc_id}, limit=100000))
        except Exception:
            return None

    return None


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
    "Neo4jDeletionStore",
    "QdrantDeletionStore",
    "delete_document_entries",
]
