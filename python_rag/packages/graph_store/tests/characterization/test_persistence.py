"""Graph characterization scenarios from extraction through dataset-scoped Neo4j persistence."""

from __future__ import annotations

import unittest
from types import SimpleNamespace

from neo4j.exceptions import ServiceUnavailable


class Neo4jCharacterizationTests(unittest.TestCase):
    """Protect parsing, scoped writes, managed retries, and executor injection."""

    def test_neo4j_response_parsing_is_robust(self) -> None:
        from hawki_graph_store.responses import (
            parse_count,
            parse_fact_rows,
            parse_label_counts,
            parse_relation_counts,
            parse_structural_rows,
        )

        class Row:
            def __init__(self, values: dict[str, object]):
                self._values = values

            def get(self, key: str, default: object | None = None) -> object:
                return self._values.get(key, default)

        self.assertEqual(parse_count(None), 0)
        self.assertEqual(
            parse_count(Row({"c": "7"})),
            7,
        )
        self.assertEqual(
            parse_relation_counts(
                [
                    Row({"rel_type": "USES", "count": "2"}),
                    Row({"rel_type": "WROTE", "count": 1}),
                ]
            ),
            [{"type": "USES", "count": 2}, {"type": "WROTE", "count": 1}],
        )
        self.assertEqual(
            parse_label_counts(
                [
                    Row({"labels": ("A", "B"), "count": 1}),
                    Row({"labels": [], "count": 0}),
                ]
            ),
            [{"labels": ["A", "B"], "count": 1}, {"labels": [], "count": 0}],
        )
        self.assertEqual(
            parse_fact_rows(
                [
                    Row({"subject": "S", "relation": "R", "object": "O"}),
                    Row({"subject": "O", "relation": "R,", "object": "S"}),
                    Row({"subject": "A", "relation": "equivalent_to,", "object": "B"}),
                    Row({"subject": "B", "relation": "equivalent", "object": "A"}),
                    Row({"subject": "A", "relation": "synonym", "object": "B"}),
                    Row({"subject": "bad", "relation": "R"}),
                ]
            ),
            [
                {"subject": "S", "relation": "R", "object": "O"},
                {"subject": "A", "relation": "equivalent", "object": "B"},
                {"subject": "A", "relation": "synonym", "object": "B"},
            ],
        )
        self.assertEqual(
            parse_structural_rows(
                [
                    Row(
                        {
                            "subject": "S",
                            "relation": "R",
                            "object": "O",
                            "doc_id": "d",
                            "hops": "3",
                        }
                    ),
                    Row(
                        {
                            "subject": "S2",
                            "relation": "R2",
                            "object": "O2",
                            "hops": None,
                        }
                    ),
                ]
            ),
            [
                {
                    "subject": "S",
                    "relation": "R",
                    "object": "O",
                    "doc_id": "d",
                    "hops": 3,
                },
                {
                    "subject": "S2",
                    "relation": "R2",
                    "object": "O2",
                    "doc_id": None,
                    "hops": 1,
                },
            ],
        )

    def test_upsert_triplets_builds_doc_scoped_rows_for_neo4j(self) -> None:
        from hawki_graph_store.graph import Neo4jGraph

        calls: list[tuple[str, dict]] = []

        class Tx:
            def run(self, cypher: str, **params):
                calls.append((cypher, params))
                return SimpleNamespace(consume=lambda: None)

        class Session:
            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def execute_write(self, callback):
                return callback(Tx())

        graph = object.__new__(Neo4jGraph)
        graph._database = None
        graph._session = lambda: Session()

        graph.upsert_triplets(
            [("HAWKI", "USES", "Qdrant"), ("HAWKI", "PERSISTS", "Neo4j")],
            doc_id="doc-1",
            dataset_id="dataset-a",
            neo4j_namespace="hawki_dataset_a",
        )

        self.assertEqual(len(calls), 1)
        cypher, params = calls[0]
        self.assertIn(
            "MERGE (s:Entity {entity_key: row.s_key, dataset_id: row.dataset_id, neo4j_namespace: row.neo4j_namespace})",
            cypher,
        )
        self.assertEqual(
            params["rows"],
            [
                {
                    "s": "HAWKI",
                    "s_key": "hawki",
                    "r": "USES",
                    "o": "Qdrant",
                    "o_key": "qdrant",
                    "doc_id": "doc-1",
                    "dataset_id": "dataset-a",
                    "neo4j_namespace": "hawki_dataset_a",
                },
                {
                    "s": "HAWKI",
                    "s_key": "hawki",
                    "r": "PERSISTS",
                    "o": "Neo4j",
                    "o_key": "neo4j",
                    "doc_id": "doc-1",
                    "dataset_id": "dataset-a",
                    "neo4j_namespace": "hawki_dataset_a",
                },
            ],
        )

    def test_upsert_triplet_rows_use_case_insensitive_entity_keys(self) -> None:
        from hawki_graph_store.requests import build_triplet_rows

        self.assertEqual(
            build_triplet_rows(
                [
                    ("Rrolf", "mentions", "RAG-System"),
                    ("rrolf", "mentions", "Rag System"),
                ],
                "doc-1",
                dataset_id="dataset-a",
                neo4j_namespace="hawki_dataset_a",
            ),
            [
                {
                    "s": "Rrolf",
                    "s_key": "rrolf",
                    "r": "mentions",
                    "o": "RAG-System",
                    "o_key": "rag system",
                    "doc_id": "doc-1",
                    "dataset_id": "dataset-a",
                    "neo4j_namespace": "hawki_dataset_a",
                },
                {
                    "s": "rrolf",
                    "s_key": "rrolf",
                    "r": "mentions",
                    "o": "Rag System",
                    "o_key": "rag system",
                    "doc_id": "doc-1",
                    "dataset_id": "dataset-a",
                    "neo4j_namespace": "hawki_dataset_a",
                },
            ],
        )

    def test_neo4j_query_executor_does_not_wrap_managed_transaction_retries(
        self,
    ) -> None:
        from hawki_graph_store.requests import Neo4jQueryRequest
        from hawki_graph_store.transport import Neo4jQueryExecutor

        execute_read_calls: list[int] = []

        class Session:
            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def execute_read(self, callback):
                execute_read_calls.append(1)
                raise ServiceUnavailable("driver retry window exhausted")

        executor = Neo4jQueryExecutor(
            session_factory=Session,
            log_latency=False,
        )
        with self.assertRaises(ServiceUnavailable):
            executor.run_read(
                Neo4jQueryRequest("RETURN 1", {}),
                callback=lambda _tx: "unreachable",
            )

        self.assertEqual(execute_read_calls, [1])

    def test_neo4j_graph_accepts_injected_query_executor(self) -> None:
        from hawki_graph_store.graph import Neo4jGraph

        class FakeExecutor:
            def __init__(self) -> None:
                self.read_calls = 0
                self.write_calls = 0
                self.statements: list[str] = []

            def run_read(self, request, callback):
                self.read_calls += 1
                self.statements.append(request.statement)

                class Tx:
                    def run(
                        self, _statement: str, **_params: str
                    ) -> list[dict[str, str]]:
                        return [{"subject": "A", "relation": "R", "object": "B"}]

                return callback(Tx())

            def run_write(self, request, callback):
                self.write_calls += 1
                self.statements.append(request.statement)

                class Tx:
                    def run(self, statement: str, **_params: str):
                        return SimpleNamespace(consume=lambda: {"statement": statement})

                return callback(Tx())

        executor = FakeExecutor()
        graph = Neo4jGraph(
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            settings=SimpleNamespace(database=None, log_latency=False, perf_log=False),
            query_executor=executor,  # type: ignore[arg-type]
        )
        fetch_result = graph.fetch_related(
            ["toy"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
        )
        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-1")

        self.assertEqual(
            fetch_result, [{"subject": "A", "relation": "R", "object": "B"}]
        )
        self.assertEqual(executor.read_calls, 1)
        self.assertEqual(executor.write_calls, 1)
        self.assertTrue(
            any("UNWIND $rows" in statement for statement in executor.statements)
        )
