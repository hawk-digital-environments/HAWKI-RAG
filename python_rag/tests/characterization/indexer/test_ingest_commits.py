"""Ingestion scenarios from request validation through vector, graph, retry, and deletion workflows."""

from __future__ import annotations

import logging
import tempfile
import time
import unittest
from types import SimpleNamespace


class IngestCharacterizationTests(unittest.TestCase):
    """Describe validation, dry runs, vector and graph commits, and final ingestion summaries."""

    def test_vector_commit_helper_records_partial_embedding_failures_and_upserts_points(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.vector_commit import commit_vector_points

        class Provider:
            def embed(self, text: str) -> list[float]:
                if "broken" in text:
                    raise RuntimeError("embedding unavailable")
                return [0.1, 0.2]

        class FakeQdrant:
            def __init__(self) -> None:
                self.collection = "toy_docs"
                self.ensure_calls: list[dict[str, object]] = []
                self.upsert_calls: list[dict[str, object]] = []

            def ensure_collection(self, vector_size: int, distance: str) -> None:
                self.ensure_calls.append(
                    {"vector_size": vector_size, "distance": distance}
                )

            def upsert_points(
                self,
                points: list[dict[str, object]],
                *,
                batch_size: int,
                idempotency_key: str | None = None,
            ) -> None:
                self.upsert_calls.append(
                    {
                        "points": points,
                        "batch_size": batch_size,
                        "idempotency_key": idempotency_key,
                    }
                )

        doc_stats: dict[str, object] = {
            "processed_docs": 2,
            "skipped_docs": 0,
            "doc_ids": ["toy-good", "toy-broken"],
            "chunks_per_doc": {"toy-good": 1, "toy-broken": 1},
        }
        chunk_records = [
            {
                "doc_id": "toy-good",
                "content": "working toy text",
                "payload": {
                    "doc_id": "toy-good",
                    "chunk_index": 0,
                    "title": "Good Toy",
                },
            },
            {
                "doc_id": "toy-broken",
                "content": "broken toy text",
                "payload": {
                    "doc_id": "toy-broken",
                    "chunk_index": 0,
                    "title": "Broken Toy",
                },
            },
        ]
        qdrant = FakeQdrant()

        result = commit_vector_points(
            body=SimpleNamespace(provider="fake", distance="Cosine"),
            chunk_records=chunk_records,
            doc_stats=doc_stats,
            provider=Provider(),
            qdrant=qdrant,
            batch_size=32,
            job_id="job-toy",
            operation_id="operation-toy",
            logger_obj=logging.getLogger("test_vector_commit_helper"),
        )

        self.assertEqual(result.vector_size, 2)
        self.assertEqual(len(result.points), 1)
        self.assertGreaterEqual(result.qdrant_ms, 0)
        self.assertEqual(
            qdrant.ensure_calls, [{"vector_size": 2, "distance": "Cosine"}]
        )
        self.assertEqual(qdrant.upsert_calls[0]["batch_size"], 32)
        self.assertEqual(qdrant.upsert_calls[0]["idempotency_key"], "operation-toy")
        self.assertEqual(doc_stats["embedding_failed_chunks"], 1)
        self.assertEqual(doc_stats["embedding_failed_docs"], 1)
        self.assertEqual(doc_stats["embedding_skipped_docs"], 1)
        self.assertEqual(doc_stats["processed_docs"], 1)
        self.assertEqual(doc_stats["skipped_docs"], 1)
        self.assertEqual(doc_stats["doc_ids"], ["toy-good"])

    def test_graph_commit_helper_extracts_upserts_and_closes_graph(self) -> None:
        from hawki_indexer_worker.indexing.graph_commit import commit_graph_triplets
        from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings

        class FakeGraph:
            def __init__(self) -> None:
                self.upserts: list[dict[str, object]] = []
                self.closed = False

            def upsert_triplets(
                self,
                triplets: list[tuple[str, str, str]],
                *,
                doc_id: str | None = None,
                request_id: str | None = None,
                dataset_id: str | None = None,
                neo4j_namespace: str | None = None,
            ) -> None:
                self.upserts.append(
                    {
                        "triplets": triplets,
                        "doc_id": doc_id,
                        "request_id": request_id,
                        "dataset_id": dataset_id,
                        "neo4j_namespace": neo4j_namespace,
                    }
                )

            def close(self) -> None:
                self.closed = True

        class FakeRAGService:
            def extract_triplets(
                self, text: str, engine: str, **kwargs: object
            ) -> list[tuple[str, str, str]]:
                calls["engine"] = engine
                calls["provider"] = kwargs["provider"]
                calls["doc_id"] = kwargs["doc_id"]
                calls["neo4j_database"] = kwargs["neo4j_database"]
                return [("Wooden train", "is", "Toy")]

        graph = FakeGraph()
        provider = SimpleNamespace(rag_model="rag", embed_model="embed")
        calls: dict[str, object] = {"graph_database": None}
        doc_stats = {
            "processed_docs": 1,
            "total_chunks": 1,
            "chunks_per_doc": {"toy-doc": 1},
        }
        chunk_records = [
            {
                "doc_id": "toy-doc",
                "content": "A wooden train is a toy for children.",
                "payload": {
                    "doc_id": "toy-doc",
                    "title": "Toy catalog",
                    "source_url": "upload://toy.md",
                },
            }
        ]
        settings = GraphIngestSettings(
            graph_debug=False,
            graph_perf_log=False,
            graph_doc_timeout_s=0.0,
            graph_doc_max_chars=0,
            graph_doc_max_chunks=0,
        )

        def graph_factory(
            database: str | None,
            *,
            dataset_id: str | None = None,
            neo4j_namespace: str | None = None,
        ) -> FakeGraph:
            calls["graph_database"] = database
            calls["dataset_id"] = dataset_id
            calls["neo4j_namespace"] = neo4j_namespace
            return graph

        with tempfile.TemporaryDirectory():
            result = commit_graph_triplets(
                body=SimpleNamespace(
                    graph_engine="raganything",
                    dataset_id="dataset-toy",
                    neo4j_namespace="toy-graph",
                    neo4j_database="neo4j",
                ),
                chunk_records=chunk_records,
                doc_stats=doc_stats,
                rag_service=FakeRAGService(),
                provider=provider,
                graph_factory=graph_factory,
                job_id="job-toy",
                operation_id="operation-toy",
                graph_debug=False,
                graph_settings=settings,
                logger_obj=logging.getLogger("test_graph_commit_helper"),
            )

        self.assertEqual(calls["graph_database"], "neo4j")
        self.assertEqual(calls["dataset_id"], "dataset-toy")
        self.assertEqual(calls["neo4j_namespace"], "toy-graph")
        self.assertEqual(calls["engine"], "raganything")
        self.assertIs(calls["provider"], provider)
        self.assertEqual(calls["doc_id"], "toy-doc")
        self.assertEqual(calls["neo4j_database"], "neo4j")
        self.assertTrue(graph.closed)
        self.assertEqual(graph.upserts[0]["doc_id"], "toy-doc")
        self.assertEqual(graph.upserts[0]["request_id"], "operation-toy")
        self.assertEqual(graph.upserts[0]["dataset_id"], "dataset-toy")
        self.assertEqual(graph.upserts[0]["neo4j_namespace"], "toy-graph")
        self.assertEqual(chunk_records[0]["payload"]["dataset_id"], "dataset-toy")
        self.assertEqual(chunk_records[0]["payload"]["neo4j_namespace"], "toy-graph")
        self.assertEqual(graph.upserts[0]["triplets"], [("Wooden train", "is", "Toy")])
        self.assertIsNotNone(result.graph_preview)
        assert result.graph_preview is not None
        self.assertEqual(result.graph_preview["total_triplets"], 1)
        self.assertGreaterEqual(result.neo4j_ms or 0, 0)

    def test_finalize_helper_writes_summary_and_graph_preview_response(self) -> None:
        from hawki_indexer_worker.indexing.finalize import build_success_ingest_response

        doc_stats = {
            "processed_docs": 1,
            "skipped_docs": 0,
            "total_chunks": 1,
            "doc_ids": ["toy-doc"],
        }
        graph_preview = {
            "timestamp": "2026-06-12T00:00:00+00:00",
            "total_docs": 1,
            "total_chunks": 1,
            "docs_with_triplets": 1,
            "total_triplets": 1,
            "per_doc": {"toy-doc": {"chunks": 1, "triplets": 1}},
        }

        result = build_success_ingest_response(
            body=SimpleNamespace(graph=True, graph_only=False),
            doc_stats=doc_stats,
            total_chunks=1,
            batch_size=64,
            collection="toy_docs",
            points_count=3,
            graph_preview=graph_preview,
            qdrant_ms=12.5,
            neo4j_ms=4.5,
            started_at=time.perf_counter(),
            job_id="job-toy",
            operation_id="operation-toy",
            logger_obj=logging.getLogger("test_finalize_helper"),
        )

        self.assertTrue(result["ok"])
        self.assertEqual(result["points"], 3)
        self.assertFalse(result["graph_only"])
        self.assertEqual(result["summary"]["qdrant_preview"]["collection"], "toy_docs")
        self.assertEqual(result["summary"]["qdrant_preview"]["elapsed_ms"], 12.5)
        self.assertEqual(result["summary"]["graph"]["elapsed_ms"], 4.5)
        self.assertEqual(result["summary"]["graph_preview"]["total_triplets"], 1)
        self.assertEqual(result["graph_preview"]["total_triplets"], 1)
        self.assertNotIn("summary_file", result["summary"])
        self.assertNotIn("graph_preview_file", result["summary"])
