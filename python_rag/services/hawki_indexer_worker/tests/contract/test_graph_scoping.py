"""Graph-write scenarios proving incomplete or conflicting dataset scope fails closed."""

from __future__ import annotations

import tempfile
import unittest
from types import SimpleNamespace


class GraphWriteScopingTests(unittest.TestCase):
    """Verify Neo4j writes and deletes always carry the authorized logical namespace."""

    def test_incomplete_scope_never_dispatches_a_canonical_write(self) -> None:
        from hawki_graph_store.graph import Neo4jGraph
        from hawki_graph_store.requests import build_triplet_rows

        class Executor:
            def __init__(self) -> None:
                self.requests: list[object] = []

            def run_write(self, request, callback):
                self.requests.append(request)
                raise AssertionError("an incomplete scope must not reach Neo4j")

        executor = Executor()
        graph = Neo4jGraph(
            dataset_id="dataset-a",
            settings=SimpleNamespace(database=None, log_latency=False, perf_log=False),
            query_executor=executor,  # type: ignore[arg-type]
        )

        graph.upsert_triplets([("HAWKI", "uses", "Neo4j")], doc_id="doc-1")

        self.assertEqual(executor.requests, [])
        self.assertEqual(
            build_triplet_rows(
                [("HAWKI", "uses", "Neo4j")],
                "doc-1",
                dataset_id="dataset-a",
                neo4j_namespace=None,
            ),
            [],
        )

    def test_conflicting_payload_scope_fails_before_vector_commit(self) -> None:
        from hawki_indexer_worker.domain.errors import IndexingValidationError
        from hawki_indexer_worker.indexing.dependencies import (
            IngestWorkflowDependencies,
        )
        from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
        from hawki_indexer_worker.indexing.orchestration import ingest_documents

        class Qdrant:
            collection = "default"

            def __init__(self) -> None:
                self.vector_writes = 0

            def set_collection(self, collection: str) -> None:
                self.collection = collection

            def ensure_collection(self, *_args, **_kwargs) -> None:
                self.vector_writes += 1

            def upsert_points(self, *_args, **_kwargs) -> None:
                self.vector_writes += 1

        qdrant = Qdrant()
        body = SimpleNamespace(
            docs=[
                SimpleNamespace(
                    id="doc-1",
                    text="HAWKI uses Neo4j.",
                    payload={"dataset_id": "dataset-b", "neo4j_namespace": "graph-a"},
                )
            ],
            dry_run=False,
            provider="fake",
            collection="dataset_a",
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            neo4j_database=None,
            graph=True,
            graph_engine="raganything",
            graph_only=False,
            chunk_chars=1200,
            chunk_overlap=0,
            batch_size=64,
            distance="Cosine",
            embedding_model=None,
            graph_model=None,
            idempotency_key="op-1",
            job_id="job-1",
        )
        dependencies = IngestWorkflowDependencies(
            vector_writer_factory=lambda: qdrant,
            graph_writer_factory=lambda **_kwargs: None,
            page_state_factory=lambda _writer: None,
            graph_settings_loader=lambda: GraphIngestSettings(
                graph_debug=False,
                graph_perf_log=False,
                graph_doc_timeout_s=0.0,
                graph_doc_max_chars=0,
                graph_doc_max_chunks=0,
            ),
        )

        with tempfile.TemporaryDirectory():
            with self.assertRaises(IndexingValidationError) as raised:
                ingest_documents(
                    body,
                    rag_service=object(),
                    get_provider=lambda _name: (_ for _ in ()).throw(
                        AssertionError(
                            "provider resolution must happen after scope validation"
                        )
                    ),
                    dependencies=dependencies,
                )

        self.assertIsInstance(raised.exception, IndexingValidationError)
        self.assertIn("conflicts with trusted dataset_id", str(raised.exception))
        self.assertEqual(qdrant.vector_writes, 0)

    def test_default_graph_factory_receives_physical_and_logical_scope_separately(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.graph_commit import _create_graph

        captured: dict[str, object] = {}

        def keyword_only_factory(
            *,
            database: str | None = None,
            dataset_id: str | None = None,
            neo4j_namespace: str | None = None,
        ) -> object:
            captured.update(
                {
                    "database": database,
                    "dataset_id": dataset_id,
                    "neo4j_namespace": neo4j_namespace,
                }
            )
            return object()

        _create_graph(
            keyword_only_factory,
            database=None,
            dataset_id="dataset-a",
            neo4j_namespace="hawki_dataset_a",
        )

        self.assertEqual(
            captured,
            {
                "database": None,
                "dataset_id": "dataset-a",
                "neo4j_namespace": "hawki_dataset_a",
            },
        )

    def test_document_delete_queries_are_constrained_to_logical_namespace(self) -> None:
        from hawki_graph_store.graph import Neo4jGraph

        class Counters:
            relationships_deleted = 1
            nodes_deleted = 1

        class Result:
            counters = Counters()

            def single(self):
                return {"c": 1}

            def consume(self):
                return self

        class Tx:
            def run(self, _statement: str, **_params: object) -> Result:
                return Result()

        class Executor:
            def __init__(self) -> None:
                self.requests: list[object] = []

            def run_write(self, request, callback):
                self.requests.append(request)
                return callback(Tx())

        executor = Executor()
        graph = Neo4jGraph(
            neo4j_namespace="hawki_dataset_a",
            settings=SimpleNamespace(database=None, log_latency=False, perf_log=False),
            query_executor=executor,  # type: ignore[arg-type]
        )

        result = graph.delete_by_doc_id("doc-1", request_id="delete-1")

        self.assertEqual(result, {"relationships_deleted": 1, "entities_deleted": 1})
        self.assertEqual(len(executor.requests), 3)
        for request in executor.requests:
            self.assertEqual(request.params["neo4j_namespace"], "hawki_dataset_a")
            self.assertIn("neo4j_namespace", request.statement)


if __name__ == "__main__":
    unittest.main()
