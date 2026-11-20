"""Neo4j request telemetry behavior."""

from __future__ import annotations

from types import SimpleNamespace
import unittest


class GraphReliabilityTests(unittest.TestCase):
    def test_neo4j_graph_preserves_request_id_for_managed_write_telemetry(
        self,
    ) -> None:
        from hawki_graph_store.graph import Neo4jGraph

        class FakeExecutor:
            def __init__(self) -> None:
                self.requests: list[object] = []

            def run_read(self, request, callback):
                return callback(SimpleNamespace(run=lambda *_a, **_k: None))

            def run_write(self, request, callback):
                self.requests.append(request)
                result = SimpleNamespace(consume=lambda: None)
                return callback(SimpleNamespace(run=lambda *_a, **_k: result))

        executor = FakeExecutor()
        graph = Neo4jGraph(
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            settings=SimpleNamespace(database=None, log_latency=False, perf_log=False),
            query_executor=executor,  # type: ignore[arg-type]
        )

        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-id", request_id=None)
        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-id", request_id="op-1")

        first_request, second_request = executor.requests[0], executor.requests[1]
        self.assertIsNone(first_request.request_id)
        self.assertEqual(second_request.request_id, "op-1")
