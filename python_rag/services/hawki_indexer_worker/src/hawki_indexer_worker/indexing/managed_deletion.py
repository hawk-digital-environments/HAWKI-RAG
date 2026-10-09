"""Strict scoped cleanup for durable managed operations; no direct-text changes."""

from typing import Any

from hawki_rag_contracts.pipeline.deletion import DeletionTarget


def delete_vectors(payload: dict[str, Any], writer: Any) -> dict[str, Any]:
    target = DeletionTarget.model_validate(payload["target"])
    dataset = str(payload["dataset_id"]).strip()
    if not dataset:
        raise ValueError("dataset_id is required")
    writer.set_collection(target.collection)
    filters = {"must": [
        {"key": key, "match": {"value": value}}
        for key, value in {"doc_id": target.doc_id, "source_id": target.source_id, "dataset_id": dataset}.items()
    ]}
    before = writer.count_points_verified(filters)
    writer.delete_by_filter(filters, idempotency_key=f"{payload['operation_id']}:{target.output_id}:vector")
    remaining = writer.count_points_verified(filters)
    if remaining != 0:
        raise RuntimeError("Qdrant points remain after managed deletion")
    return {"verified": True, "remaining_points": 0, "deleted_points": before,
        "doc_id": target.doc_id, "collection": target.collection}


def delete_graph(payload: dict[str, Any], graph_factory: Any) -> dict[str, Any]:
    target = DeletionTarget.model_validate(payload["target"])
    if target.neo4j_namespace is None:
        return {"verified": True, "required": False, "remaining_contributions": 0}
    graph = graph_factory(dataset_id=payload["dataset_id"], neo4j_namespace=target.neo4j_namespace)
    try:
        return {**graph.client.delete_scoped_document(
            target.doc_id, request_id=f"{payload['operation_id']}:{target.output_id}:graph"
        ), "required": True, "doc_id": target.doc_id, "namespace": target.neo4j_namespace}
    finally:
        graph.close()
