"""Strict sink verification and safe repetition after interrupted cleanup."""

from unittest.mock import Mock

import pytest
import requests

from hawki_indexer_worker.indexing.managed_deletion import delete_graph, delete_vectors
from hawki_vector_store.client import QdrantHTTP


def payload(namespace="namespace-a"):
    return {"operation_id": "delete_" + "c" * 64, "dataset_id": "dataset-a", "target": {
        "output_id": 2, "doc_id": "doc-a", "source_id": "source-a", "collection": "collection-a", "neo4j_namespace": namespace}}


def test_vectors_filter_all_trusted_identities_and_verify():
    writer = Mock()
    writer.count_points_verified.side_effect = [5, 0, 0, 0]
    assert delete_vectors(payload(), writer)["deleted_points"] == 5
    assert delete_vectors(payload(), writer)["deleted_points"] == 0
    filters = writer.delete_by_filter.call_args.args[0]
    assert {x["key"]: x["match"]["value"] for x in filters["must"]} == {"doc_id": "doc-a", "source_id": "source-a", "dataset_id": "dataset-a"}
    writer.set_collection.assert_called_with("collection-a")


def test_partial_vector_deletion_is_not_success():
    writer = Mock()
    writer.count_points_verified.side_effect = [5, 1]
    with pytest.raises(RuntimeError, match="points remain"):
        delete_vectors(payload(), writer)


def test_lost_sink_ack_is_retryable_and_reverification_succeeds():
    writer = Mock()
    writer.count_points_verified.side_effect = [5, 0, 0]
    writer.delete_by_filter.side_effect = [requests.ConnectionError("lost acknowledgement"), {}]
    with pytest.raises(requests.ConnectionError):
        delete_vectors(payload(), writer)
    assert delete_vectors(payload(), writer)["verified"] is True


def test_exact_count_transport_failure_is_not_zero():
    writer = object.__new__(QdrantHTTP)
    writer.collection = "collection-a"
    writer._http_settings = Mock(count_timeout=1)
    writer._gateway = Mock()
    writer._gateway.count_points.side_effect = requests.ConnectionError("count unavailable")
    with pytest.raises(requests.ConnectionError):
        writer.count_points_verified({"must": []})


@pytest.mark.parametrize("count", [None, -1, "0", 0.5, False])
def test_exact_count_rejects_invalid_proof(count):
    writer = object.__new__(QdrantHTTP)
    writer.collection = "collection-a"
    writer._http_settings = Mock(count_timeout=1)
    response = Mock(status_code=200)
    response.json.return_value = {"result": {"count": count}}
    writer._gateway = Mock()
    writer._gateway.count_points.return_value = response
    with pytest.raises(RuntimeError):
        writer.count_points_verified({"must": []})


def test_missing_collection_is_zero_only_on_explicit_404():
    writer = object.__new__(QdrantHTTP)
    writer.collection = "collection-a"
    writer._http_settings = Mock(count_timeout=1)
    writer._gateway = Mock()
    writer._gateway.count_points.return_value = Mock(status_code=404)
    assert writer.count_points_verified({"must": []}) == 0


def test_graph_adapter_passes_dataset_and_closes_after_failure():
    factory = Mock()
    factory.return_value.client.delete_scoped_document.side_effect = RuntimeError("graph failure")
    with pytest.raises(RuntimeError):
        delete_graph(payload(), factory)
    factory.assert_called_once_with(dataset_id="dataset-a", neo4j_namespace="namespace-a")
    factory.return_value.close.assert_called_once()


def test_optional_graph_does_not_open_a_connection():
    factory = Mock()
    assert delete_graph(payload(None), factory)["required"] is False
    factory.assert_not_called()
