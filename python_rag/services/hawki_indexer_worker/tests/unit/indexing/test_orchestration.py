from __future__ import annotations

from pathlib import Path
from typing import Any
from types import SimpleNamespace

import pytest

from hawki_indexer_worker.domain.errors import IndexingValidationError
from hawki_indexer_worker.domain.models import IngestDocument
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.orchestration import ingest_documents
from hawki_indexer_worker.indexing.page_state import QdrantPageState
from hawki_indexer_worker.indexing.request import IndexRequest


class RecordingProvider:
    embed_model = "initial"
    rag_model = "chat"
    vision_model = "vision"

    def __init__(self) -> None:
        self.embedded: list[str] = []

    def embed(self, text: str) -> list[float]:
        self.embedded.append(text)
        return [0.1, 0.2, 0.3]


class MemoryQdrant:
    def __init__(self) -> None:
        self.collection = "default"
        self.points: list[dict[str, Any]] = []
        self.operations: list[tuple[str, str | None]] = []

    def set_collection(self, collection: str) -> None:
        self.collection = collection

    def find_points_by_payload(
        self, filters: dict[str, Any], *, limit: int = 1
    ) -> list[dict[str, Any]]:
        matches = [
            point
            for point in self.points
            if all(
                point.get("payload", {}).get(key) == value
                for key, value in filters.items()
            )
        ]
        return matches[:limit]

    def ensure_collection(self, vector_size: int, *, distance: str) -> None:
        assert vector_size == 3
        assert distance == "Cosine"

    def upsert_points(
        self,
        points: list[dict[str, Any]],
        *,
        batch_size: int,
        idempotency_key: str | None = None,
    ) -> None:
        assert batch_size > 0
        self.operations.append(("upsert", idempotency_key))
        self.points.extend(points)

    def delete_by_doc_id(
        self, doc_id: str, *, idempotency_key: str | None = None
    ) -> dict[str, Any]:
        self.operations.append(("delete", idempotency_key))
        self.points = [
            point
            for point in self.points
            if point.get("payload", {}).get("doc_id") != doc_id
        ]
        return {"status": "ok"}


def _request(text: str, *, operation: str) -> IndexRequest:
    return IndexRequest(
        docs=[
            IngestDocument(
                id="source-document",
                text=text,
                payload={
                    "title": "Page",
                    "source_url": "https://example.test/page",
                    "source_format": "markdown",
                    "job_id": "job-1",
                },
            )
        ],
        provider="ollama",
        embedding_model="bge-m3",
        collection="dataset-1",
        idempotency_key=operation,
    )


def _dependencies(qdrant: MemoryQdrant) -> IngestWorkflowDependencies:
    return IngestWorkflowDependencies(
        vector_writer_factory=lambda: qdrant,
        graph_writer_factory=lambda **_kwargs: None,
        page_state_factory=QdrantPageState,
    )


def test_ingestion_recovery_retries_graph_after_vectors_already_succeeded(
    monkeypatch,
) -> None:
    from hawki_indexer_worker.indexing import orchestration

    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    dependencies = _dependencies(qdrant)
    graph_attempts = []

    def commit_graph(**kwargs):
        graph_attempts.append(kwargs["chunk_records"])
        if len(graph_attempts) == 1:
            raise RuntimeError("Neo4j unavailable after vector write")
        return SimpleNamespace(graph_preview=None, graph_failures=[], neo4j_ms=1.0)

    monkeypatch.setattr(orchestration, "commit_graph_triplets", commit_graph)
    request = _request("saved Markdown", operation="operation-recovery")
    request.graph = True
    request.dataset_id = "dataset-1"
    request.neo4j_namespace = "dataset-1"
    with pytest.raises(RuntimeError, match="Neo4j unavailable"):
        ingest_documents(
            request,
            rag_service=object(),
            get_provider=lambda _: provider,
            dependencies=dependencies,
        )
    assert qdrant.points

    request.reprocess_unchanged = True
    result = ingest_documents(
        request,
        rag_service=object(),
        get_provider=lambda _: provider,
        dependencies=dependencies,
    )

    assert result["ok"]
    assert len(graph_attempts) == 2
    assert graph_attempts[1]
    assert len(qdrant.points) == 1


def test_only_ingestion_recovery_reprocesses_unchanged_content() -> None:
    for stage in (None, "scrape", "convert", "ingest"):
        workflow_input = {"resume": {"stage": stage}} if stage else {}
        request = IndexRequest.from_options(
            [],
            workflow_input=workflow_input,
            options={},
            operation_id="operation",
        )
        assert request.reprocess_unchanged is (stage == "ingest")


def test_incremental_ingestion_skips_retry_and_replaces_changed_content(
    tmp_path: Path,
) -> None:
    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    dependencies = _dependencies(qdrant)

    def resolve_provider(_name: str) -> RecordingProvider:
        return provider

    first = ingest_documents(
        _request("first version", operation="op-1"),
        rag_service=object(),
        get_provider=resolve_provider,
        dependencies=dependencies,
    )
    retry = ingest_documents(
        _request("first version", operation="op-1"),
        rag_service=object(),
        get_provider=resolve_provider,
        dependencies=dependencies,
    )
    changed = ingest_documents(
        _request("changed version", operation="op-2"),
        rag_service=object(),
        get_provider=resolve_provider,
        dependencies=dependencies,
    )

    assert first["points"] == 1
    assert retry["points"] == 0
    assert retry["summary"]["documents"]["incremental_unchanged_docs"] == 1
    assert changed["summary"]["documents"]["incremental_changed_docs"] == 1
    assert [operation for operation, _key in qdrant.operations] == [
        "upsert",
        "delete",
        "upsert",
    ]
    assert qdrant.operations[1][1] is not None
    assert len(provider.embedded) == 2


def test_graph_request_requires_trusted_dataset_scope() -> None:
    with pytest.raises(IndexingValidationError, match="dataset_id"):
        IndexRequest(
            docs=[IngestDocument(id="doc", text="content")],
            graph=True,
            collection="dataset-1",
        )


def test_qdrant_page_state_reads_content_payload_without_sql() -> None:
    qdrant = MemoryQdrant()
    qdrant.points.append(
        {
            "id": "point-1",
            "payload": {
                "source_identity": "url:https://example.test/page",
                "doc_id": "doc-1",
                "content_hash": "abc",
            },
        }
    )
    state = QdrantPageState(qdrant)
    assert state.find_by_source_identity(
        collection="dataset-1",
        source_identity="url:https://example.test/page",
    ) == {
        "source_identity": "url:https://example.test/page",
        "doc_id": "doc-1",
        "content_hash": "abc",
    }
