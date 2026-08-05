"""Ingestion scenarios from request validation through vector, graph, retry, and deletion workflows."""

from __future__ import annotations

import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


class IngestCharacterizationTests(unittest.TestCase):
    """Describe validation, dry runs, vector and graph commits, and final ingestion summaries."""

    def test_ingest_documents_uses_injected_vector_and_graph_dependencies(self) -> None:
        from hawki_indexer_worker.indexing.dependencies import (
            IngestWorkflowDependencies,
        )
        from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
        from hawki_indexer_worker.indexing.orchestration import ingest_documents

        class FakeQdrant:
            def __init__(self) -> None:
                self.collection = "default_collection"

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
            ) -> None:
                self.upserts.append(
                    {"triplets": triplets, "doc_id": doc_id, "request_id": request_id}
                )

            def close(self) -> None:
                self.closed = True

        class FakeRAGService:
            def extract_triplets(
                self, *args: object, **kwargs: object
            ) -> list[tuple[str, str, str]]:
                return [("Wooden train", "is", "Toy")]

        qdrant = FakeQdrant()
        graph = FakeGraph()
        calls: dict[str, object] = {"graph_database": None, "providers": []}

        body = SimpleNamespace(
            docs=[
                SimpleNamespace(
                    id="toy-doc",
                    text="A wooden train is a toy for children.",
                    payload={
                        "title": "Toy catalog",
                        "source_url": "upload://toy.md",
                        "source_format": "markdown",
                    },
                )
            ],
            dry_run=False,
            dry_include_graph=False,
            provider="fake",
            collection="toy_docs",
            graph=True,
            graph_engine="raganything",
            graph_only=True,
            chunk_chars=1200,
            chunk_overlap=0,
            batch_size=64,
            distance="Cosine",
            neo4j_database="toy-graph",
            dataset_id="dataset-toy",
            neo4j_namespace="toy-graph",
            job_id="job-toy",
            idempotency_key=None,
            embedding_model=None,
            graph_model=None,
        )
        settings = GraphIngestSettings(
            graph_debug=True,
            graph_perf_log=False,
            graph_doc_timeout_s=0.0,
            graph_doc_max_chars=0,
            graph_doc_max_chunks=0,
            graph_failure_log="",
        )

        def graph_factory(database: str | None = None) -> FakeGraph:
            calls["graph_database"] = database
            return graph

        def get_provider(name: str) -> object:
            calls["providers"].append(name)
            return SimpleNamespace(embed_model="embed", rag_model="rag")

        with (
            tempfile.TemporaryDirectory() as tmp,
            patch(
                "hawki_indexer_worker.indexing.graph_prepare.write_graph_visualization",
                return_value=None,
            ),
        ):
            result = ingest_documents(
                body,
                rag_service=FakeRAGService(),
                get_provider=get_provider,
                public_dir=Path(tmp),
                idempotency_key="operation-toy",
                dependencies=IngestWorkflowDependencies(
                    graph_settings_loader=lambda: settings,
                    qdrant_factory=lambda: qdrant,
                    graph_factory=graph_factory,
                ),
            )

        self.assertTrue(result["ok"])
        self.assertTrue(result["graph_only"])
        self.assertEqual(result["points"], 0)
        self.assertEqual(result["summary"]["qdrant_preview"]["collection"], "toy_docs")
        self.assertEqual(calls["providers"], ["fake"])
        self.assertEqual(calls["graph_database"], "toy-graph")
        self.assertTrue(graph.closed)
        self.assertEqual(graph.upserts[0]["doc_id"], "toy-doc")
        self.assertEqual(graph.upserts[0]["request_id"], "job-toy")
        self.assertEqual(graph.upserts[0]["triplets"], [("Wooden train", "is", "Toy")])

    def test_build_points_creates_deterministic_qdrant_point_payload(self) -> None:
        from hawki_indexer_worker.indexing.vector_prepare import build_points

        class Provider:
            def embed(self, text: str) -> list[float]:
                self.last_text = text
                return [0.1, 0.2, 0.3]

        chunk_records = [
            {
                "doc_id": "doc-1",
                "content": "HAWKI content",
                "payload": {
                    "doc_id": "doc-1",
                    "chunk_index": 0,
                    "title": "HAWKI",
                    "source_url": "upload://hawki.md",
                    "content": "HAWKI content",
                },
            }
        ]

        points, vector_size, failures = build_points(chunk_records, Provider())

        self.assertEqual(vector_size, 3)
        self.assertEqual(failures, [])
        self.assertEqual(len(points), 1)
        self.assertEqual(points[0]["vector"], [0.1, 0.2, 0.3])
        self.assertEqual(points[0]["payload"]["doc_id"], "doc-1")
        self.assertEqual(points[0]["payload"]["chunk_index"], 0)
        self.assertRegex(points[0]["id"], r"^[0-9a-f-]{36}$")

    def test_graph_ingest_settings_load_from_env(self) -> None:
        from hawki_indexer_worker.indexing.graph_prepare import graph_failure_log_path
        from hawki_indexer_worker.indexing.graph_settings import (
            load_graph_ingest_settings,
        )

        with patch.dict(
            os.environ,
            {
                "GRAPH_DEBUG": "true",
                "GRAPH_PERF_LOG": "1",
                "GRAPH_DOC_TIMEOUT": "12",
                "GRAPH_DOC_MAX_CHARS": "777",
                "GRAPH_DOC_MAX_CHUNKS": "5",
                "GRAPH_FAILURE_LOG": "/tmp/custom_graph_failures.log",
            },
            clear=False,
        ):
            settings = load_graph_ingest_settings()
            self.assertEqual(
                str(graph_failure_log_path(Path(tempfile.gettempdir()))),
                "/tmp/custom_graph_failures.log",
            )

        self.assertTrue(settings.graph_debug)
        self.assertTrue(settings.graph_perf_log)
        self.assertEqual(settings.graph_doc_timeout_s, 12.0)
        self.assertEqual(settings.graph_doc_max_chars, 777)
        self.assertEqual(settings.graph_doc_max_chunks, 5)
