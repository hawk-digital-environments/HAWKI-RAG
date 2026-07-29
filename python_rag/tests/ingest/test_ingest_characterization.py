"""Ingestion scenarios from request validation through vector, graph, retry, and deletion workflows."""

from __future__ import annotations

import json
import logging
import os
import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import MappingProxyType, SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))

from characterization_support import install_optional_dependency_stubs

install_optional_dependency_stubs()



class IngestCharacterizationTests(unittest.TestCase):
    """Describe validation, dry runs, vector and graph commits, and final ingestion summaries."""
    def test_validate_ingest_document_reports_invalid_shape_and_metadata_warnings(self) -> None:
        from application.workflows.validation import normalize_ingest_metadata, validate_ingest_document

        missing = SimpleNamespace(id=" ", text="", payload={"converted_path": "/tmp/sample-toys.md"})
        errors, warnings = validate_ingest_document(missing)

        self.assertIn("doc id is missing.", errors)
        self.assertIn("document text is empty.", errors)
        self.assertIn("metadata URL is missing.", warnings)
        self.assertIn("metadata title is missing.", warnings)

        bad_payload = SimpleNamespace(id="doc-1", text="Toy train", payload="not-a-dict")
        errors, warnings = validate_ingest_document(bad_payload)

        self.assertEqual(errors, ["document payload must be an object."])
        self.assertEqual(warnings, [])

        normalized = normalize_ingest_metadata(
            SimpleNamespace(
                id="doc-2",
                text="Toy blocks",
                payload={"original_filename": "toy_catalog.docx", "url": "upload://toy_catalog.docx"},
            )
        )

        self.assertEqual(normalized["title"], "toy_catalog")
        self.assertEqual(normalized["source_url"], "upload://toy_catalog.docx")
        self.assertEqual(normalized["page_url"], "upload://toy_catalog.docx")

    def test_prepare_documents_skips_invalid_docs_and_tracks_chunks(self) -> None:
        from application.workflows.ingest.chunking import prepare_documents

        docs = [
            SimpleNamespace(
                id="doc-1",
                text="Toy train. Toy blocks.",
                payload={"title": "Toys", "source_url": "upload://toys.md", "source_format": "markdown"},
            ),
            SimpleNamespace(id="", text="Skipped", payload={"title": "Invalid"}),
        ]

        chunk_records, stats = prepare_documents(
            docs,
            chunk_chars=100,
            chunk_overlap=0,
            default_job_id="job-1",
        )

        self.assertEqual(len(chunk_records), 1)
        self.assertEqual(stats["processed_docs"], 1)
        self.assertEqual(stats["skipped_docs"], 1)
        self.assertEqual(stats["chunks_per_doc"], {"doc-1": 1})
        self.assertEqual(stats["validation_failures"][0]["doc_id"], "")
        self.assertEqual(chunk_records[0]["payload"]["doc_id"], "doc-1")
        self.assertEqual(chunk_records[0]["payload"]["component_type"], "chunk")

    def test_ingest_request_helpers_infer_job_and_apply_provider_overrides(self) -> None:
        from application.workflows.ingest.request import apply_provider_overrides, infer_job_id

        docs = [SimpleNamespace(id="doc-1", payload={"trace_id": "trace-1"})]
        body = SimpleNamespace(
            job_id=None,
            embedding_model="embed-v2",
            graph_model="graph-v2",
            vision_model="vision-v2",
        )

        class Provider:
            embed_model = "embed-v1"
            rag_model = "graph-v1"
            vision_model = "vision-v1"

        provider = Provider()
        apply_provider_overrides(provider, body)

        self.assertEqual(infer_job_id(body, docs), "trace-1")
        self.assertEqual(provider.embed_model, "embed-v2")
        self.assertEqual(provider.rag_model, "graph-v2")
        self.assertEqual(provider.vision_model, "vision-v2")
        self.assertEqual(provider._explicit_graph_model, "graph-v2")

    def test_ingest_documents_dry_run_returns_request_summary_shape(self) -> None:
        from application.workflows.ingest_logic import ingest_documents

        body = SimpleNamespace(
            docs=[
                SimpleNamespace(
                    id="doc-1",
                    text="HAWKI uses Qdrant and Neo4j.",
                    payload={
                        "title": "HAWKI",
                        "source_url": "upload://hawki.md",
                        "source_format": "markdown",
                    },
                )
            ],
            dry_run=True,
            dry_include_graph=False,
            provider="fake",
            collection="hawki_test",
            graph=False,
            graph_engine="raganything",
            graph_only=False,
            chunk_chars=1200,
            chunk_overlap=250,
            batch_size=64,
            distance="Cosine",
            neo4j_database=None,
        )

        result = ingest_documents(
            body,
            rag_service=object(),
            get_provider=lambda name: object(),
            public_dir=Path(tempfile.gettempdir()),
        )

        self.assertTrue(result["ok"])
        self.assertTrue(result["dry_run"])
        summary = result["summary"]
        self.assertTrue(summary["estimate_only"])
        self.assertEqual(summary["planned_points"], 1)
        self.assertEqual(summary["qdrant_preview"]["collection"], "hawki_test")
        self.assertEqual(summary["documents"]["doc_ids"], ["doc-1"])
        self.assertEqual(summary["documents"]["by_format"], {"markdown": 1})

    def test_dry_run_helper_builds_graph_preview_without_store_writes(self) -> None:
        from application.workflows.ingest.chunking import prepare_documents
        from application.workflows.ingest.dry_run import build_dry_run_ingest_response
        from application.workflows.ingest.settings import GraphIngestSettings

        docs = [
            SimpleNamespace(
                id="toy-doc",
                text="A wooden train is a toy for children.",
                payload={"title": "Toy catalog", "source_url": "upload://toy.md", "source_format": "markdown"},
            )
        ]
        chunk_records, doc_stats = prepare_documents(docs, chunk_chars=1200, chunk_overlap=0, default_job_id="job-toy")
        provider = SimpleNamespace(embed_model="embed-old", rag_model="rag-old")
        captured: dict[str, object] = {"provider_names": []}

        class FakeRAGService:
            def extract_triplets(self, text: str, engine: str, **kwargs: object) -> list[tuple[str, str, str]]:
                captured["engine"] = engine
                captured["provider"] = kwargs["provider"]
                captured["doc_id"] = kwargs["doc_id"]
                captured["neo4j_database"] = kwargs["neo4j_database"]
                return [("Wooden train", "is", "Toy")]

        def get_provider(name: str) -> object:
            captured["provider_names"].append(name)
            return provider

        body = SimpleNamespace(
            graph=True,
            dry_include_graph=True,
            provider="fake-provider",
            graph_engine="raganything",
            neo4j_database="toy-graph",
            embedding_model="embed-new",
            graph_model="rag-new",
        )
        settings = GraphIngestSettings(
            graph_debug=False,
            graph_perf_log=False,
            graph_doc_timeout_s=0.0,
            graph_doc_max_chars=0,
            graph_doc_max_chunks=0,
            graph_failure_log="",
        )

        with tempfile.TemporaryDirectory() as tmp:
            result = build_dry_run_ingest_response(
                body=body,
                doc_stats=doc_stats,
                chunk_records=chunk_records,
                total_chunks=len(chunk_records),
                batch_size=64,
                collection="toy_docs",
                rag_service=FakeRAGService(),
                get_provider=get_provider,
                public_dir=Path(tmp),
                job_id="job-toy",
                operation_id="operation-toy",
                graph_debug=False,
                graph_settings=settings,
                logger_obj=logging.getLogger("test_dry_run_helper"),
            )

            preview_file = Path(result["summary"]["graph_preview_file"])
            self.assertTrue(preview_file.exists())

        self.assertTrue(result["ok"])
        self.assertTrue(result["dry_run"])
        self.assertEqual(result["summary"]["graph_preview"]["total_triplets"], 1)
        self.assertEqual(result["summary"]["graph_preview"]["per_doc"]["toy-doc"]["triplets"], 1)
        self.assertEqual(captured["provider_names"], ["fake-provider"])
        self.assertEqual(provider.embed_model, "embed-new")
        self.assertEqual(provider.rag_model, "rag-new")
        self.assertEqual(captured["doc_id"], "toy-doc")
        self.assertEqual(captured["neo4j_database"], "toy-graph")

    def test_vector_commit_helper_records_partial_embedding_failures_and_upserts_points(self) -> None:
        from application.workflows.ingest.vector_commit import commit_vector_points

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
                self.ensure_calls.append({"vector_size": vector_size, "distance": distance})

            def upsert_points(
                self,
                points: list[dict[str, object]],
                *,
                batch_size: int,
                idempotency_key: str | None = None,
            ) -> None:
                self.upsert_calls.append(
                    {"points": points, "batch_size": batch_size, "idempotency_key": idempotency_key}
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
                "payload": {"doc_id": "toy-good", "chunk_index": 0, "title": "Good Toy"},
            },
            {
                "doc_id": "toy-broken",
                "content": "broken toy text",
                "payload": {"doc_id": "toy-broken", "chunk_index": 0, "title": "Broken Toy"},
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
        self.assertEqual(qdrant.ensure_calls, [{"vector_size": 2, "distance": "Cosine"}])
        self.assertEqual(qdrant.upsert_calls[0]["batch_size"], 32)
        self.assertEqual(qdrant.upsert_calls[0]["idempotency_key"], "operation-toy")
        self.assertEqual(doc_stats["embedding_failed_chunks"], 1)
        self.assertEqual(doc_stats["embedding_failed_docs"], 1)
        self.assertEqual(doc_stats["embedding_skipped_docs"], 1)
        self.assertEqual(doc_stats["processed_docs"], 1)
        self.assertEqual(doc_stats["skipped_docs"], 1)
        self.assertEqual(doc_stats["doc_ids"], ["toy-good"])

    def test_graph_commit_helper_extracts_upserts_and_closes_graph(self) -> None:
        from application.workflows.ingest.graph_commit import commit_graph_triplets
        from application.workflows.ingest.settings import GraphIngestSettings

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
                self.upserts.append({
                    "triplets": triplets,
                    "doc_id": doc_id,
                    "request_id": request_id,
                    "dataset_id": dataset_id,
                    "neo4j_namespace": neo4j_namespace,
                })

            def close(self) -> None:
                self.closed = True

        class FakeRAGService:
            def extract_triplets(self, text: str, engine: str, **kwargs: object) -> list[tuple[str, str, str]]:
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
                "payload": {"doc_id": "toy-doc", "title": "Toy catalog", "source_url": "upload://toy.md"},
            }
        ]
        settings = GraphIngestSettings(
            graph_debug=False,
            graph_perf_log=False,
            graph_doc_timeout_s=0.0,
            graph_doc_max_chars=0,
            graph_doc_max_chunks=0,
            graph_failure_log="",
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

        with tempfile.TemporaryDirectory() as tmp, patch(
            "application.workflows.ingest.graph_ingest.write_graph_visualization",
            return_value=None,
        ):
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
                public_dir=Path(tmp),
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
        from application.workflows.ingest.finalize import build_success_ingest_response

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

        with tempfile.TemporaryDirectory() as tmp:
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
                public_dir=Path(tmp),
                job_id="job-toy",
                operation_id="operation-toy",
                logger_obj=logging.getLogger("test_finalize_helper"),
            )

            summary_file = Path(result["summary"]["summary_file"])
            preview_file = Path(result["summary"]["graph_preview_file"])
            persisted_summary = json.loads(summary_file.read_text(encoding="utf-8"))
            persisted_preview = json.loads(preview_file.read_text(encoding="utf-8"))

        self.assertTrue(result["ok"])
        self.assertEqual(result["points"], 3)
        self.assertFalse(result["graph_only"])
        self.assertEqual(result["summary"]["qdrant_preview"]["collection"], "toy_docs")
        self.assertEqual(result["summary"]["qdrant_preview"]["elapsed_ms"], 12.5)
        self.assertEqual(result["summary"]["graph"]["elapsed_ms"], 4.5)
        self.assertEqual(result["summary"]["graph_preview"]["total_triplets"], 1)
        self.assertEqual(persisted_summary["planned_points"], 1)
        self.assertEqual(persisted_preview["total_triplets"], 1)

    def test_ingest_documents_uses_injected_vector_and_graph_dependencies(self) -> None:
        from application.workflows.ingest.dependencies import IngestWorkflowDependencies
        from application.workflows.ingest.settings import GraphIngestSettings
        from application.workflows.ingest_logic import ingest_documents

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
                self.upserts.append({"triplets": triplets, "doc_id": doc_id, "request_id": request_id})

            def close(self) -> None:
                self.closed = True

        class FakeRAGService:
            def extract_triplets(self, *args: object, **kwargs: object) -> list[tuple[str, str, str]]:
                return [("Wooden train", "is", "Toy")]

        qdrant = FakeQdrant()
        graph = FakeGraph()
        calls: dict[str, object] = {"graph_database": None, "providers": []}

        body = SimpleNamespace(
            docs=[
                SimpleNamespace(
                    id="toy-doc",
                    text="A wooden train is a toy for children.",
                    payload={"title": "Toy catalog", "source_url": "upload://toy.md", "source_format": "markdown"},
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

        with tempfile.TemporaryDirectory() as tmp, patch(
            "application.workflows.ingest.graph_ingest.write_graph_visualization",
            return_value=None,
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
        from application.workflows.ingest.vector_ingest import build_points

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
        from application.workflows.ingest.settings import load_graph_ingest_settings
        from application.workflows.ingest.graph_ingest import graph_failure_log_path

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
            self.assertEqual(str(graph_failure_log_path(Path(tempfile.gettempdir()))), "/tmp/custom_graph_failures.log")

        self.assertTrue(settings.graph_debug)
        self.assertTrue(settings.graph_perf_log)
        self.assertEqual(settings.graph_doc_timeout_s, 12.0)
        self.assertEqual(settings.graph_doc_max_chars, 777)
        self.assertEqual(settings.graph_doc_max_chunks, 5)


class IngestDeletionCharacterizationTests(unittest.TestCase):
    """Verify document deletion remains scoped across vector and graph storage."""

    def test_delete_document_entries_accepts_read_only_mapping_results(self) -> None:
        from application.workflows.ingest.deletion import delete_document_entries

        class Qdrant:
            collection = "student_space"

            def delete_by_doc_id(
                self,
                doc_id: str,
                *,
                idempotency_key: str | None = None,
            ) -> object:
                return MappingProxyType({
                    "result": MappingProxyType({"deleted": 3}),
                })

        class Graph:
            def delete_by_doc_id(
                self,
                doc_id: str,
                *,
                request_id: str | None = None,
            ) -> object:
                return MappingProxyType({
                    "relationships_deleted": "2",
                    "entities_deleted": 1,
                })

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

    def test_delete_document_entries_scopes_vector_and_graph_delete_then_closes_graph(self) -> None:
        from application.workflows.ingest.deletion import delete_document_entries

        events: list[tuple[str, object]] = []

        class Qdrant:
            collection = "default"

            def set_collection(self, collection: str) -> None:
                self.collection = collection
                events.append(("qdrant_collection", collection))

            def count_points_by_doc_id(self, doc_id: str, *, collection: str | None = None, exact: bool = True) -> int:
                events.append(("qdrant_count", {"doc_id": doc_id, "collection": collection, "exact": exact}))
                return 4

            def delete_by_doc_id(self, doc_id: str, *, idempotency_key: str | None = None) -> dict[str, object]:
                events.append(("qdrant", {"doc_id": doc_id, "idempotency_key": idempotency_key, "collection": self.collection}))
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
                events.append(("graph_scope", {"database": database, "neo4j_namespace": neo4j_namespace}))

            def delete_by_doc_id(self, doc_id: str, *, request_id: str | None = None) -> dict[str, int]:
                events.append(("graph", {
                    "doc_id": doc_id,
                    "request_id": request_id,
                    "database": self.database,
                    "neo4j_namespace": self.neo4j_namespace,
                }))
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
                ("qdrant_count", {"doc_id": "doc-1", "collection": "student_space", "exact": True}),
                ("qdrant", {"doc_id": "doc-1", "idempotency_key": "delete-op-1", "collection": "student_space"}),
                ("graph_scope", {"database": None, "neo4j_namespace": "student_graph"}),
                ("graph", {
                    "doc_id": "doc-1",
                    "request_id": "delete-op-1",
                    "database": None,
                    "neo4j_namespace": "student_graph",
                }),
                ("graph_close", ""),
            ],
        )
