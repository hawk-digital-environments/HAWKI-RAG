"""Strict scoped cleanup for durable managed operations; no direct-text changes."""

from typing import Any

from hawki_rag_contracts.pipeline.deletion import ManagedDeletionSinkInput
from hawki_indexer_worker.domain.ports import VectorWriterPort, GraphWriterFactory


def delete_vectors(payload: dict[str, Any], writer: VectorWriterPort) -> dict[str, Any]:
    body = ManagedDeletionSinkInput.model_validate(payload)
    target = body.target
    dataset = body.dataset_id
    writer.set_collection(target.collection)
    filters = {
        "must": [
            {"key": key, "match": {"value": value}}
            for key, value in {
                "doc_id": target.doc_id,
                "source_id": target.source_id,
                "dataset_id": dataset,
            }.items()
        ]
    }
    before = writer.count_points_verified(filters)
    writer.delete_by_filter(
        filters, idempotency_key=f"{body.operation_id}:{target.output_id}:vector"
    )
    remaining = writer.count_points_verified(filters)
    if remaining != 0:
        raise RuntimeError("Qdrant points remain after managed deletion")
    return {
        "verified": True,
        "remaining_points": 0,
        "deleted_points": before,
        "doc_id": target.doc_id,
        "collection": target.collection,
        "source_id": target.source_id,
        "dataset_id": dataset,
    }


def delete_graph(
    payload: dict[str, Any], graph_factory: GraphWriterFactory
) -> dict[str, Any]:
    body = ManagedDeletionSinkInput.model_validate(payload)
    target = body.target
    if target.neo4j_namespace is None:
        return {"verified": True, "required": False, "remaining_contributions": 0}
    graph = graph_factory(
        dataset_id=body.dataset_id, neo4j_namespace=target.neo4j_namespace
    )
    try:
        return {
            **graph.delete_scoped_document(
                target.doc_id,
                request_id=f"{body.operation_id}:{target.output_id}:graph",
            ),
            "required": True,
            "dataset_id": body.dataset_id,
            "doc_id": target.doc_id,
            "namespace": target.neo4j_namespace,
        }
    finally:
        graph.close()
