"""Graph-read scenarios proving that dataset scope prevents cross-dataset leakage."""

from __future__ import annotations

import unittest
from types import SimpleNamespace
from typing import Any
from unittest.mock import patch


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
    from hawki_graph_store.graph import Neo4jGraph

    return Neo4jGraph(
        settings=SimpleNamespace(
            database=None,
            log_latency=False,
            perf_log=False,
        ),
        query_executor=executor,
    )


class DatasetScopedGraphReadTests(unittest.TestCase):
    """Verify every structural and fact lookup remains inside the trusted graph scope."""

    def test_graph_utils_disables_database_fallback_and_forwards_scope(self) -> None:
        from hawki_bridge.adapters.neo4j_reader import fetch_related_graph

        with patch("hawki_bridge.adapters.neo4j_reader.Neo4jGraph") as graph_type:
            graph_type.return_value.fetch_related.return_value = []

            result = fetch_related_graph(
                ["subject"],
                dataset_id="dataset-a",
                neo4j_namespace="graph-a",
                limit=7,
                excluded_doc_ids=["doc-pending"],
            )

        self.assertEqual(result, [])
        graph_type.assert_called_once_with(allow_database_fallback=False)
        graph_type.return_value.fetch_related.assert_called_once_with(
            ["subject"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            limit=7,
            excluded_doc_ids=["doc-pending"],
        )

    def test_graph_utils_defaults_to_no_exclusions_for_legacy_callers(self) -> None:
        from hawki_bridge.adapters.neo4j_reader import fetch_related_graph

        with patch("hawki_bridge.adapters.neo4j_reader.Neo4jGraph") as graph_type:
            graph_type.return_value.fetch_related.return_value = []

            fetch_related_graph(
                ["subject"],
                dataset_id="dataset-a",
                neo4j_namespace="graph-a",
                limit=7,
            )

        graph_type.return_value.fetch_related.assert_called_once_with(
            ["subject"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            limit=7,
            excluded_doc_ids=(),
        )

    def test_structural_hits_drop_rows_of_pending_deletion_documents(self) -> None:
        from hawki_bridge.adapters.neo4j_reader import build_structural_hits

        class _Graph:
            def __init__(self, *_args: Any, **_kwargs: Any) -> None:
                pass

            def search_structural(self, *_args: Any, **_kwargs: Any) -> list[Any]:
                return [
                    {
                        "subject": "Live subject",
                        "relation": "supports",
                        "object": "Live object",
                        "doc_id": "doc-live",
                        "hops": 1,
                    },
                    {
                        "subject": "Deleted subject",
                        "relation": "supported",
                        "object": "Deleted object",
                        "doc_id": "doc-pending",
                        "hops": 1,
                    },
                    {
                        "subject": "Legacy subject",
                        "relation": "relates",
                        "object": "Legacy object",
                        "doc_id": None,
                        "hops": 2,
                    },
                ]

            def close(self) -> None:
                return None

        with patch(
            "hawki_bridge.adapters.neo4j_reader.Neo4jGraph",
            side_effect=lambda *_args, **_kwargs: _Graph(),
        ):
            hits = build_structural_hits(
                ["subject"],
                dataset_id="dataset-a",
                neo4j_namespace="graph-a",
                limit=10,
                hops=2,
                include_rel_match=False,
                excluded_doc_ids=["doc-pending"],
            )

        self.assertEqual(
            [hit["payload"]["doc_id"] for hit in hits],
            ["doc-live", None],
        )
        self.assertTrue(
            all(hit["payload"]["dataset_id"] == "dataset-a" for hit in hits)
        )

    def test_two_dataset_fact_reads_bind_independent_scope_without_leakage(
        self,
    ) -> None:
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
