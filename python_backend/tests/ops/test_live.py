"""Use real PostgreSQL, Neo4j and Qdrant, exclusively disposable test services."""

import os
from dataclasses import replace
from types import SimpleNamespace

import httpx
import pytest
from hawki_backend.datasets.catalog import QdrantCatalog
from hawki_graph_store import Neo4jGraph, load_neo4j_settings
from .conftest import call
from .test_postgres import pg  # noqa: F401


def test_real_owned_stats_and_graph_clear(pg, monkeypatch):  # noqa: F811 - imported pytest fixture
    qdrant = os.environ.get("RAG_EPOCH14_QDRANT_URL")
    neo4j = os.environ.get("RAG_EPOCH14_NEO4J_URI")
    if not qdrant or not neo4j:
        pytest.skip("Set the explicit disposable Epoch 14 Neo4j and Qdrant URLs")
    settings = replace(load_neo4j_settings(), uri=neo4j, max_transaction_retry_time=0)

    def factory(dataset, namespace):
        return Neo4jGraph(
            dataset_id=dataset,
            neo4j_namespace=namespace,
            settings=settings,
            allow_database_fallback=False,
        )

    pg.ops.graph_factory = factory
    pg.ops.catalog = QdrantCatalog(qdrant)
    graphs = []
    with httpx.Client(base_url=qdrant, timeout=10) as client:
        for dataset in ["one", "two", "foreign", "disabled"]:
            name = "physical-vector-" + dataset
            assert client.put(
                "/collections/" + name,
                json={"vectors": {"size": 2, "distance": "Cosine"}},
            ).is_success
            assert client.put(
                "/collections/" + name + "/points?wait=true",
                json={
                    "points": [
                        {
                            "id": 1,
                            "vector": [1.0, 0.0],
                            "payload": {"dataset_id": dataset},
                        }
                    ]
                },
            ).is_success
            graph = factory(dataset, "physical-graph-" + dataset)
            graph.upsert_triplets(
                [("Same entity", "belongs", dataset)], doc_id="document"
            )
            graphs.append(graph)
        try:
            stats = call(pg, "stats", token="manager")
            assert stats.status_code == 200
            assert "foreign" not in stats.text
            assert (
                next(
                    row
                    for row in stats.json()["qdrant"]["collections"]
                    if row["dataset_id"] == "one"
                )["count"]
                == 1
            )
            assert stats.json()["neo4j"] == {"ok": True, "entities": 4, "triplets": 2}
            for dataset in ["one", "two"]:
                scene = call(pg, "graph/overview?dataset_id=" + dataset)
                assert scene.status_code == 200
                assert {node["label"] for node in scene.json()["nodes"]} == {
                    "Same entity",
                    dataset,
                }
                search = call(pg, "graph/search?dataset_id=" + dataset + "&q=Same")
                assert search.status_code == 200
                assert len(search.json()["results"]) == 1
            first_id = graphs[0].graph_search("Same entity")[0]["id"]
            foreign = call(pg, "graph/node?dataset_id=two&node_id=" + first_id)
            assert foreign.status_code == 200
            assert foreign.json()["nodes"] == []
            expanded = call(
                pg,
                "graph/expand",
                method="POST",
                body={"dataset_id": "two", "node_id": first_id},
            )
            assert expanded.status_code == 200
            assert expanded.json()["nodes"] == []
            from hawki_backend.ops import api

            def retrieval(body, principal, request):
                assert body.generate is False
                return SimpleNamespace(
                    kg=[{"subject": "Same entity", "object": "foreign"}]
                )

            monkeypatch.setattr(api, "query", retrieval)
            semantic = call(pg, "graph/semantic-search?dataset_id=one&q=Same")
            assert semantic.status_code == 200
            assert [node["label"] for node in semantic.json()["results"]] == [
                "Same entity"
            ]
            clear = call(
                pg,
                "graph/clear",
                token="manager",
                method="POST",
                body={"dataset_id": "one", "confirm": "CLEAR_DATASET_GRAPH"},
            )
            assert clear.status_code == 200
            assert clear.json()["deleted_nodes"] == 2
            assert graphs[1].graph_counts()["entities"] == 2
            assert graphs[2].graph_counts()["entities"] == 2
            assert pg.ops.catalog.point_count("physical-vector-one") == 1
        finally:
            for dataset, graph in zip(["one", "two", "foreign", "disabled"], graphs):
                graph.clear_dataset_graph()
                graph.close()
                assert client.delete(
                    "/collections/physical-vector-" + dataset
                ).is_success
