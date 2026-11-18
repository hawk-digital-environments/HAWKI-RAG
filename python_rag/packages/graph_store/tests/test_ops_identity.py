"""Public identity binds the stable domain key to both scope components."""

from unittest.mock import Mock
import pytest
from hawki_graph_store import Neo4jGraph
from hawki_graph_store.responses import scoped_entity_key, scoped_node_id


@pytest.mark.parametrize(
    "dataset,namespace", [(None, None), ("a", None), (None, "ns"), ("", "ns")]
)
@pytest.mark.parametrize(
    "method,kwargs",
    [
        ("graph_overview", {}),
        ("graph_search", {"query": "same"}),
        ("graph_node", {"node_id": "foreign"}),
        ("graph_expand", {"node_id": "foreign"}),
        ("graph_counts", {}),
        ("clear_dataset_graph", {}),
    ],
)
def test_partial_scope_refuses_before_io(dataset, namespace, method, kwargs):
    executor = Mock()
    graph = Neo4jGraph(
        dataset_id=dataset, neo4j_namespace=namespace, query_executor=executor
    )
    with pytest.raises(ValueError):
        getattr(graph, method)(**kwargs)
    executor.run_read.assert_not_called()
    executor.run_write.assert_not_called()


def test_identity_is_stable_and_dataset_qualified():
    first = scoped_node_id("a", "secret-namespace", "shared key")
    assert first == scoped_node_id("a", "secret-namespace", "shared key")
    assert scoped_entity_key(first, "a", "secret-namespace") == "shared key"
    assert scoped_entity_key(first, "b", "secret-namespace") is None
    assert scoped_entity_key(first, "a", "other") is None
    assert "secret-namespace" not in first
