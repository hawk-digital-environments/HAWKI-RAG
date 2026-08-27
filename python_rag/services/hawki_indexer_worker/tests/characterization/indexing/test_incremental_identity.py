"""Incremental-ingestion scenarios from stable identity through scoped vector and graph writes."""

from __future__ import annotations

import hashlib
import logging
import unittest
from types import SimpleNamespace


class IncrementalIngestTests(unittest.TestCase):
    """Verify retries, replacements, registries, and deletions preserve document isolation."""

    def test_prepare_documents_assigns_stable_http_page_id_and_hash(self) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents
        from hawki_indexer_worker.indexing.incremental import page_identity_key

        doc = SimpleNamespace(
            id="transient-job-doc",
            text="HAWKI uses Qdrant and Neo4j.",
            payload={
                "title": "HAWKI",
                "page_url": "https://Example.test/Research/?page=2#section",
                "source_format": "markdown",
            },
        )

        chunk_records, stats = prepare_documents(
            [doc],
            chunk_chars=1200,
            chunk_overlap=0,
            default_job_id="job-1",
        )

        payload = chunk_records[0]["payload"]
        identity = page_identity_key(payload)
        assert identity is not None
        expected_doc_id = (
            f"doc_{hashlib.sha256(identity.encode('utf-8')).hexdigest()[:40]}"
        )

        self.assertEqual(chunk_records[0]["doc_id"], expected_doc_id)
        self.assertEqual(payload["doc_id"], expected_doc_id)
        self.assertEqual(payload["source_document_id"], "transient-job-doc")
        self.assertEqual(
            payload["source_identity"], "url:https://example.test/Research?page=2"
        )
        self.assertEqual(
            payload["canonical_url"], "https://example.test/Research?page=2"
        )
        self.assertEqual(
            payload["content_hash"],
            hashlib.sha256("HAWKI uses Qdrant and Neo4j.".encode("utf-8")).hexdigest(),
        )
        self.assertEqual(stats["doc_ids"], [expected_doc_id])

    def test_prepare_documents_registers_upload_identity_without_changing_document_id(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents
        from hawki_indexer_worker.indexing.page_state import build_page_state_records

        doc = SimpleNamespace(
            id="doc_upload_page_1",
            text="Page one of an uploaded PDF.",
            payload={
                "title": "upload.pdf",
                "source_url": "upload://upload.pdf",
                "source_format": "markdown",
                "relative_path": "pages/00001.md",
                "source_id": "source-upload-1",
            },
        )

        chunk_records, stats = prepare_documents(
            [doc],
            chunk_chars=1200,
            chunk_overlap=0,
            default_job_id="job-upload-1",
        )

        payload = chunk_records[0]["payload"]
        self.assertEqual(chunk_records[0]["doc_id"], "doc_upload_page_1")
        self.assertEqual(payload["doc_id"], "doc_upload_page_1")
        self.assertEqual(payload["source_identity"], "doc:doc_upload_page_1")
        self.assertEqual(stats["doc_ids"], ["doc_upload_page_1"])

        registry_records = build_page_state_records(
            chunk_records,
            collection="upload_test_docs",
            neo4j_database=None,
        )

        self.assertEqual(len(registry_records), 1)
        self.assertEqual(registry_records[0].doc_id, "doc_upload_page_1")
        self.assertEqual(registry_records[0].source_identity, "doc:doc_upload_page_1")
        self.assertEqual(registry_records[0].source_id, "source-upload-1")
        self.assertEqual(registry_records[0].chunks_count, 1)

    def test_upload_registry_identity_skips_unchanged_retry(self) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents
        from hawki_indexer_worker.indexing.incremental import plan_incremental_ingest

        doc = SimpleNamespace(
            id="doc_upload_retry",
            text="The same uploaded page.",
            payload={
                "source_url": "upload://retry.pdf",
                "source_format": "markdown",
                "relative_path": "pages/00001.md",
            },
        )
        chunk_records, stats = prepare_documents(
            [doc],
            chunk_chars=1200,
            chunk_overlap=0,
            default_job_id="job-upload-retry",
        )
        content_hash = str(chunk_records[0]["payload"]["content_hash"])

        class FakeRegistry:
            def find_by_source_identity(
                self, *, collection: str, source_identity: str
            ) -> dict[str, object]:
                self.lookup = (collection, source_identity)
                return {
                    "doc_id": "doc_upload_retry",
                    "content_hash": content_hash,
                    "status": "completed",
                }

        class FakeQdrant:
            def find_points_by_payload(
                self, filters: dict[str, object], *, limit: int = 1
            ) -> list[dict[str, object]]:
                raise AssertionError(
                    "Qdrant fallback should not be used when the upload registry matches"
                )

        registry = FakeRegistry()
        plan = plan_incremental_ingest(
            chunk_records,
            doc_stats=stats,
            qdrant=FakeQdrant(),
            collection="upload_test_docs",
            operation_id="op-upload-retry",
            logger_obj=logging.getLogger("test_upload_registry_retry"),
            page_registry=registry,
        )

        self.assertEqual(plan.chunk_records, [])
        self.assertEqual(plan.unchanged_doc_ids, {"doc_upload_retry"})
        self.assertEqual(registry.lookup, ("upload_test_docs", "doc:doc_upload_retry"))
        self.assertEqual(len(plan.unchanged_page_records), 1)
        self.assertEqual(
            plan.unchanged_page_records[0].source_identity, "doc:doc_upload_retry"
        )

    def test_incremental_plan_skips_unchanged_and_marks_changed_old_doc_id_for_replace(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.incremental import plan_incremental_ingest

        class FakeQdrant:
            def __init__(self) -> None:
                self.filters: list[dict[str, object]] = []

            def find_points_by_payload(
                self, filters: dict[str, object], *, limit: int = 1
            ) -> list[dict[str, object]]:
                self.filters.append(filters)
                if filters.get("doc_id") == "doc-same":
                    return [
                        {
                            "payload": {
                                "doc_id": "old-doc-same",
                                "content_hash": "same-hash",
                            }
                        }
                    ]
                if filters.get("doc_id") == "doc-changed":
                    return [
                        {
                            "payload": {
                                "doc_id": "old-doc-changed",
                                "content_hash": "old-hash",
                            }
                        }
                    ]
                return []

        chunk_records = [
            {
                "doc_id": "doc-same",
                "content": "same",
                "payload": {
                    "doc_id": "doc-same",
                    "chunk_index": 0,
                    "content_hash": "same-hash",
                    "source_format": "markdown",
                },
            },
            {
                "doc_id": "doc-changed",
                "content": "changed",
                "payload": {
                    "doc_id": "doc-changed",
                    "chunk_index": 0,
                    "content_hash": "new-hash",
                    "source_format": "markdown",
                },
            },
            {
                "doc_id": "doc-new",
                "content": "new",
                "payload": {
                    "doc_id": "doc-new",
                    "chunk_index": 0,
                    "content_hash": "brand-new",
                    "source_format": "markdown",
                },
            },
        ]
        doc_stats: dict[str, object] = {
            "processed_docs": 3,
            "skipped_docs": 0,
            "doc_ids": ["doc-same", "doc-changed", "doc-new"],
            "chunks_per_doc": {"doc-same": 1, "doc-changed": 1, "doc-new": 1},
            "by_format": {"markdown": 3},
        }

        plan = plan_incremental_ingest(
            chunk_records,
            doc_stats=doc_stats,
            qdrant=FakeQdrant(),
            collection="test_docs",
            operation_id="op-1",
            logger_obj=logging.getLogger("test_incremental_plan"),
        )

        self.assertEqual(
            [record["doc_id"] for record in plan.chunk_records],
            ["doc-changed", "doc-new"],
        )
        self.assertEqual(plan.unchanged_doc_ids, {"doc-same"})
        self.assertEqual(plan.changed_doc_ids, {"doc-changed"})
        self.assertEqual(plan.new_doc_ids, {"doc-new"})
        self.assertEqual(plan.replace_doc_ids, {"doc-changed", "old-doc-changed"})
        self.assertEqual(
            plan.replace_doc_ids_by_doc,
            {"doc-changed": {"doc-changed", "old-doc-changed"}},
        )
        self.assertEqual(doc_stats["processed_docs"], 2)
        self.assertEqual(doc_stats["skipped_docs"], 1)
        self.assertEqual(doc_stats["doc_ids"], ["doc-changed", "doc-new"])
        self.assertEqual(doc_stats["chunks_per_doc"], {"doc-changed": 1, "doc-new": 1})
        self.assertEqual(doc_stats["by_format"], {"markdown": 2})
        self.assertEqual(doc_stats["incremental_unchanged_docs"], 1)
        self.assertEqual(doc_stats["incremental_changed_docs"], 1)
        self.assertEqual(doc_stats["incremental_new_docs"], 1)
