"""Indexer request and document-deletion characterization scenarios."""

from __future__ import annotations

import unittest


class IndexRequestCharacterizationTests(unittest.TestCase):
    def test_graph_index_request_requires_scope_and_preserves_defaults(self) -> None:
        from hawki_indexer_worker.domain.errors import IndexingValidationError
        from hawki_indexer_worker.domain.models import IngestDocument
        from hawki_indexer_worker.indexing.request import IndexRequest

        with self.assertRaises(IndexingValidationError):
            IndexRequest(
                docs=[IngestDocument(id="doc-1", text="Toy blocks")],
                graph=True,
                collection="toy_docs",
            )

        request = IndexRequest(
            docs=[
                IngestDocument(
                    id="doc-1",
                    text="Toy blocks",
                    payload={"title": "Toys"},
                )
            ],
            provider="fake",
            collection="toy_docs",
            dataset_id="dataset-a",
            neo4j_namespace="hawki_dataset_a",
            graph=True,
        )

        self.assertEqual(request.docs[0].id, "doc-1")
        self.assertEqual(request.docs[0].payload, {"title": "Toys"})
        self.assertEqual(request.provider, "fake")
        self.assertEqual(request.collection, "toy_docs")
        self.assertEqual(request.chunk_chars, 1200)
        self.assertEqual(request.chunk_overlap, 250)
        self.assertTrue(request.graph)

    def test_index_request_is_built_from_workflow_input_without_http(self) -> None:
        from hawki_indexer_worker.domain.models import IngestDocument
        from hawki_indexer_worker.indexing.request import IndexRequest

        request = IndexRequest.from_options(
            [
                IngestDocument(
                    id="doc-toy-1",
                    text="Wooden trains and blocks.",
                    payload={"title": "Toys"},
                )
            ],
            workflow_input={
                "dataset_id": "dataset-a",
                "job_id": "job-1",
            },
            options={
                "provider": "ollama",
                "embedding_model": "bge-m3",
                "collection": "hawki_dataset_a",
                "neo4j_namespace": "hawki_dataset_a",
                "graph": True,
            },
            operation_id="workflow-op-1",
        )

        self.assertEqual(request.docs[0].id, "doc-toy-1")
        self.assertEqual(request.dataset_id, "dataset-a")
        self.assertEqual(request.collection, "hawki_dataset_a")
        self.assertEqual(request.neo4j_namespace, "hawki_dataset_a")
        self.assertEqual(request.idempotency_key, "workflow-op-1")
        self.assertEqual(request.job_id, "job-1")
        self.assertTrue(request.graph)

    def test_document_delete_contract_is_owned_by_the_indexer(self) -> None:
        from hawki_indexer_worker.indexing.deletion import delete_document_entries

        class FakeQdrant:
            def __init__(self) -> None:
                self.collection = "default"
                self.calls: list[tuple[str, str | None]] = []

            def set_collection(self, collection: str) -> None:
                self.collection = collection

            def count_points_by_doc_id(
                self,
                doc_id: str,
                *,
                collection: str | None = None,
                exact: bool = True,
            ) -> int:
                assert doc_id == "doc-replace-1"
                assert collection == "toy_docs"
                assert exact is True
                return 2

            def delete_by_doc_id(
                self,
                doc_id: str,
                *,
                idempotency_key: str | None = None,
            ) -> dict[str, str]:
                self.calls.append((doc_id, idempotency_key))
                return {"status": "ok"}

        graph_instances = []

        class FakeGraph:
            def __init__(
                self,
                *,
                database: str | None = None,
                dataset_id: str | None = None,
                neo4j_namespace: str | None = None,
            ) -> None:
                self.database = database
                self.dataset_id = dataset_id
                self.neo4j_namespace = neo4j_namespace
                self.calls: list[tuple[str, str | None]] = []
                self.closed = False
                graph_instances.append(self)

            def delete_by_doc_id(
                self,
                doc_id: str,
                *,
                request_id: str | None = None,
            ) -> dict[str, int]:
                self.calls.append((doc_id, request_id))
                return {"relationships_deleted": 3, "entities_deleted": 1}

            def close(self) -> None:
                self.closed = True

        qdrant = FakeQdrant()
        response = delete_document_entries(
            "doc-replace-1",
            idempotency_key="delete-op-1",
            collection="toy_docs",
            neo4j_namespace="toy_graph",
            vector_writer_factory=lambda: qdrant,
            graph_writer_factory=FakeGraph,
        )

        self.assertEqual(
            response,
            {
                "qdrant": {
                    "doc_id": "doc-replace-1",
                    "collection": "toy_docs",
                    "deleted_points": 2,
                    "result": {"status": "ok"},
                },
                "neo4j": {
                    "doc_id": "doc-replace-1",
                    "namespace": "toy_graph",
                    "relationships_deleted": 3,
                    "entities_deleted": 1,
                },
            },
        )
        self.assertEqual(qdrant.calls, [("doc-replace-1", "delete-op-1")])
        self.assertEqual(graph_instances[0].calls, [("doc-replace-1", "delete-op-1")])
        self.assertTrue(graph_instances[0].closed)
