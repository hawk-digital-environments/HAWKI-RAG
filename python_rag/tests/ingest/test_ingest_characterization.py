"""Ingestion scenarios from request validation through vector, graph, retry, and deletion workflows."""

from __future__ import annotations

import io
import json
import logging
import os
import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
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


class IngestRunnerCharacterizationTests(unittest.TestCase):
    """Protect CLI batching, resume, retry, partial-success, and exit-code behavior."""
    def test_ingest_crawled_main_delegates_to_runner(self) -> None:
        from application.cli.commands.ingest_crawled import ingest_crawled

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "crawl"
            root.mkdir()
            with patch("application.cli.commands.ingest_crawled.run_ingest", return_value=123) as run_mock:
                exit_code = ingest_crawled.main(["--root", str(root)])

            run_mock.assert_called_once()
            self.assertEqual(exit_code, 123)

    def test_runner_config_helpers_parse_options_and_partial_summaries(self) -> None:
        from application.cli.commands.runner_config import (
            build_default_options,
            build_no_ingestable_summary,
            build_no_pages_summary,
            env_bool,
            env_choice,
        )

        self.assertTrue(env_bool("ENABLED", env={"ENABLED": "yes"}))
        self.assertFalse(env_bool("ENABLED", default=False, env={"ENABLED": ""}))
        self.assertEqual(env_choice("MODE", {"resume", "start"}, "resume", env={"MODE": "START"}), "start")
        with self.assertRaises(ValueError):
            env_choice("MODE", {"resume", "start"}, "resume", env={"MODE": "bad"})

        args = SimpleNamespace(
            provider="ollama",
            graph=True,
            graph_engine="raganything",
            distance="Cosine",
            chunk_chars="1200",
            chunk_overlap="200",
            batch="32",
            graph_model="graph-model",
            graph_only=True,
            neo4j_database="toy-graph",
            embedding_model="embed-model",
            collection="toy_docs",
            dry=True,
            dry_include_graph=True,
        )

        options = build_default_options(args)
        self.assertEqual(options["provider"], "ollama")
        self.assertEqual(options["batch_size"], 32)
        self.assertEqual(options["chunk_chars"], 1200)
        self.assertTrue(options["graph_only"])
        self.assertTrue(options["dry_run"])
        self.assertTrue(options["dry_include_graph"])
        self.assertEqual(options["neo4j_database"], "toy-graph")

        no_pages = build_no_pages_summary("summary.json")
        self.assertEqual(no_pages["reason"], "no_pages_found")
        self.assertEqual(no_pages["documents"]["total_docs"], 0)

        no_ingestable = build_no_ingestable_summary(3, 2, ["empty-a", "empty-b"], "summary.json")
        self.assertEqual(no_ingestable["reason"], "no_ingestable_documents")
        self.assertEqual(no_ingestable["documents"]["total_docs"], 3)
        self.assertEqual(no_ingestable["documents"]["empty_paths"], ["empty-a", "empty-b"])

    def test_retry_input_helpers_parse_doc_ids_and_options(self) -> None:
        from application.cli.commands.retry_inputs import (
            build_retry_options,
            load_doc_ids_from_failures,
            load_doc_ids_from_file,
            normalize_doc_ids,
        )

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            text_file = root / "ids.txt"
            object_file = root / "ids.json"
            failures_file = root / "failures.jsonl"
            text_file.write_text("Doc-A, doc-b\nDOC-C", encoding="utf-8")
            object_file.write_text(json.dumps({"doc_ids": ["Doc-D", " doc-e "]}), encoding="utf-8")
            failures_file.write_text(
                "\n".join(
                    [
                        json.dumps({"doc_id": "Doc-F"}),
                        json.dumps({"id": "Doc-G"}),
                        json.dumps({"docId": "Doc-H"}),
                        "not-json",
                    ]
                ),
                encoding="utf-8",
            )

            text_ids = load_doc_ids_from_file(text_file)
            object_ids = load_doc_ids_from_file(object_file)
            failure_ids = load_doc_ids_from_failures(failures_file)

        self.assertEqual(text_ids, {"doc-a", "doc-b", "doc-c"})
        self.assertEqual(object_ids, {"doc-d", "doc-e"})
        self.assertEqual(failure_ids, {"doc-f", "doc-g", "doc-h"})
        self.assertEqual(normalize_doc_ids([" Doc-I ", "", "DOC-J"]), {"doc-i", "doc-j"})

        options = build_retry_options(
            SimpleNamespace(
                provider="ollama",
                graph=False,
                graph_engine="raganything",
                distance="Cosine",
                chunk_chars="900",
                chunk_overlap="100",
                graph_only=True,
                collection="toy_docs",
                dry=True,
                dry_include_graph=True,
            )
        )

        self.assertEqual(options["provider"], "ollama")
        self.assertTrue(options["graph"])
        self.assertTrue(options["graph_only"])
        self.assertEqual(options["collection"], "toy_docs")
        self.assertEqual(options["chunk_chars"], 900)
        self.assertTrue(options["dry_run"])
        self.assertTrue(options["dry_include_graph"])

    def test_retry_document_helper_materializes_queue_doc(self) -> None:
        from application.cli.commands.metadata import make_doc_id
        from application.cli.commands.retry_documents import queue_retry_doc

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            page = root / "pages" / "toy"
            page.mkdir(parents=True)
            (page / "metadata.json").write_text(
                json.dumps(
                    {
                        "url": "https://example.test/toys",
                        "title": "Toy Shelf",
                        "date": "2026-06-10",
                        "tags": ["wooden toys", "trains"],
                        "images": ["https://example.test/toy.png"],
                        "metaImageUrl": "https://example.test/card.png",
                    }
                ),
                encoding="utf-8",
            )
            (page / "content.md").write_text("# Toy Shelf\nWooden trains and teddy bears.", encoding="utf-8")

            empty_page = root / "pages" / "empty"
            empty_page.mkdir()
            (empty_page / "metadata.json").write_text(json.dumps({"url": "https://example.test/empty"}), encoding="utf-8")

            queued = queue_retry_doc(
                directory=page,
                root=root,
                page_url_map={},
                source_url_map={},
            )
            empty = queue_retry_doc(
                directory=empty_page,
                root=root,
                page_url_map={},
                source_url_map={},
            )

        self.assertIsNotNone(queued)
        assert queued is not None
        expected_id = make_doc_id("https://example.test/toys", "pages/toy")
        self.assertEqual(queued.doc_id, expected_id)
        self.assertEqual(queued.doc["id"], expected_id)
        self.assertEqual(queued.doc["text"], "# Toy Shelf\nWooden trains and teddy bears.")
        payload = queued.doc["payload"]
        assert isinstance(payload, dict)
        self.assertEqual(payload["title"], "Toy Shelf")
        self.assertEqual(payload["page_url"], "https://example.test/toys")
        self.assertEqual(payload["source_url"], "https://example.test/toys")
        self.assertEqual(payload["date"], "2026-06-10")
        self.assertEqual(payload["meta_img_url"], ["https://example.test/card.png"])
        self.assertEqual(payload["images"], ["https://example.test/toy.png"])
        self.assertEqual(payload["tags"], ["wooden toys", "trains"])
        self.assertNotIn("file_path", payload)
        self.assertIsNone(empty)

    def test_retry_batch_sender_posts_and_tracks_failures(self) -> None:
        from application.cli.commands.retry_batches import RetryBatchSender

        calls: list[tuple[str, list[str], dict[str, object], int]] = []
        responses = [
            (True, {"ok": True}, None),
            (False, None, "boom"),
        ]

        def fake_post(
            base_url: str,
            docs: list[dict[str, object]],
            options: dict[str, object],
            timeout: int,
        ) -> tuple[bool, dict[str, object] | None, str | None]:
            calls.append((base_url, [str(doc.get("id")) for doc in docs], options, timeout))
            return responses.pop(0)

        sender = RetryBatchSender(
            args=SimpleNamespace(base_url="http://rag.local", timeout=7, dry=True),
            options={"graph": True},
            post_batch=fake_post,
            logger_obj=logging.getLogger("retry-batch-test"),
        )

        with patch("builtins.print") as mocked_print:
            self.assertTrue(sender.send([{"id": "doc-a"}]))
            self.assertFalse(sender.send([{"id": "doc-b"}]))

        self.assertEqual(sender.sent, 1)
        self.assertEqual(sender.batch_index, 2)
        self.assertEqual(sender.failures, 1)
        self.assertEqual(
            calls,
            [
                ("http://rag.local", ["doc-a"], {"graph": True}, 7),
                ("http://rag.local", ["doc-b"], {"graph": True}, 7),
            ],
        )
        mocked_print.assert_any_call("Planned 1 docs (batch 1)")

    def test_retry_completion_reports_exit_policy(self) -> None:
        from application.cli.commands.retry_completion import (
            EXIT_PARTIAL_SUCCESS,
            EXIT_RUNTIME_FAILURE,
            EXIT_SUCCESS,
            report_retry_completion,
        )

        stdout = io.StringIO()
        stderr = io.StringIO()
        with patch("sys.stdout", stdout), patch("sys.stderr", stderr):
            partial = report_retry_completion(
                requested_doc_ids={"doc-a", "doc-b"},
                matched={"doc-a": "/tmp/doc-a"},
                remaining={"doc-b"},
                failures=0,
            )

        self.assertEqual(partial, EXIT_PARTIAL_SUCCESS)
        self.assertIn("Matched 1 of 2 requested documents.", stdout.getvalue())
        self.assertIn("  - doc-b", stderr.getvalue())

        with patch("sys.stdout", io.StringIO()), patch("sys.stderr", io.StringIO()):
            failed = report_retry_completion(
                requested_doc_ids={"doc-a"},
                matched={"doc-a": "/tmp/doc-a"},
                remaining=set(),
                failures=1,
            )
        self.assertEqual(failed, EXIT_RUNTIME_FAILURE)

        with patch("sys.stdout", io.StringIO()), patch("sys.stderr", io.StringIO()):
            success = report_retry_completion(
                requested_doc_ids={"doc-a"},
                matched={"doc-a": "/tmp/doc-a"},
                remaining=set(),
                failures=0,
            )
        self.assertEqual(success, EXIT_SUCCESS)

    def test_runner_resume_plan_loads_existing_state_for_noninteractive_resume(self) -> None:
        from application.cli.commands.resume import safe_state_filename, save_resume_state_payload
        from application.cli.commands.runner_resume import build_resume_key_parts, prepare_resume_plan

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "crawl"
            state_dir = Path(tmp) / "state"
            root.mkdir()
            args = SimpleNamespace(
                collection="toy_docs",
                base_url="http://rag.local",
                graph_only=True,
                neo4j_database="toy-graph",
                resume_state_dir=str(state_dir),
                dry=False,
                estimate_only=False,
                resume=False,
                start=False,
                graph=True,
            )
            key = "::".join(build_resume_key_parts(args, root.resolve()))
            state_path = state_dir.expanduser().resolve() / safe_state_filename(key)
            save_resume_state_payload(
                state_path,
                doc_ids={"doc-a", "doc-b"},
                metadata={"collection": "toy_docs"},
                updated_at="2026-06-12T00:00:00+00:00",
            )

            plan = prepare_resume_plan(
                args,
                root.resolve(),
                automation_mode=True,
                configured_resume_mode="resume",
            )

        self.assertTrue(plan.resume_mode)
        self.assertEqual(plan.doc_ids, {"doc-a", "doc-b"})
        self.assertEqual(plan.state_path, state_path)
        self.assertEqual(plan.metadata["collection"], "toy_docs")
        self.assertIn("graph_only", plan.key_parts)
        self.assertIn("neo4j_db=toy-graph", plan.key_parts)

    def test_runner_page_builder_materializes_bridge_doc_and_detects_empty_pages(self) -> None:
        from application.cli.commands.runner_pages import build_page_document

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "crawl"
            page = root / "page-a"
            empty = root / "empty-page"
            page.mkdir(parents=True)
            empty.mkdir(parents=True)
            (page / "metadata.json").write_text(
                json.dumps({"title": "Toy Page", "url": "https://example.test/toys"}),
                encoding="utf-8",
            )
            (page / "content.md").write_text(
                "Toy blocks with manual https://example.test/manual.pdf",
                encoding="utf-8",
            )

            result = build_page_document(
                directory=page,
                root=root,
                page_url_map={},
                source_url_map={},
            )
            empty_result = build_page_document(
                directory=empty,
                root=root,
                page_url_map={},
                source_url_map={},
            )

        self.assertFalse(result.empty)
        self.assertIsNotNone(result.doc)
        assert result.doc is not None
        self.assertEqual(result.rel_dir, "page-a")
        self.assertEqual(result.doc["payload"]["title"], "Toy Page")
        self.assertEqual(result.doc["payload"]["page_url"], "https://example.test/toys")
        self.assertEqual(result.doc["payload"]["source_url"], "https://example.test/toys")
        self.assertEqual(result.doc["payload"]["pdfs"], ["https://example.test/manual.pdf"])
        self.assertTrue(empty_result.empty)
        self.assertIsNone(empty_result.doc)

    def test_runner_batch_sender_posts_and_persists_resume_progress(self) -> None:
        from application.cli.commands.runner_batches import BatchSender

        calls: list[dict[str, object]] = []

        def post_batch(**kwargs: object) -> tuple[bool, dict[str, object], None]:
            calls.append(kwargs)
            return True, {"summary": {"planned_points": len(kwargs["docs"])}}, None

        with tempfile.TemporaryDirectory() as tmp:
            state_path = Path(tmp) / "resume.json"
            sender = BatchSender(
                args=SimpleNamespace(base_url="http://rag.local", timeout=9, dry=False),
                options={"collection": "toy_docs"},
                resume_state_path=state_path,
                resume_metadata={"collection": "toy_docs"},
                processed_doc_ids=set(),
                post_batch=post_batch,
                min_split_batch=4,
                max_split_depth=2,
            )
            ok = sender.send(
                [{"id": "doc-a"}, {"id": "doc-b"}],
                total=2,
            )
            persisted = json.loads(state_path.read_text(encoding="utf-8"))

        self.assertTrue(ok)
        self.assertEqual(sender.sent, 2)
        self.assertEqual(sender.batch_index, 1)
        self.assertEqual(sender.last_response, {"summary": {"planned_points": 2}})
        self.assertEqual(calls[0]["base_url"], "http://rag.local")
        self.assertEqual(calls[0]["timeout"], 9)
        self.assertEqual(sorted(persisted["doc_ids"]), ["doc-a", "doc-b"])

    def test_runner_completion_helpers_report_write_and_select_exit_code(self) -> None:
        from application.cli.commands.runner_completion import (
            determine_exit_code,
            report_dry_run_summary,
            report_resume_state,
            write_last_summary,
        )

        printed: list[str] = []
        with patch("builtins.print", side_effect=lambda *args, **kwargs: printed.append(" ".join(str(arg) for arg in args))):
            report_dry_run_summary(
                dry_run=True,
                last_response={
                    "summary": {
                        "planned_points": 4,
                        "graph_preview": {"planned_entities": 2, "planned_triplets": 3},
                    }
                },
            )
            report_resume_state(
                resume_state_path=Path("/tmp/resume.json"),
                dry_run=False,
                resume_mode=True,
                skipped_existing=2,
            )

        written: dict[str, object] = {}
        write_last_summary(
            summary_file="summary.json",
            last_response={"summary": {"ok": True}},
            write_summary_file=lambda path, summary: written.update({"path": path, "summary": summary}),
        )

        self.assertIn("[dry-run] Estimated Qdrant points: 4", printed)
        self.assertIn("[dry-run] Estimated Neo4j entities: 2", printed)
        self.assertIn("[dry-run] Estimated Neo4j relationships: 3", printed)
        self.assertIn("Resume state stored at /tmp/resume.json", printed)
        self.assertIn("Skipped 2 documents already ingested earlier.", printed)
        self.assertEqual(written, {"path": "summary.json", "summary": {"ok": True}})
        self.assertEqual(
            determine_exit_code(dry_run=False, estimate_only=False, failed_batches=1, skipped_empty=0, sent=0),
            1,
        )
        self.assertEqual(
            determine_exit_code(dry_run=True, estimate_only=False, failed_batches=0, skipped_empty=1, sent=0),
            3,
        )
        self.assertEqual(
            determine_exit_code(dry_run=False, estimate_only=False, failed_batches=0, skipped_empty=0, sent=1),
            0,
        )

    def test_runner_no_pages_returns_partial_and_writes_summary(self) -> None:
        from application.cli.commands.ingest_crawled import ingest_crawled
        from application.cli.commands.runner import run_ingest, EXIT_PARTIAL_SUCCESS

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "crawl"
            root.mkdir()
            summary_payload: dict[str, object] = {}

            def fake_write_summary(path: str | None, payload: dict[str, object]) -> None:
                summary_payload["path"] = path
                summary_payload["payload"] = payload

            args = ingest_crawled.parse_args(["--root", str(root), "--summary-file", str(Path(tmp) / "summary.json")])

            with patch("application.cli.commands.runner.discover_page_dirs", return_value=[]), patch(
                "application.cli.commands.submit.write_summary_file",
                side_effect=fake_write_summary,
            ), patch("application.cli.commands.runner.build_url_maps", return_value=({}, {})):
                exit_code = run_ingest(args)

            self.assertEqual(exit_code, EXIT_PARTIAL_SUCCESS)
            payload = summary_payload.get("payload")
            self.assertIsInstance(payload, dict)
            self.assertEqual(payload.get("reason"), "no_pages_found")
            self.assertIsNotNone(summary_payload.get("path"))

    def test_runner_estimate_only_returns_success(self) -> None:
        from application.cli.commands.ingest_crawled import ingest_crawled
        from application.cli.commands.runner import run_ingest

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "crawl"
            page = root / "page"
            page.mkdir(parents=True)
            (page / "content.md").write_text("This is a toy catalog.", encoding="utf-8")

            args = ingest_crawled.parse_args(["--root", str(root), "--estimate-only"])

            with patch("application.cli.commands.runner.discover_page_dirs", return_value=[page]), patch(
                "application.cli.commands.runner.run_local_estimate"
            ) as estimate_mock:
                estimate_mock.return_value = {
                    "timestamp": "2026-06-10T00:00:00Z",
                    "estimate_only": True,
                    "documents": {"total_docs": 1},
                    "qdrant_preview": {"planned_batches": 1},
                }
                exit_code = run_ingest(args)

            estimate_mock.assert_called_once()
            self.assertEqual(exit_code, 0)


