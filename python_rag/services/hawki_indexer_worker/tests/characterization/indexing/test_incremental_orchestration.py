"""Incremental-ingestion scenarios from stable identity through scoped vector and graph writes."""

from __future__ import annotations

import hashlib
import tempfile
import unittest
from types import SimpleNamespace


class IncrementalIngestTests(unittest.TestCase):
    """Verify retries, replacements, registries, and deletions preserve document isolation."""

    def test_ingest_documents_returns_success_when_every_page_is_unchanged(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.dependencies import (
            IngestWorkflowDependencies,
        )
        from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
        from hawki_indexer_worker.indexing.orchestration import ingest_documents

        text = "HAWKI uses Qdrant."
        content_hash = hashlib.sha256(text.encode("utf-8")).hexdigest()

        class FakeQdrant:
            collection = "test_docs"

            def set_collection(self, collection: str) -> None:
                self.collection = collection

            def find_points_by_payload(
                self, filters: dict[str, object], *, limit: int = 1
            ) -> list[dict[str, object]]:
                raise AssertionError(
                    "Qdrant fallback should not be used when registry has the page"
                )

        class FakePageRegistry:
            def __init__(self) -> None:
                self.seen: list[object] = []

            def find_by_source_identity(
                self, *, collection: str, source_identity: str
            ) -> dict[str, object]:
                self.lookup = {
                    "collection": collection,
                    "source_identity": source_identity,
                }
                return {
                    "doc_id": "registered-doc",
                    "content_hash": content_hash,
                    "status": "completed",
                }

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
            vector_writer_factory=FakeQdrant,
            graph_writer_factory=lambda **_kwargs: None,
            page_state_factory=lambda _qdrant: page_registry,
            graph_settings_loader=lambda: GraphIngestSettings(
                graph_debug=False,
                graph_perf_log=False,
                graph_doc_timeout_s=0.0,
                graph_doc_max_chars=0,
                graph_doc_max_chunks=0,
            ),
        )

        with tempfile.TemporaryDirectory():
            result = ingest_documents(
                body,
                rag_service=object(),
                get_provider=lambda name: (_ for _ in ()).throw(
                    AssertionError("provider should not be called")
                ),
                dependencies=dependencies,
            )

        self.assertTrue(result["ok"])
        self.assertEqual(result["points"], 0)
        self.assertEqual(result["summary"]["documents"]["processed_docs"], 0)
        self.assertEqual(result["summary"]["documents"]["skipped_docs"], 1)
        self.assertEqual(
            result["summary"]["documents"]["incremental_unchanged_docs"], 1
        )
        self.assertEqual(result["summary"]["documents"]["incremental_registry_hits"], 1)
        self.assertEqual(page_registry.lookup["collection"], "test_docs")
        self.assertEqual(
            page_registry.lookup["source_identity"], "url:https://example.test/hawki"
        )
        self.assertEqual(len(page_registry.seen), 1)

    def test_ingest_documents_marks_new_pages_completed_in_registry_after_qdrant_write(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.dependencies import (
            IngestWorkflowDependencies,
        )
        from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
        from hawki_indexer_worker.indexing.orchestration import ingest_documents

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

            def find_points_by_payload(
                self, filters: dict[str, object], *, limit: int = 1
            ) -> list[dict[str, object]]:
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

            def find_by_source_identity(
                self, *, collection: str, source_identity: str
            ) -> None:
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
            vector_writer_factory=lambda: qdrant,
            graph_writer_factory=lambda **_kwargs: None,
            page_state_factory=lambda _qdrant: page_registry,
            graph_settings_loader=lambda: GraphIngestSettings(
                graph_debug=False,
                graph_perf_log=False,
                graph_doc_timeout_s=0.0,
                graph_doc_max_chars=0,
                graph_doc_max_chunks=0,
            ),
        )

        with tempfile.TemporaryDirectory():
            result = ingest_documents(
                body,
                rag_service=object(),
                get_provider=lambda name: Provider(),
                dependencies=dependencies,
            )

        self.assertTrue(result["ok"])
        self.assertEqual(result["points"], 1)
        self.assertEqual(len(qdrant.upserts), 1)
        self.assertEqual(len(page_registry.completed), 1)
        completed = page_registry.completed[0]
        self.assertEqual(completed.collection, "test_docs")
        self.assertEqual(
            completed.source_identity, "url:https://example.test/new-hawki"
        )
        self.assertEqual(completed.task_id, "task-1")
        self.assertEqual(completed.job_id, "job-new")
        self.assertEqual(completed.neo4j_database, "student_graph")
