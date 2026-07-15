from __future__ import annotations

import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from typing import Any
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


class _ScopeAwareExecutor:
    def __init__(self) -> None:
        self.requests: list[Any] = []
        self.rows = [
            {
                "dataset_id": "dataset-a",
                "neo4j_namespace": "graph-a",
                "subject": "A subject",
                "relation": "A relation",
                "object": "A object",
                "doc_id": "doc-a",
                "hops": 1,
            },
            {
                "dataset_id": "dataset-b",
                "neo4j_namespace": "graph-b",
                "subject": "B subject",
                "relation": "B relation",
                "object": "B object",
                "doc_id": "doc-b",
                "hops": 1,
            },
        ]

    def run_read(self, request: Any, callback: Any) -> Any:
        self.requests.append(request)
        rows = self.rows

        class Tx:
            def run(self, statement: str, **params: Any) -> list[dict[str, Any]]:
                assert statement == request.statement
                return [
                    row
                    for row in rows
                    if row["dataset_id"] == params["dataset_id"]
                    and row["neo4j_namespace"] == params["neo4j_namespace"]
                ]

        return callback(Tx())

    def run_write(self, request: Any, callback: Any) -> Any:
        raise AssertionError("dataset-scoped graph read tests must not write")


def _graph(executor: _ScopeAwareExecutor) -> Any:
    from infrastructure.graph.neo4j_graph import Neo4jGraph

    return Neo4jGraph(
        settings=SimpleNamespace(
            database=None,
            retry_attempts=1,
            log_latency=False,
            perf_log=False,
        ),
        query_executor=executor,
    )


class DatasetScopedGraphReadTests(unittest.TestCase):
    def test_graph_utils_disables_database_fallback_and_forwards_scope(self) -> None:
        from infrastructure.graph.graph_utils import fetch_related_terms

        with patch("infrastructure.graph.graph_utils.Neo4jGraph") as graph_type:
            graph_type.return_value.fetch_related.return_value = []

            result = fetch_related_terms(
                ["subject"],
                dataset_id="dataset-a",
                neo4j_namespace="graph-a",
                limit=7,
            )

        self.assertEqual(result, [])
        graph_type.assert_called_once_with(allow_database_fallback=False)
        graph_type.return_value.fetch_related.assert_called_once_with(
            ["subject"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            limit=7,
        )

    def test_two_dataset_fact_reads_bind_independent_scope_without_leakage(self) -> None:
        executor = _ScopeAwareExecutor()
        graph = _graph(executor)

        dataset_a = graph.fetch_related(
            ["subject"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
        )
        dataset_b = graph.fetch_related(
            ["subject"],
            dataset_id="dataset-b",
            neo4j_namespace="graph-b",
        )
        crossed_scope = graph.fetch_related(
            ["subject"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-b",
        )

        self.assertEqual([row["subject"] for row in dataset_a], ["A subject"])
        self.assertEqual([row["subject"] for row in dataset_b], ["B subject"])
        self.assertEqual(crossed_scope, [])
        self.assertEqual(
            [request.params["dataset_id"] for request in executor.requests],
            ["dataset-a", "dataset-b", "dataset-a"],
        )

        statement = executor.requests[0].statement
        self.assertIn("MATCH (s:Entity)-[r:REL]->(o:Entity)", statement)
        for alias in ("s", "o", "r"):
            self.assertIn(f"{alias}.dataset_id = $dataset_id", statement)
            self.assertIn(f"{alias}.neo4j_namespace = $neo4j_namespace", statement)

    def test_structural_paths_scope_every_node_and_relationship(self) -> None:
        executor = _ScopeAwareExecutor()
        graph = _graph(executor)

        results = graph.search_structural(
            ["subject"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            hops=3,
            include_rel_match=True,
        )

        self.assertEqual([row["subject"] for row in results], ["A subject"])
        request = executor.requests[0]
        self.assertEqual(request.params["dataset_id"], "dataset-a")
        self.assertEqual(request.params["neo4j_namespace"], "graph-a")
        self.assertIn("MATCH p=(s:Entity)-[r:REL*1..3]->(o:Entity)", request.statement)
        self.assertIn("all(node IN nodes(p) WHERE", request.statement)
        self.assertIn("all(rel IN relationships(p) WHERE", request.statement)
        self.assertIn("rel.dataset_id = $dataset_id", request.statement)
        self.assertIn("rel.neo4j_namespace = $neo4j_namespace", request.statement)

    def test_blank_scope_fails_before_any_graph_query(self) -> None:
        executor = _ScopeAwareExecutor()
        graph = _graph(executor)

        with self.assertRaisesRegex(ValueError, "dataset_id and neo4j_namespace"):
            graph.fetch_related(
                ["subject"],
                dataset_id="dataset-a",
                neo4j_namespace=" ",
            )

        self.assertEqual(executor.requests, [])


if __name__ == "__main__":
    unittest.main()
