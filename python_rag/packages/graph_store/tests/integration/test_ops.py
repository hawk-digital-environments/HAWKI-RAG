"""Real Neo4j isolation and mutation tests; only unique disposable scopes mutate."""

from dataclasses import replace
from uuid import uuid4

import pytest
from hawki_graph_store import Neo4jGraph
from hawki_graph_store.responses import scoped_node_id

pytestmark = pytest.mark.integration


@pytest.fixture
def graphs(live_neo4j):
    token = uuid4().hex
    settings = replace(live_neo4j.settings, max_transaction_retry_time=0)
    first = Neo4jGraph(
        dataset_id="a-" + token, neo4j_namespace="ns-" + token, settings=settings
    )
    second = Neo4jGraph(
        dataset_id="b-" + token, neo4j_namespace="ns-" + token, settings=settings
    )
    first.upsert_triplets(
        [(f"Node {i}", "rel", f"Node {i + 1}") for i in range(4)], doc_id="owned"
    )
    second.upsert_triplets([("Node 0", "rel", "Foreign")], doc_id="foreign")
    try:
        yield first, second
    finally:
        # Every fixture resource is uniquely reserved for this test.
        with live_neo4j.driver.session(database=settings.database) as session:
            session.run(
                "MATCH (n) WHERE n.neo4j_namespace=$namespace DETACH DELETE n",
                namespace="ns-" + token,
            ).consume()
        first.close()
        second.close()


@pytest.mark.parametrize(
    "action", ["graph_overview", "graph_search", "graph_node", "graph_expand"]
)
def test_same_name_and_foreign_node_never_cross_scope(graphs, action):
    first, second = graphs
    found = first.graph_search("Node 0")[0]
    if action == "graph_search":
        result = first.graph_search("Foreign")
        assert result == []
    else:
        result = getattr(first, action)(
            **({} if action == "graph_overview" else {"node_id": found["id"]})
        )
        assert all(node["label"] != "Foreign" for node in result["nodes"])
    assert second.graph_node(found["id"])["nodes"] == []


def test_stable_nodes_edges_and_hidden_physical_scope(graphs):
    first, _ = graphs
    before = first.graph_overview()
    first.upsert_triplets([("Node 0", "rel", "Node 1")], doc_id="another")
    after = first.graph_overview()
    assert {node["id"] for node in before["nodes"]} == {
        node["id"] for node in after["nodes"]
    }
    assert {edge["id"] for edge in before["edges"]} == {
        edge["id"] for edge in after["edges"]
    }
    for item in after["nodes"] + after["edges"]:
        assert not {"dataset_id", "neo4j_namespace", "elementId"}.intersection(
            item["properties"]
        )


@pytest.mark.parametrize("limit", [1, 2, 3])
def test_scene_and_search_limits(graphs, limit):
    first, _ = graphs
    node = first.graph_search("Node 0")[0]
    assert len(first.graph_overview(limit)["nodes"]) <= limit
    assert len(first.graph_search("Node", limit)) <= limit
    assert len(first.graph_expand(node["id"], 3, limit)["nodes"]) <= limit


@pytest.mark.parametrize("requested,expected", [(-9, 1), (1, 1), (2, 2), (99, 3)])
def test_depth_clamp(graphs, requested, expected):
    first, _ = graphs
    node = first.graph_search("Node 0")[0]
    scene = first.graph_expand(node["id"], requested)
    assert scene["depth"] == expected
    assert len(scene["nodes"]) == expected + 1


def test_counts_clear_idempotence_and_other_dataset_untouched(graphs):
    first, second = graphs
    assert first.graph_counts() == {"entities": 5, "triplets": 4}
    foreign = second.graph_overview()
    assert first.clear_dataset_graph() == {
        "deleted_nodes": 5,
        "deleted_relationships": 4,
    }
    assert first.clear_dataset_graph() == {
        "deleted_nodes": 0,
        "deleted_relationships": 0,
    }
    assert first.graph_counts() == {"entities": 0, "triplets": 0}
    assert second.graph_overview() == foreign


def test_namespace_is_required_as_well_as_dataset(graphs):
    first, _ = graphs
    other = Neo4jGraph(
        dataset_id=first._dataset_id, neo4j_namespace="wrong", settings=first._settings
    )
    try:
        assert other.graph_overview()["nodes"] == []
        assert other.graph_counts() == {"entities": 0, "triplets": 0}
        assert other.clear_dataset_graph() == {
            "deleted_nodes": 0,
            "deleted_relationships": 0,
        }
        assert first.graph_counts()["entities"] == 5
    finally:
        other.close()


def test_name_lookup_stays_scoped(graphs):
    first, second = graphs
    assert [
        node["label"] for node in first.graph_search("", names=["Foreign", "Node 0"])
    ] == ["Node 0"]
    assert {
        node["label"] for node in second.graph_search("", names=["Foreign", "Node 0"])
    } == {"Foreign", "Node 0"}


def test_missing_identity_returns_warning(graphs):
    first, _ = graphs
    identity = scoped_node_id(first._dataset_id, first._neo4j_namespace, "missing")
    assert first.graph_node(identity)["nodes"] == []
    assert first.graph_node(identity)["warnings"]


def test_foreign_relationship_refuses_clear_and_rolls_back_every_deletion(
    graphs, live_neo4j
):
    first, second = graphs
    with live_neo4j.driver.session(database=first._settings.database) as session:
        session.run(
            "MATCH (a:Entity {dataset_id:$a, neo4j_namespace:$namespace, entity_key:'node 0'}), "
            "(b:Entity {dataset_id:$b, neo4j_namespace:$namespace, entity_key:'foreign'}) "
            "CREATE (a)-[:BROKEN {dataset_id:$b, neo4j_namespace:$namespace}]->(b)",
            a=first._dataset_id,
            b=second._dataset_id,
            namespace=first._neo4j_namespace,
        ).consume()
    from neo4j.exceptions import Neo4jError

    with pytest.raises(Neo4jError):
        first.clear_dataset_graph()
    assert first.graph_counts() == {"entities": 5, "triplets": 4}
    assert second.graph_counts() == {"entities": 2, "triplets": 1}
