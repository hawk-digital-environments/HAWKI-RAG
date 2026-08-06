"""Ingestion scenarios from request validation through vector, graph, retry, and deletion workflows."""

from __future__ import annotations

import logging
import unittest
from types import SimpleNamespace


class IngestCharacterizationTests(unittest.TestCase):
    """Describe validation, dry runs, vector and graph commits, and final ingestion summaries."""

    def test_validate_ingest_document_reports_invalid_shape_and_metadata_warnings(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.validation import (
            normalize_ingest_metadata,
            validate_ingest_document,
        )

        missing = SimpleNamespace(
            id=" ", text="", payload={"converted_path": "/tmp/sample-toys.md"}
        )
        errors, warnings = validate_ingest_document(missing)

        self.assertIn("doc id is missing.", errors)
        self.assertIn("document text is empty.", errors)
        self.assertIn("metadata URL is missing.", warnings)
        self.assertIn("metadata title is missing.", warnings)

        bad_payload = SimpleNamespace(
            id="doc-1", text="Toy train", payload="not-a-dict"
        )
        errors, warnings = validate_ingest_document(bad_payload)

        self.assertEqual(errors, ["document payload must be an object."])
        self.assertEqual(warnings, [])

        normalized = normalize_ingest_metadata(
            SimpleNamespace(
                id="doc-2",
                text="Toy blocks",
                payload={
                    "original_filename": "toy_catalog.docx",
                    "url": "upload://toy_catalog.docx",
                },
            )
        )

        self.assertEqual(normalized["title"], "toy_catalog")
        self.assertEqual(normalized["source_url"], "upload://toy_catalog.docx")
        self.assertEqual(normalized["page_url"], "upload://toy_catalog.docx")

    def test_prepare_documents_skips_invalid_docs_and_tracks_chunks(self) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents

        docs = [
            SimpleNamespace(
                id="doc-1",
                text="Toy train. Toy blocks.",
                payload={
                    "title": "Toys",
                    "source_url": "upload://toys.md",
                    "source_format": "markdown",
                },
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

    def test_ingest_request_helpers_infer_job_and_apply_provider_overrides(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.request import (
            apply_provider_overrides,
            infer_job_id,
        )

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
        from hawki_indexer_worker.indexing.orchestration import ingest_documents

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
        from hawki_indexer_worker.indexing.chunking import prepare_documents
        from hawki_indexer_worker.indexing.dry_run import build_dry_run_ingest_response
        from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings

        docs = [
            SimpleNamespace(
                id="toy-doc",
                text="A wooden train is a toy for children.",
                payload={
                    "title": "Toy catalog",
                    "source_url": "upload://toy.md",
                    "source_format": "markdown",
                },
            )
        ]
        chunk_records, doc_stats = prepare_documents(
            docs, chunk_chars=1200, chunk_overlap=0, default_job_id="job-toy"
        )
        provider = SimpleNamespace(embed_model="embed-old", rag_model="rag-old")
        captured: dict[str, object] = {"provider_names": []}

        class FakeRAGService:
            def extract_triplets(
                self, text: str, engine: str, **kwargs: object
            ) -> list[tuple[str, str, str]]:
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
        )

        result = build_dry_run_ingest_response(
            body=body,
            doc_stats=doc_stats,
            chunk_records=chunk_records,
            total_chunks=len(chunk_records),
            batch_size=64,
            collection="toy_docs",
            rag_service=FakeRAGService(),
            get_provider=get_provider,
            job_id="job-toy",
            operation_id="operation-toy",
            graph_debug=False,
            graph_settings=settings,
            logger_obj=logging.getLogger("test_dry_run_helper"),
        )

        self.assertTrue(result["ok"])
        self.assertTrue(result["dry_run"])
        self.assertEqual(result["summary"]["graph_preview"]["total_triplets"], 1)
        self.assertEqual(result["graph_preview"]["total_triplets"], 1)
        self.assertNotIn("graph_preview_file", result["summary"])
        self.assertEqual(
            result["summary"]["graph_preview"]["per_doc"]["toy-doc"]["triplets"], 1
        )
        self.assertEqual(captured["provider_names"], ["fake-provider"])
        self.assertEqual(provider.embed_model, "embed-new")
        self.assertEqual(provider.rag_model, "rag-new")
        self.assertEqual(captured["doc_id"], "toy-doc")
        self.assertEqual(captured["neo4j_database"], "toy-graph")
