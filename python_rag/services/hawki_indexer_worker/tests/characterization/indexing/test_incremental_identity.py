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

    def test_direct_text_documents_sharing_a_url_keep_separate_ownership(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents
        from hawki_indexer_worker.indexing.incremental import plan_incremental_ingest
        from hawki_indexer_worker.indexing.page_state import build_page_state_record
        from hawki_rag_contracts.pipeline.identity import document_id

        dataset_id = "dataset-shared"
        shared_url = "https://example.test/shared"

        def direct_document(external_document_id: str) -> SimpleNamespace:
            source_hash = hashlib.sha256(
                f"{dataset_id}|{external_document_id}".encode()
            ).hexdigest()[:32]
            source_id = f"source_{source_hash}"
            doc_id = document_id(source_id, "document.md")
            return SimpleNamespace(
                id=doc_id,
                text=f"Content for {external_document_id}",
                payload={
                    "dataset_id": dataset_id,
                    "source_id": source_id,
                    "external_document_id": external_document_id,
                    "source_url": shared_url,
                    "source_format": "markdown",
                    "ingestion_mode": "direct_text",
                },
            )

        document_a = direct_document("A")
        document_b = direct_document("B")
        records_a, _stats_a = prepare_documents(
            [document_a],
            chunk_chars=1200,
            chunk_overlap=0,
            default_job_id="job-a",
        )
        records_b, stats_b = prepare_documents(
            [document_b],
            chunk_chars=1200,
            chunk_overlap=0,
            default_job_id="job-b",
        )

        doc_id_a = str(records_a[0]["doc_id"])
        doc_id_b = str(records_b[0]["doc_id"])
        payload_a = records_a[0]["payload"]
        payload_b = records_b[0]["payload"]
        state_a = build_page_state_record(
            doc_id=doc_id_a,
            records=records_a,
            collection="shared-documents",
            neo4j_database=None,
        )
        state_b = build_page_state_record(
            doc_id=doc_id_b,
            records=records_b,
            collection="shared-documents",
            neo4j_database=None,
        )

        self.assertNotEqual(
            document_a.payload["source_id"], document_b.payload["source_id"]
        )
        self.assertNotEqual(doc_id_a, doc_id_b)
        self.assertEqual(doc_id_a, document_a.id)
        self.assertEqual(doc_id_b, document_b.id)
        self.assertEqual(payload_a["source_identity"], f"doc:{doc_id_a}")
        self.assertEqual(payload_b["source_identity"], f"doc:{doc_id_b}")
        self.assertEqual(payload_a["source_url"], shared_url)
        self.assertEqual(payload_b["source_url"], shared_url)
        self.assertIsNotNone(state_a)
        self.assertIsNotNone(state_b)
        assert state_a is not None
        assert state_b is not None
        self.assertNotEqual(state_a.point_ids, state_b.point_ids)

        class ExistingDocumentQdrant:
            def __init__(self) -> None:
                self.filters: list[dict[str, object]] = []

            def find_points_by_payload(
                self, filters: dict[str, object], *, limit: int = 1
            ) -> list[dict[str, object]]:
                del limit
                self.filters.append(filters)
                if all(payload_a.get(key) == value for key, value in filters.items()):
                    return [{"payload": payload_a}]
                return []

        qdrant = ExistingDocumentQdrant()
        plan = plan_incremental_ingest(
            records_b,
            doc_stats=stats_b,
            qdrant=qdrant,
            collection="shared-documents",
            operation_id="ingest-b",
            logger_obj=logging.getLogger("test_direct_shared_url"),
        )

        self.assertEqual(plan.new_doc_ids, {doc_id_b})
        self.assertEqual(plan.changed_doc_ids, set())
        self.assertEqual(plan.replace_doc_ids, set())
        self.assertTrue(qdrant.filters)
        self.assertTrue(
            all(
                set(filters).issubset({"doc_id", "source_identity", "source_id"})
                for filters in qdrant.filters
            )
        )

    def test_direct_text_url_change_keeps_document_and_replacement_identity(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents
        from hawki_indexer_worker.indexing.incremental import plan_incremental_ingest
        from hawki_indexer_worker.indexing.page_state import build_page_state_record
        from hawki_rag_contracts.pipeline.identity import document_id

        source_id = "source_stable_external_a"
        artifact_doc_id = document_id(source_id, "document.md")

        def direct_records(text: str, source_url: str):
            return prepare_documents(
                [
                    SimpleNamespace(
                        id=artifact_doc_id,
                        text=text,
                        payload={
                            "dataset_id": "dataset-a",
                            "source_id": source_id,
                            "external_document_id": "A",
                            "source_url": source_url,
                            "source_format": "markdown",
                            "ingestion_mode": "direct_text",
                        },
                    )
                ],
                chunk_chars=1200,
                chunk_overlap=0,
                default_job_id="job-a",
            )

        old_records, _old_stats = direct_records(
            "Unchanged text", "https://example.test/old"
        )
        new_records, new_stats = direct_records(
            "Unchanged text", "https://example.test/new"
        )
        old_state = build_page_state_record(
            doc_id=artifact_doc_id,
            records=old_records,
            collection="direct-documents",
            neo4j_database=None,
        )
        new_state = build_page_state_record(
            doc_id=artifact_doc_id,
            records=new_records,
            collection="direct-documents",
            neo4j_database=None,
        )
        self.assertIsNotNone(old_state)
        self.assertIsNotNone(new_state)
        assert old_state is not None
        assert new_state is not None

        self.assertEqual(old_records[0]["doc_id"], artifact_doc_id)
        self.assertEqual(new_records[0]["doc_id"], artifact_doc_id)
        self.assertEqual(old_records[0]["payload"]["source_id"], source_id)
        self.assertEqual(new_records[0]["payload"]["source_id"], source_id)
        self.assertEqual(old_state.source_identity, f"doc:{artifact_doc_id}")
        self.assertEqual(new_state.source_identity, f"doc:{artifact_doc_id}")
        self.assertEqual(old_state.point_ids, new_state.point_ids)
        self.assertEqual(
            old_state.completion_fingerprint,
            new_state.completion_fingerprint,
        )
        self.assertEqual(
            new_records[0]["payload"]["source_url"], "https://example.test/new"
        )
        self.assertNotEqual(
            new_records[0]["doc_id"],
            "doc_" + hashlib.sha256(b"url:https://example.test/new").hexdigest()[:40],
        )

        class CompletedRegistry:
            def find_completed(
                self,
                *,
                collection: str,
                source_identity: str,
                completion_fingerprint: str,
                chunks_count: int,
                point_ids: tuple[str, ...],
            ) -> dict[str, object] | None:
                self.completed_lookup = (
                    collection,
                    source_identity,
                    completion_fingerprint,
                    chunks_count,
                    point_ids,
                )
                if completion_fingerprint != old_state.completion_fingerprint:
                    return None
                return dict(old_records[0]["payload"])

            def find_by_source_identity(
                self, *, collection: str, source_identity: str
            ) -> dict[str, object]:
                self.identity_lookup = (collection, source_identity)
                return dict(old_records[0]["payload"])

        class UnusedQdrant:
            def find_points_by_payload(
                self, filters: dict[str, object], *, limit: int = 1
            ) -> list[dict[str, object]]:
                raise AssertionError(
                    "stable registry identity should resolve the retry"
                )

        registry = CompletedRegistry()
        replay_plan = plan_incremental_ingest(
            new_records,
            doc_stats=new_stats,
            qdrant=UnusedQdrant(),
            collection="direct-documents",
            operation_id="url-metadata-change",
            logger_obj=logging.getLogger("test_direct_url_change"),
            page_registry=registry,
        )

        self.assertEqual(replay_plan.unchanged_doc_ids, set())
        self.assertEqual(
            [record.doc_id for record in replay_plan.payload_refresh_page_records],
            [artifact_doc_id],
        )
        self.assertEqual(replay_plan.replace_doc_ids, set())
        self.assertEqual(registry.completed_lookup[1], f"doc:{artifact_doc_id}")

        changed_records, changed_stats = direct_records(
            "Changed text", "https://example.test/new"
        )
        replacement_plan = plan_incremental_ingest(
            changed_records,
            doc_stats=changed_stats,
            qdrant=UnusedQdrant(),
            collection="direct-documents",
            operation_id="content-change",
            logger_obj=logging.getLogger("test_direct_url_replacement"),
            page_registry=registry,
        )

        self.assertEqual(replacement_plan.changed_doc_ids, {artifact_doc_id})
        self.assertEqual(replacement_plan.replace_doc_ids, {artifact_doc_id})
        self.assertEqual(
            replacement_plan.replace_doc_ids_by_doc,
            {artifact_doc_id: {artifact_doc_id}},
        )
        self.assertEqual(registry.identity_lookup[1], f"doc:{artifact_doc_id}")

    def test_direct_text_without_url_keeps_artifact_document_identity(self) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents
        from hawki_rag_contracts.pipeline.identity import document_id

        artifact_doc_id = document_id("source-no-url", "document.md")
        records, stats = prepare_documents(
            [
                SimpleNamespace(
                    id=artifact_doc_id,
                    text="Direct text without a URL.",
                    payload={
                        "dataset_id": "dataset-a",
                        "source_id": "source-no-url",
                        "source_format": "markdown",
                        "ingestion_mode": "direct_text",
                    },
                )
            ],
            chunk_chars=1200,
            chunk_overlap=0,
            default_job_id="job-no-url",
        )
        records_with_url, _stats_with_url = prepare_documents(
            [
                SimpleNamespace(
                    id=artifact_doc_id,
                    text="Direct text without a URL.",
                    payload={
                        "dataset_id": "dataset-a",
                        "source_id": "source-no-url",
                        "source_url": "https://example.test/later",
                        "source_format": "markdown",
                        "ingestion_mode": "direct_text",
                    },
                )
            ],
            chunk_chars=1200,
            chunk_overlap=0,
            default_job_id="job-with-url",
        )

        self.assertEqual(records[0]["doc_id"], artifact_doc_id)
        self.assertEqual(records_with_url[0]["doc_id"], artifact_doc_id)
        self.assertEqual(
            records[0]["payload"]["source_identity"], f"doc:{artifact_doc_id}"
        )
        self.assertEqual(
            records_with_url[0]["payload"]["source_identity"],
            f"doc:{artifact_doc_id}",
        )
        self.assertNotIn("canonical_url", records[0]["payload"])
        self.assertEqual(stats["doc_ids"], [artifact_doc_id])

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