class CliIngestHelperCharacterizationTests(unittest.TestCase):
    """Protect source metadata, document identity, URL mapping, and page discovery helpers."""
    def test_ingest_metadata_helpers_normalize_values_and_make_stable_doc_ids(self) -> None:
        from application.cli.commands.metadata import (
            first_str,
            make_doc_id,
            resolve_date,
            title_from_markdown,
            to_array_list,
        )

        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "content.md"
            path.write_text("# Wooden Toys\nBody", encoding="utf-8")
            resolved_date = resolve_date({}, path)

        self.assertIsNone(first_str(" null "))
        self.assertEqual(first_str([" Toys ", "Other"]), "Toys")
        self.assertEqual(to_array_list(["A", None, "undefined", "B"]), ["A", "B"])
        self.assertEqual(title_from_markdown("\n# Wooden Toys\nBody"), "Wooden Toys")
        self.assertRegex(resolved_date or "", r"^\d{4}-\d{2}-\d{2}T")
        self.assertEqual(make_doc_id("upload://toys.md", "toys/content.md"), make_doc_id("upload://toys.md", "other"))

    def test_ingest_payload_builder_preserves_bridge_document_contract(self) -> None:
        from application.cli.commands.payloads import build_bridge_doc, build_payload

        payload = build_payload(
            meta={
                "canonical_url": "https://example.test/toys",
                "updatedAt": "2026-06-10T07:00:00Z",
                "fetchTime": "2026-06-10T07:01:00Z",
                "http_status": 200,
            },
            title="Toy Catalog",
            page_url="https://example.test/page",
            source_url=None,
            rel_path="toys/content.md",
            date="2026-06-10",
            meta_img=None,
            meta_img_list=[],
            images_list=["image.png"],
            pdfs_list=["manual.pdf"],
            tags=[],
            source_format="markdown",
            md_path=Path("/tmp/toys/content.md"),
            ingested_at="2026-06-10T08:00:00Z",
        )
        doc = build_bridge_doc(doc_id="doc-1", text="Toy content", payload=payload)

        self.assertEqual(payload["title"], "Toy Catalog")
        self.assertEqual(payload["source_url"], "https://example.test/page")
        self.assertEqual(payload["tags"], None)
        self.assertEqual(payload["updated_at"], "2026-06-10T07:00:00Z")
        self.assertEqual(payload["fetch_time"], "2026-06-10T07:01:00Z")
        self.assertEqual(payload["file_path"], "/tmp/toys/content.md")
        self.assertEqual(doc, {"id": "doc-1", "text": "Toy content", "payload": payload})

    def test_ingest_resume_helpers_batch_retry_and_persist_state(self) -> None:
        from application.cli.commands.resume import (
            batched,
            load_resume_state,
            safe_state_filename,
            save_resume_state_payload,
            should_split_batch,
        )

        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / safe_state_filename("toy_docs::/tmp/root")
            save_resume_state_payload(
                path,
                doc_ids={"doc-b", "doc-a"},
                metadata={"collection": "toy_docs"},
                updated_at="2026-06-10T08:00:00Z",
            )
            data = json.loads(path.read_text(encoding="utf-8"))

            self.assertEqual(load_resume_state(path), {"doc-a", "doc-b"})
            self.assertEqual(data["doc_ids"], ["doc-a", "doc-b"])
            self.assertEqual(data["collection"], "toy_docs")

        self.assertRegex(safe_state_filename("toy_docs"), r"^[0-9a-f]{40}\.json$")
        self.assertEqual(list(batched([1, 2, 3, 4, 5], 2)), [[1, 2], [3, 4], [5]])
        self.assertTrue(should_split_batch("HTTP 504 gateway timeout"))
        self.assertFalse(should_split_batch("HTTP 400 bad request"))

    def test_ingest_url_maps_link_converted_outputs_to_source_pdf_urls(self) -> None:
        from application.cli.commands.url_maps import build_url_maps, resolve_url_for_path

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            page = root / "page"
            pdf = page / "files" / "manual.pdf"
            converted = root / "converted"
            output = converted / "output"
            pdf.parent.mkdir(parents=True)
            output.mkdir(parents=True)
            pdf.write_text("pdf", encoding="utf-8")
            (page / "page.json").write_text(
                json.dumps(
                    {
                        "page_url": "https://example.test/toys",
                        "pdfs": [{"local_path": str(pdf), "url": "https://example.test/manual.pdf"}],
                    }
                ),
                encoding="utf-8",
            )
            (converted / "conversion_meta.json").write_text(
                json.dumps({"source_pdf": str(pdf), "output_dir": str(converted)}),
                encoding="utf-8",
            )

            page_map, source_map = build_url_maps(root)

            self.assertEqual(resolve_url_for_path(page_map, output, root), "https://example.test/toys")
            self.assertEqual(resolve_url_for_path(source_map, output, root), "https://example.test/manual.pdf")

    def test_pdf_link_extraction_dedupes_and_strips_trailing_punctuation(self) -> None:
        from application.cli.commands.links import extract_pdf_links

        text = (
            "Read https://example.test/a.pdf, then "
            "https://example.test/a.pdf) and https://example.test/b.PDF."
        )

        self.assertEqual(
            extract_pdf_links(text),
            ["https://example.test/a.pdf", "https://example.test/b.PDF"],
        )

    def test_discover_page_dirs_treats_converted_folders_as_document_units(self) -> None:
        from application.cli.commands.discovery import discover_page_dirs

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            page = root / "page"
            converted = page / "converted_doc"
            nested = converted / "output"
            ordinary = root / "ordinary"
            nested.mkdir(parents=True)
            ordinary.mkdir()
            (page / "page.json").write_text("{}", encoding="utf-8")
            (converted / "conversion_meta.json").write_text("{}", encoding="utf-8")
            (nested / "content.md").write_text("duplicate", encoding="utf-8")
            (ordinary / "content.md").write_text("ok", encoding="utf-8")

            discovered = [path.relative_to(root) for path in discover_page_dirs(root)]

        self.assertEqual(
            set(discovered),
            {Path("page"), Path("page/converted_doc"), Path("ordinary")},
        )


class IngestDeletionCharacterizationTests(unittest.TestCase):
    """Verify document deletion remains scoped across vector and graph storage."""
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
