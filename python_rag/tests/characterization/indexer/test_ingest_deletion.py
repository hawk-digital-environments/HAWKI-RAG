"""Ingestion scenarios from request validation through vector, graph, retry, and deletion workflows."""

from __future__ import annotations

import unittest
from types import MappingProxyType


class IngestDeletionCharacterizationTests(unittest.TestCase):
    """Verify document deletion remains scoped across vector and graph storage."""

    def test_delete_document_entries_accepts_read_only_mapping_results(self) -> None:
        from hawki_indexer_worker.indexing.deletion import delete_document_entries

        class Qdrant:
            collection = "student_space"

            def delete_by_doc_id(
                self,
                doc_id: str,
                *,
                idempotency_key: str | None = None,
            ) -> object:
                return MappingProxyType(
                    {
                        "result": MappingProxyType({"deleted": 3}),
                    }
                )

        class Graph:
            def delete_by_doc_id(
                self,
                doc_id: str,
                *,
                request_id: str | None = None,
            ) -> object:
                return MappingProxyType(
                    {
                        "relationships_deleted": "2",
                        "entities_deleted": 1,
                    }
                )

            def close(self) -> None:
                return None

        result = delete_document_entries(
            "doc-read-only-result",
            qdrant_factory=Qdrant,
            graph_factory=Graph,
        )

        self.assertEqual(result["qdrant"]["deleted_points"], 3)
        self.assertEqual(result["neo4j"]["relationships_deleted"], 2)
        self.assertEqual(result["neo4j"]["entities_deleted"], 1)

    def test_delete_document_entries_scopes_vector_and_graph_delete_then_closes_graph(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.deletion import delete_document_entries

        events: list[tuple[str, object]] = []

        class Qdrant:
            collection = "default"

            def set_collection(self, collection: str) -> None:
                self.collection = collection
                events.append(("qdrant_collection", collection))

            def count_points_by_doc_id(
                self, doc_id: str, *, collection: str | None = None, exact: bool = True
            ) -> int:
                events.append(
                    (
                        "qdrant_count",
                        {"doc_id": doc_id, "collection": collection, "exact": exact},
                    )
                )
                return 4

            def delete_by_doc_id(
                self, doc_id: str, *, idempotency_key: str | None = None
            ) -> dict[str, object]:
                events.append(
                    (
                        "qdrant",
                        {
                            "doc_id": doc_id,
                            "idempotency_key": idempotency_key,
                            "collection": self.collection,
                        },
                    )
                )
                return {"result": {"status": "completed"}}

        class Graph:
            def __init__(
                self,
                *,
                database: str | None = None,
                neo4j_namespace: str | None = None,
            ) -> None:
                self.database = database
                self.neo4j_namespace = neo4j_namespace
                events.append(
                    (
                        "graph_scope",
                        {"database": database, "neo4j_namespace": neo4j_namespace},
                    )
                )

            def delete_by_doc_id(
                self, doc_id: str, *, request_id: str | None = None
            ) -> dict[str, int]:
                events.append(
                    (
                        "graph",
                        {
                            "doc_id": doc_id,
                            "request_id": request_id,
                            "database": self.database,
                            "neo4j_namespace": self.neo4j_namespace,
                        },
                    )
                )
                return {"relationships_deleted": 2, "entities_deleted": 1}

            def close(self) -> None:
                events.append(("graph_close", ""))

        result = delete_document_entries(
            "doc-1",
            idempotency_key="delete-op-1",
            collection="student_space",
            neo4j_namespace="student_graph",
            qdrant_factory=Qdrant,
            graph_factory=Graph,
        )

        self.assertEqual(
            result,
            {
                "qdrant": {
                    "doc_id": "doc-1",
                    "collection": "student_space",
                    "deleted_points": 4,
                    "result": {"result": {"status": "completed"}},
                },
                "neo4j": {
                    "doc_id": "doc-1",
                    "namespace": "student_graph",
                    "relationships_deleted": 2,
                    "entities_deleted": 1,
                },
            },
        )
        self.assertEqual(
            events,
            [
                ("qdrant_collection", "student_space"),
                (
                    "qdrant_count",
                    {"doc_id": "doc-1", "collection": "student_space", "exact": True},
                ),
                (
                    "qdrant",
                    {
                        "doc_id": "doc-1",
                        "idempotency_key": "delete-op-1",
                        "collection": "student_space",
                    },
                ),
                ("graph_scope", {"database": None, "neo4j_namespace": "student_graph"}),
                (
                    "graph",
                    {
                        "doc_id": "doc-1",
                        "request_id": "delete-op-1",
                        "database": None,
                        "neo4j_namespace": "student_graph",
                    },
                ),
                ("graph_close", ""),
            ],
        )
