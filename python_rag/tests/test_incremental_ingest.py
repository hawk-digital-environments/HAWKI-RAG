from __future__ import annotations

import hashlib
import logging
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


class IncrementalIngestTests(unittest.TestCase):
    def test_prepare_documents_assigns_stable_http_page_id_and_hash(self) -> None:
        from application.workflows.ingest.chunking import prepare_documents
        from application.workflows.ingest.incremental import page_identity_key

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
        expected_doc_id = f"doc_{hashlib.sha256(identity.encode('utf-8')).hexdigest()[:40]}"

        self.assertEqual(chunk_records[0]["doc_id"], expected_doc_id)
        self.assertEqual(payload["doc_id"], expected_doc_id)
        self.assertEqual(payload["source_document_id"], "transient-job-doc")
        self.assertEqual(payload["source_identity"], "url:https://example.test/Research?page=2")
        self.assertEqual(payload["canonical_url"], "https://example.test/Research?page=2")
        self.assertEqual(
            payload["content_hash"],
            hashlib.sha256("HAWKI uses Qdrant and Neo4j.".encode("utf-8")).hexdigest(),
        )
        self.assertEqual(stats["doc_ids"], [expected_doc_id])

    def test_prepare_documents_registers_upload_identity_without_changing_document_id(self) -> None:
        from application.workflows.ingest.chunking import prepare_documents
        from application.workflows.ingest.page_registry import build_page_registry_records

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

        registry_records = build_page_registry_records(
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
        from application.workflows.ingest.chunking import prepare_documents
        from application.workflows.ingest.incremental import plan_incremental_ingest

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
            def find_by_source_identity(self, *, collection: str, source_identity: str) -> dict[str, object]:
                self.lookup = (collection, source_identity)
                return {
                    "doc_id": "doc_upload_retry",
                    "content_hash": content_hash,
                    "status": "completed",
                }

        class FakeQdrant:
            def find_points_by_payload(self, filters: dict[str, object], *, limit: int = 1) -> list[dict[str, object]]:
                raise AssertionError("Qdrant fallback should not be used when the upload registry matches")

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
        self.assertEqual(plan.unchanged_page_records[0].source_identity, "doc:doc_upload_retry")

    def test_incremental_plan_skips_unchanged_and_marks_changed_old_doc_id_for_replace(self) -> None:
        from application.workflows.ingest.incremental import plan_incremental_ingest

        class FakeQdrant:
            def __init__(self) -> None:
                self.filters: list[dict[str, object]] = []

            def find_points_by_payload(self, filters: dict[str, object], *, limit: int = 1) -> list[dict[str, object]]:
                self.filters.append(filters)
                if filters.get("doc_id") == "doc-same":
                    return [{"payload": {"doc_id": "old-doc-same", "content_hash": "same-hash"}}]
                if filters.get("doc_id") == "doc-changed":
                    return [{"payload": {"doc_id": "old-doc-changed", "content_hash": "old-hash"}}]
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

        self.assertEqual([record["doc_id"] for record in plan.chunk_records], ["doc-changed", "doc-new"])
        self.assertEqual(plan.unchanged_doc_ids, {"doc-same"})
        self.assertEqual(plan.changed_doc_ids, {"doc-changed"})
        self.assertEqual(plan.new_doc_ids, {"doc-new"})
        self.assertEqual(plan.replace_doc_ids, {"doc-changed", "old-doc-changed"})
        self.assertEqual(plan.replace_doc_ids_by_doc, {"doc-changed": {"doc-changed", "old-doc-changed"}})
        self.assertEqual(doc_stats["processed_docs"], 2)
        self.assertEqual(doc_stats["skipped_docs"], 1)
        self.assertEqual(doc_stats["doc_ids"], ["doc-changed", "doc-new"])
        self.assertEqual(doc_stats["chunks_per_doc"], {"doc-changed": 1, "doc-new": 1})
        self.assertEqual(doc_stats["by_format"], {"markdown": 2})
        self.assertEqual(doc_stats["incremental_unchanged_docs"], 1)
        self.assertEqual(doc_stats["incremental_changed_docs"], 1)
        self.assertEqual(doc_stats["incremental_new_docs"], 1)

    def test_vector_commit_deletes_replaced_docs_before_upsert(self) -> None:
        from application.workflows.ingest.vector_commit import commit_vector_points

        class Provider:
            embed_model = "embed-test"

            def embed(self, text: str) -> list[float]:
                return [0.1, 0.2]

        class FakeQdrant:
            collection = "test_docs"

            def __init__(self) -> None:
                self.events: list[tuple[str, object]] = []

            def ensure_collection(self, vector_size: int, distance: str) -> None:
                self.events.append(("ensure", {"vector_size": vector_size, "distance": distance}))

            def delete_by_doc_id(self, doc_id: str, *, idempotency_key: str | None = None) -> None:
                self.events.append(("delete", {"doc_id": doc_id, "idempotency_key": idempotency_key}))

            def upsert_points(
                self,
                points: list[dict[str, object]],
                *,
                batch_size: int,
                idempotency_key: str | None = None,
            ) -> None:
                self.events.append(("upsert", {"points": points, "batch_size": batch_size, "idempotency_key": idempotency_key}))

        qdrant = FakeQdrant()
        result = commit_vector_points(
            body=SimpleNamespace(provider="fake", distance="Cosine"),
            chunk_records=[
                {
                    "doc_id": "doc-new",
                    "content": "changed page",
                    "payload": {"doc_id": "doc-new", "chunk_index": 0, "content_hash": "new"},
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

        self.assertEqual([event[0] for event in qdrant.events], ["ensure", "delete", "delete", "upsert"])
        self.assertEqual(qdrant.events[1][1]["doc_id"], "doc-new")
        self.assertEqual(qdrant.events[2][1]["doc_id"], "old-doc")
        self.assertEqual(qdrant.events[3][1]["batch_size"], 16)
        self.assertEqual(result.vector_size, 2)

    def test_graph_ingest_deletes_replaced_doc_ids_before_merge(self) -> None:
        from application.workflows.ingest.graph_ingest import build_triplets_by_doc
        from application.workflows.ingest.settings import GraphIngestSettings

        class FakeGraph:
            def __init__(self) -> None:
                self.events: list[tuple[str, object]] = []

            def delete_by_doc_id(self, doc_id: str, *, request_id: str | None = None) -> None:
                self.events.append(("delete", {"doc_id": doc_id, "request_id": request_id}))

            def upsert_triplets(
                self,
                triplets: list[tuple[str, str, str]],
                *,
                doc_id: str | None = None,
                request_id: str | None = None,
            ) -> None:
                self.events.append(("upsert", {"triplets": triplets, "doc_id": doc_id, "request_id": request_id}))

        class FakeRAGService:
            def extract_triplets(self, text: str, engine: str, **kwargs: object) -> list[tuple[str, str, str]]:
                return [("HAWKI", "uses", "Neo4j")]

        graph = FakeGraph()
        settings = GraphIngestSettings(
            graph_debug=False,
            graph_perf_log=False,
            graph_doc_timeout_s=0.0,
            graph_doc_max_chars=0,
            graph_doc_max_chunks=0,
            graph_failure_log="",
        )

        with patch(
            "application.workflows.ingest.graph_ingest.filter_triplets_to_source",
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

        self.assertEqual([event[0] for event in graph.events], ["delete", "delete", "upsert"])
        self.assertEqual(graph.events[0][1]["doc_id"], "doc-new")
        self.assertEqual(graph.events[1][1]["doc_id"], "old-doc")
        self.assertEqual(graph.events[2][1]["doc_id"], "doc-new")

    def test_ingest_documents_returns_success_when_every_page_is_unchanged(self) -> None:
        from application.workflows.ingest.dependencies import IngestWorkflowDependencies
        from application.workflows.ingest.settings import GraphIngestSettings
        from application.workflows.ingest_logic import ingest_documents

        text = "HAWKI uses Qdrant."
        content_hash = hashlib.sha256(text.encode("utf-8")).hexdigest()

        class FakeQdrant:
            collection = "test_docs"

            def set_collection(self, collection: str) -> None:
                self.collection = collection

            def find_points_by_payload(self, filters: dict[str, object], *, limit: int = 1) -> list[dict[str, object]]:
                raise AssertionError("Qdrant fallback should not be used when registry has the page")

        class FakePageRegistry:
            def __init__(self) -> None:
                self.seen: list[object] = []

            def find_by_source_identity(self, *, collection: str, source_identity: str) -> dict[str, object]:
                self.lookup = {"collection": collection, "source_identity": source_identity}
                return {"doc_id": "registered-doc", "content_hash": content_hash, "status": "completed"}

            def mark_seen(self, records: list[object]) -> None:
                self.seen.extend(records)

        body = SimpleNamespace(
            docs=[
                SimpleNamespace(
                    id="transient-doc",
                    text=text,
                    payload={
                        "title": "HAWKI",
                        "page_url": "https://example.test/hawki",
                        "source_format": "markdown",
                    },
                )
            ],
            dry_run=False,
            provider="fake",
            collection="test_docs",
            graph=False,
            graph_engine="raganything",
            graph_only=False,
            chunk_chars=1200,
            chunk_overlap=0,
            batch_size=64,
            distance="Cosine",
            neo4j_database=None,
            embedding_model=None,
            graph_model=None,
            idempotency_key="op-unchanged",
            job_id="job-unchanged",
        )
        page_registry = FakePageRegistry()
        dependencies = IngestWorkflowDependencies(
            qdrant_factory=FakeQdrant,
            page_registry_factory=lambda: page_registry,
            graph_settings_loader=lambda: GraphIngestSettings(
                graph_debug=False,
                graph_perf_log=False,
                graph_doc_timeout_s=0.0,
                graph_doc_max_chars=0,
                graph_doc_max_chunks=0,
                graph_failure_log="",
            ),
        )

        with tempfile.TemporaryDirectory() as tmp:
            result = ingest_documents(
                body,
                rag_service=object(),
                get_provider=lambda name: (_ for _ in ()).throw(AssertionError("provider should not be called")),
                public_dir=Path(tmp),
                dependencies=dependencies,
            )

        self.assertTrue(result["ok"])
        self.assertEqual(result["points"], 0)
        self.assertEqual(result["summary"]["documents"]["processed_docs"], 0)
        self.assertEqual(result["summary"]["documents"]["skipped_docs"], 1)
        self.assertEqual(result["summary"]["documents"]["incremental_unchanged_docs"], 1)
        self.assertEqual(result["summary"]["documents"]["incremental_registry_hits"], 1)
        self.assertEqual(page_registry.lookup["collection"], "test_docs")
        self.assertEqual(page_registry.lookup["source_identity"], "url:https://example.test/hawki")
        self.assertEqual(len(page_registry.seen), 1)

    def test_ingest_documents_marks_new_pages_completed_in_registry_after_qdrant_write(self) -> None:
        from application.workflows.ingest.dependencies import IngestWorkflowDependencies
        from application.workflows.ingest.settings import GraphIngestSettings
        from application.workflows.ingest_logic import ingest_documents

        class Provider:
            embed_model = "embed-test"

            def embed(self, text: str) -> list[float]:
                return [0.1, 0.2]

        class FakeQdrant:
            collection = "test_docs"

            def __init__(self) -> None:
                self.upserts: list[list[dict[str, object]]] = []

            def set_collection(self, collection: str) -> None:
                self.collection = collection

            def find_points_by_payload(self, filters: dict[str, object], *, limit: int = 1) -> list[dict[str, object]]:
                return []

            def ensure_collection(self, vector_size: int, distance: str) -> None:
                self.vector_size = vector_size

            def upsert_points(
                self,
                points: list[dict[str, object]],
                *,
                batch_size: int,
                idempotency_key: str | None = None,
            ) -> None:
                self.upserts.append(points)

        class FakePageRegistry:
            def __init__(self) -> None:
                self.completed: list[object] = []

            def find_by_source_identity(self, *, collection: str, source_identity: str) -> None:
                return None

            def mark_completed(self, records: list[object]) -> None:
                self.completed.extend(records)

        qdrant = FakeQdrant()
        page_registry = FakePageRegistry()
        body = SimpleNamespace(
            docs=[
                SimpleNamespace(
                    id="transient-doc",
                    text="New HAWKI page.",
                    payload={
                        "title": "New HAWKI",
                        "page_url": "https://example.test/new-hawki",
                        "source_format": "markdown",
                        "task_id": "task-1",
                    },
                )
            ],
            dry_run=False,
            provider="fake",
            collection="test_docs",
            graph=False,
            graph_engine="raganything",
            graph_only=False,
            chunk_chars=1200,
            chunk_overlap=0,
            batch_size=64,
            distance="Cosine",
            neo4j_database="neo4j",
            neo4j_namespace="student_graph",
            embedding_model=None,
            graph_model=None,
            idempotency_key="op-new",
            job_id="job-new",
        )
        dependencies = IngestWorkflowDependencies(
            qdrant_factory=lambda: qdrant,
            page_registry_factory=lambda: page_registry,
            graph_settings_loader=lambda: GraphIngestSettings(
                graph_debug=False,
                graph_perf_log=False,
                graph_doc_timeout_s=0.0,
                graph_doc_max_chars=0,
                graph_doc_max_chunks=0,
                graph_failure_log="",
            ),
        )

        with tempfile.TemporaryDirectory() as tmp:
            result = ingest_documents(
                body,
                rag_service=object(),
                get_provider=lambda name: Provider(),
                public_dir=Path(tmp),
                dependencies=dependencies,
            )

        self.assertTrue(result["ok"])
        self.assertEqual(result["points"], 1)
        self.assertEqual(len(qdrant.upserts), 1)
        self.assertEqual(len(page_registry.completed), 1)
        completed = page_registry.completed[0]
        self.assertEqual(completed.collection, "test_docs")
        self.assertEqual(completed.source_identity, "url:https://example.test/new-hawki")
        self.assertEqual(completed.task_id, "task-1")
        self.assertEqual(completed.job_id, "job-new")
        self.assertEqual(completed.neo4j_database, "student_graph")

    def test_three_documents_share_one_collection_and_delete_removes_only_target_document_points(self) -> None:
        from application.workflows.ingest.chunking import prepare_documents
        from application.workflows.ingest.deletion import delete_document_entries
        from application.workflows.ingest.vector_commit import commit_vector_points

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
                bucket = self.points_by_collection.get(collection or self.collection, [])
                return sum(
                    1
                    for point in bucket
                    if str((point.get("payload") or {}).get("doc_id") or "") == doc_id
                )

            def delete_by_doc_id(self, doc_id: str, *, idempotency_key: str | None = None) -> dict[str, object]:
                bucket = self.points_by_collection.get(self.collection, [])
                kept = [
                    point for point in bucket
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

            def delete_by_doc_id(self, doc_id: str, *, request_id: str | None = None) -> dict[str, int]:
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
            self.assertIn(payload["managed_document_id"], {"adoc-alpha", "adoc-beta", "adoc-gamma"})
            self.assertTrue(str(payload["source_id"]).startswith("source-"))
            self.assertTrue(str(payload["doc_id"]))
            graph_writer.upsert_triplets([("Student", "uploaded", str(payload["managed_document_id"]))], doc_id=str(payload["doc_id"]))

        target_points_before = qdrant.count_points_by_doc_id(target_doc_id, collection="student_space")
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
        self.assertEqual(delete_result["qdrant"]["deleted_points"], target_points_before)
        self.assertEqual(delete_result["neo4j"]["namespace"], "student_graph")
        self.assertEqual(qdrant.count_points_by_doc_id(target_doc_id, collection="student_space"), 0)
        for doc_id, count in kept_points_before.items():
            self.assertEqual(qdrant.count_points_by_doc_id(doc_id, collection="student_space"), count)
        self.assertEqual(qdrant.total_points("student_space"), sum(kept_points_before.values()))
        self.assertNotIn(target_doc_id, graph_state["student_graph"])
        self.assertTrue(all(doc_id in graph_state["student_graph"] for doc_id in kept_doc_ids))


if __name__ == "__main__":
    unittest.main()
