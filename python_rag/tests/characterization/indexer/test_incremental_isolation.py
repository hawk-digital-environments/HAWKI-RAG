"""Incremental-ingestion scenarios from stable identity through scoped vector and graph writes."""

from __future__ import annotations

import logging
import unittest
from types import SimpleNamespace


class IncrementalIngestTests(unittest.TestCase):
    """Verify retries, replacements, registries, and deletions preserve document isolation."""

    def test_three_documents_share_one_collection_and_delete_removes_only_target_document_points(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents
        from hawki_indexer_worker.indexing.deletion import delete_document_entries
        from hawki_indexer_worker.indexing.vector_commit import commit_vector_points

        class Provider:
            embed_model = "embed-test"

            def embed(self, text: str) -> list[float]:
                return [0.1, 0.2]

        class FakeQdrant:
            def __init__(self) -> None:
                self.collection = "default"
                self.points_by_collection: dict[str, list[dict[str, object]]] = {}

            def set_collection(self, collection: str) -> None:
                self.collection = collection

            def ensure_collection(self, vector_size: int, distance: str) -> None:
                self.points_by_collection.setdefault(self.collection, [])

            def upsert_points(
                self,
                points: list[dict[str, object]],
                *,
                batch_size: int,
                idempotency_key: str | None = None,
            ) -> None:
                bucket = self.points_by_collection.setdefault(self.collection, [])
                existing = {str(point["id"]): point for point in bucket}
                for point in points:
                    existing[str(point["id"])] = point
                self.points_by_collection[self.collection] = list(existing.values())

            def count_points_by_doc_id(
                self,
                doc_id: str,
                *,
                collection: str | None = None,
                exact: bool = True,
            ) -> int:
                bucket = self.points_by_collection.get(
                    collection or self.collection, []
                )
                return sum(
                    1
                    for point in bucket
                    if str((point.get("payload") or {}).get("doc_id") or "") == doc_id
                )

            def delete_by_doc_id(
                self, doc_id: str, *, idempotency_key: str | None = None
            ) -> dict[str, object]:
                bucket = self.points_by_collection.get(self.collection, [])
                kept = [
                    point
                    for point in bucket
                    if str((point.get("payload") or {}).get("doc_id") or "") != doc_id
                ]
                self.points_by_collection[self.collection] = kept
                return {"result": {"status": "completed"}}

            def total_points(self, collection: str) -> int:
                return len(self.points_by_collection.get(collection, []))

        graph_state: dict[str, set[str]] = {}

        class FakeGraph:
            def __init__(
                self,
                *,
                database: str | None = None,
                neo4j_namespace: str | None = None,
            ) -> None:
                self.database = neo4j_namespace or database or "default"
                graph_state.setdefault(self.database, set())

            def upsert_triplets(
                self,
                triplets: list[tuple[str, str, str]],
                *,
                doc_id: str | None = None,
                request_id: str | None = None,
            ) -> None:
                if doc_id:
                    graph_state[self.database].add(doc_id)

            def delete_by_doc_id(
                self, doc_id: str, *, request_id: str | None = None
            ) -> dict[str, int]:
                graph_state[self.database].discard(doc_id)
                return {"relationships_deleted": 1, "entities_deleted": 1}

            def close(self) -> None:
                return None

        qdrant = FakeQdrant()
        qdrant.set_collection("student_space")
        graph_writer = FakeGraph(database="student_graph")

        docs = [
            SimpleNamespace(
                id="transient-a",
                text="Alpha section. " * 20,
                payload={
                    "title": "alpha.pdf",
                    "source_url": "upload://alpha.pdf",
                    "source_format": "markdown",
                    "dataset_id": "student-dataset",
                    "managed_document_id": "adoc-alpha",
                    "source_id": "source-alpha",
                },
            ),
            SimpleNamespace(
                id="transient-b",
                text="Beta section. " * 24,
                payload={
                    "title": "beta.pdf",
                    "source_url": "upload://beta.pdf",
                    "source_format": "markdown",
                    "dataset_id": "student-dataset",
                    "managed_document_id": "adoc-beta",
                    "source_id": "source-beta",
                },
            ),
            SimpleNamespace(
                id="transient-c",
                text="Gamma section. " * 18,
                payload={
                    "title": "gamma.pdf",
                    "source_url": "upload://gamma.pdf",
                    "source_format": "markdown",
                    "dataset_id": "student-dataset",
                    "managed_document_id": "adoc-gamma",
                    "source_id": "source-gamma",
                },
            ),
        ]

        chunk_records, doc_stats = prepare_documents(
            docs,
            chunk_chars=40,
            chunk_overlap=0,
            default_job_id="job-student",
        )

        result = commit_vector_points(
            body=SimpleNamespace(provider="fake", distance="Cosine"),
            chunk_records=chunk_records,
            doc_stats=doc_stats,
            provider=Provider(),
            qdrant=qdrant,
            batch_size=64,
            job_id="job-student",
            operation_id="op-student",
            logger_obj=logging.getLogger("test_student_collection_delete"),
        )

        self.assertGreater(len(result.points), 3)
        doc_ids_by_assistant = {
            str(record["payload"]["managed_document_id"]): str(record["doc_id"])
            for record in chunk_records
        }
        target_doc_id = doc_ids_by_assistant["adoc-beta"]
        kept_doc_ids = {
            doc_ids_by_assistant["adoc-alpha"],
            doc_ids_by_assistant["adoc-gamma"],
        }

        for point in result.points:
            payload = point["payload"]
            self.assertEqual(payload["dataset_id"], "student-dataset")
            self.assertIn(
                payload["managed_document_id"],
                {"adoc-alpha", "adoc-beta", "adoc-gamma"},
            )
            self.assertTrue(str(payload["source_id"]).startswith("source-"))
            self.assertTrue(str(payload["doc_id"]))
            graph_writer.upsert_triplets(
                [("Student", "uploaded", str(payload["managed_document_id"]))],
                doc_id=str(payload["doc_id"]),
            )

        target_points_before = qdrant.count_points_by_doc_id(
            target_doc_id, collection="student_space"
        )
        kept_points_before = {
            doc_id: qdrant.count_points_by_doc_id(doc_id, collection="student_space")
            for doc_id in kept_doc_ids
        }

        delete_result = delete_document_entries(
            target_doc_id,
            idempotency_key="delete-student-doc",
            collection="student_space",
            neo4j_namespace="student_graph",
            qdrant_factory=lambda: qdrant,
            graph_factory=FakeGraph,
        )

        self.assertEqual(delete_result["qdrant"]["collection"], "student_space")
        self.assertEqual(
            delete_result["qdrant"]["deleted_points"], target_points_before
        )
        self.assertEqual(delete_result["neo4j"]["namespace"], "student_graph")
        self.assertEqual(
            qdrant.count_points_by_doc_id(target_doc_id, collection="student_space"), 0
        )
        for doc_id, count in kept_points_before.items():
            self.assertEqual(
                qdrant.count_points_by_doc_id(doc_id, collection="student_space"), count
            )
        self.assertEqual(
            qdrant.total_points("student_space"), sum(kept_points_before.values())
        )
        self.assertNotIn(target_doc_id, graph_state["student_graph"])
        self.assertTrue(
            all(doc_id in graph_state["student_graph"] for doc_id in kept_doc_ids)
        )


if __name__ == "__main__":
    unittest.main()
