"""Incremental-ingestion scenarios from stable identity through scoped vector and graph writes."""

from __future__ import annotations

import logging
import unittest
from types import SimpleNamespace
from unittest.mock import patch


class IncrementalIngestTests(unittest.TestCase):
    """Verify retries, replacements, registries, and deletions preserve document isolation."""

    def test_vector_commit_deletes_replaced_docs_before_upsert(self) -> None:
        from hawki_indexer_worker.indexing.vector_commit import commit_vector_points

        class Provider:
            embed_model = "embed-test"

            def embed(self, text: str) -> list[float]:
                return [0.1, 0.2]

        class FakeQdrant:
            collection = "test_docs"

            def __init__(self) -> None:
                self.events: list[tuple[str, object]] = []

            def ensure_collection(self, vector_size: int, distance: str) -> None:
                self.events.append(
                    ("ensure", {"vector_size": vector_size, "distance": distance})
                )

            def delete_by_doc_id(
                self, doc_id: str, *, idempotency_key: str | None = None
            ) -> None:
                self.events.append(
                    ("delete", {"doc_id": doc_id, "idempotency_key": idempotency_key})
                )

            def upsert_points(
                self,
                points: list[dict[str, object]],
                *,
                batch_size: int,
                idempotency_key: str | None = None,
            ) -> None:
                self.events.append(
                    (
                        "upsert",
                        {
                            "points": points,
                            "batch_size": batch_size,
                            "idempotency_key": idempotency_key,
                        },
                    )
                )

        qdrant = FakeQdrant()
        result = commit_vector_points(
            body=SimpleNamespace(provider="fake", distance="Cosine"),
            chunk_records=[
                {
                    "doc_id": "doc-new",
                    "content": "changed page",
                    "payload": {
                        "doc_id": "doc-new",
                        "chunk_index": 0,
                        "content_hash": "new",
                    },
                }
            ],
            doc_stats={"processed_docs": 1, "skipped_docs": 0},
            provider=Provider(),
            qdrant=qdrant,
            batch_size=16,
            job_id="job-1",
            operation_id="op-1",
            replace_doc_ids={"old-doc", "doc-new"},
            logger_obj=logging.getLogger("test_vector_commit_incremental"),
        )

        self.assertEqual(
            [event[0] for event in qdrant.events],
            ["ensure", "delete", "delete", "upsert"],
        )
        self.assertEqual(qdrant.events[1][1]["doc_id"], "doc-new")
        self.assertEqual(qdrant.events[2][1]["doc_id"], "old-doc")
        self.assertEqual(qdrant.events[3][1]["batch_size"], 16)
        self.assertEqual(result.vector_size, 2)

    def test_graph_ingest_deletes_replaced_doc_ids_before_merge(self) -> None:
        from hawki_indexer_worker.indexing.graph_prepare import build_triplets_by_doc
        from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings

        class FakeGraph:
            def __init__(self) -> None:
                self.events: list[tuple[str, object]] = []

            def delete_by_doc_id(
                self, doc_id: str, *, request_id: str | None = None
            ) -> None:
                self.events.append(
                    ("delete", {"doc_id": doc_id, "request_id": request_id})
                )

            def upsert_triplets(
                self,
                triplets: list[tuple[str, str, str]],
                *,
                doc_id: str | None = None,
                request_id: str | None = None,
            ) -> None:
                self.events.append(
                    (
                        "upsert",
                        {
                            "triplets": triplets,
                            "doc_id": doc_id,
                            "request_id": request_id,
                        },
                    )
                )

        class FakeRAGService:
            def extract_triplets(
                self, text: str, engine: str, **kwargs: object
            ) -> list[tuple[str, str, str]]:
                return [("HAWKI", "uses", "Neo4j")]

        graph = FakeGraph()
        settings = GraphIngestSettings(
            graph_debug=False,
            graph_perf_log=False,
            graph_doc_timeout_s=0.0,
            graph_doc_max_chars=0,
            graph_doc_max_chunks=0,
        )

        with patch(
            "hawki_indexer_worker.indexing.graph_prepare.filter_triplets_to_source",
            side_effect=lambda triplets, text, graph_perf_log=False: triplets,
        ):
            build_triplets_by_doc(
                [
                    {
                        "doc_id": "doc-new",
                        "content": "HAWKI uses Neo4j.",
                        "payload": {"doc_id": "doc-new", "chunk_index": 0},
                    }
                ],
                "raganything",
                FakeRAGService(),
                provider=None,
                graph=graph,
                dataset_id="dataset-a",
                neo4j_namespace="graph-a",
                graph_settings=settings,
                request_id="op-1",
                replace_doc_ids_by_doc={"doc-new": {"old-doc", "doc-new"}},
            )

        self.assertEqual(
            [event[0] for event in graph.events], ["delete", "delete", "upsert"]
        )
        self.assertEqual(graph.events[0][1]["doc_id"], "doc-new")
        self.assertEqual(graph.events[1][1]["doc_id"], "old-doc")
        self.assertEqual(graph.events[2][1]["doc_id"], "doc-new")
