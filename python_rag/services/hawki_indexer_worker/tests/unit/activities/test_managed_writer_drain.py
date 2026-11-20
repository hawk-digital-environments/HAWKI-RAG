"""Managed writers release only after acknowledged sink completion."""

from types import SimpleNamespace

import pytest

from hawki_indexer_worker.adapters import neo4j_writer
from hawki_indexer_worker.indexing.point_identity import deterministic_point_id
from hawki_vector_store.client import QdrantHTTP
from services.hawki_indexer_worker.tests.unit.activities.managed_writer_test_support import (
    registration,
)


@pytest.mark.parametrize("operation", ["upsert", "delete"])
def test_ambiguous_canonical_graph_write_cannot_release_permission(
    tmp_path, monkeypatch, operation
):
    writer = registration(tmp_path)
    writer.targets["owned"] = "historic"

    def ambiguous(*_args, **_kwargs):
        raise TimeoutError("Neo4j commit acknowledgement lost")

    graph = neo4j_writer.RegisteredGraphWriter(
        SimpleNamespace(upsert_triplets=ambiguous, delete_scoped_document=ambiguous),
        writer.failed_write,
        authorize=writer.authorize_graph,
        dataset_id="dataset",
        neo4j_namespace="historic",
    )
    with pytest.raises(TimeoutError):
        if operation == "upsert":
            graph.upsert_triplets(
                [("A", "KNOWS", "B")],
                doc_id="owned",
                request_id="write",
                dataset_id="dataset",
                neo4j_namespace="historic",
            )
        else:
            graph.delete_by_doc_id("owned", request_id="write")
    monkeypatch.setattr(
        writer,
        "send",
        lambda *_args: pytest.fail("No release after ambiguous graph write"),
    )
    with pytest.raises(RuntimeError, match="uncertain"):
        writer.release()


@pytest.mark.parametrize("sink_kind", ["vector", "graph"])
def test_release_drains_admitted_writes_and_closes_cached_adapters(
    tmp_path, monkeypatch, sink_kind
):
    from concurrent.futures import ThreadPoolExecutor
    from threading import Event

    writer = registration(tmp_path)
    writer.targets["owned"] = "historic"
    entered, proceed, closing, released = Event(), Event(), Event(), Event()

    def delayed(*_args, **_kwargs):
        entered.set()
        assert proceed.wait(3)

    monkeypatch.setattr(writer, "send", lambda *_args: released.set())
    if sink_kind == "vector":
        monkeypatch.setattr(QdrantHTTP, "upsert", delayed)
        sink = writer.vector_writer()

        def mutate():
            return sink.upsert_points(
                [
                    {
                        "id": deterministic_point_id("owned", 0),
                        "payload": {
                            "doc_id": "owned",
                            "source_id": "source",
                            "dataset_id": "dataset",
                        },
                    }
                ]
            )
    else:
        monkeypatch.setattr(neo4j_writer.Neo4jWriter, "upsert_triplets", delayed)
        sink = writer.graph_writer(dataset_id="dataset", neo4j_namespace="historic")

        def mutate():
            return sink.upsert_triplets(
                [("A", "KNOWS", "B")],
                doc_id="owned",
                request_id=None,
                dataset_id="dataset",
                neo4j_namespace="historic",
            )

    def finish():
        closing.set()
        writer.release()

    try:
        with ThreadPoolExecutor(max_workers=2) as executor:
            write = executor.submit(mutate)
            assert entered.wait(3)
            finish_future = executor.submit(finish)
            assert closing.wait(3)
            assert not released.wait(0.05)
            proceed.set()
            write.result(3)
            finish_future.result(3)
        assert released.is_set()
        with pytest.raises(RuntimeError, match="mutations are closed"):
            mutate()
    finally:
        proceed.set()
        if sink_kind == "graph":
            sink.close()
