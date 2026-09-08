from __future__ import annotations

import logging
from typing import Any
import uuid

import pytest

from hawki_indexer_worker.domain.errors import EmbeddingError
from hawki_indexer_worker.domain.models import IngestDocument
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.incremental import plan_incremental_ingest
from hawki_indexer_worker.indexing.orchestration import ingest_documents
from hawki_indexer_worker.indexing.page_state import (
    QdrantPageState,
    build_page_state_record,
)
from hawki_indexer_worker.indexing.request import IndexRequest


class RecordingProvider:
    embed_model = "embed-test"

    def __init__(self, *, fail_on: str | None = None) -> None:
        self.fail_on = fail_on
        self.embedded: list[str] = []

    def embed(self, text: str) -> list[float]:
        self.embedded.append(text)
        if text == self.fail_on:
            raise RuntimeError("provider response included sensitive details")
        return [0.1, 0.2, 0.3]


class MemoryQdrant:
    def __init__(self) -> None:
        self.collection = "default"
        self.points: dict[str, dict[str, Any]] = {}
        self.upsert_batches: list[list[str]] = []
        self.fail_on_batch: int | None = None
        self.fail_completion = False
        self.completion_writes = 0
        self.delete_calls = 0

    def set_collection(self, collection: str) -> None:
        self.collection = collection

    def find_points_by_payload(
        self,
        filters: dict[str, Any],
        *,
        limit: int = 1,
    ) -> list[dict[str, Any]]:
        matches = [
            point
            for point in self.points.values()
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
        del idempotency_key
        for batch_number, start in enumerate(
            range(0, len(points), batch_size), start=1
        ):
            batch = points[start : start + batch_size]
            self.upsert_batches.append([str(point["id"]) for point in batch])
            if self.fail_on_batch == batch_number:
                raise RuntimeError("Qdrant batch failed")
            for point in batch:
                self.points[str(point["id"])] = {
                    **point,
                    "payload": dict(point["payload"]),
                }

    def set_payload(
        self,
        point_ids: list[str],
        payload: dict[str, Any],
        *,
        idempotency_key: str | None = None,
    ) -> None:
        del idempotency_key
        if self.fail_completion:
            raise RuntimeError("completion write failed")
        self.completion_writes += 1
        for point_id in point_ids:
            self.points[point_id]["payload"].update(payload)

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        idempotency_key: str | None = None,
    ) -> dict[str, str]:
        del idempotency_key
        self.delete_calls += 1
        self.points = {
            point_id: point
            for point_id, point in self.points.items()
            if point.get("payload", {}).get("doc_id") != doc_id
        }
        return {"status": "ok"}

    def completion_points(self) -> list[dict[str, Any]]:
        return [
            point
            for point in self.points.values()
            if point.get("payload", {}).get("rawki_document_complete") is True
        ]


def _request(
    text: str,
    *,
    operation_id: str,
    chunk_chars: int = 5,
) -> IndexRequest:
    return IndexRequest(
        docs=[
            IngestDocument(
                id="direct-document",
                text=text,
                payload={
                    "ingestion_mode": "direct_text",
                    "source_format": "markdown",
                    "source_id": "source-direct",
                    "job_id": "job-direct",
                },
            )
        ],
        provider="fake",
        collection="direct-text",
        chunk_chars=chunk_chars,
        chunk_overlap=0,
        batch_size=2,
        idempotency_key=operation_id,
    )


def _dependencies(qdrant: MemoryQdrant) -> IngestWorkflowDependencies:
    return IngestWorkflowDependencies(
        vector_writer_factory=lambda: qdrant,
        graph_writer_factory=lambda **_kwargs: None,
        page_state_factory=QdrantPageState,
    )


def _expected_point_ids() -> set[str]:
    return {
        str(uuid.uuid5(uuid.NAMESPACE_URL, f"direct-document:{index}"))
        for index in range(3)
    }


def test_partial_qdrant_batch_is_reprocessed_before_completion() -> None:
    qdrant = MemoryQdrant()
    qdrant.fail_on_batch = 2
    provider = RecordingProvider()

    with pytest.raises(RuntimeError, match="Qdrant batch failed"):
        ingest_documents(
            _request("AAAAABBBBBCCCCC", operation_id="attempt-1"),
            rag_service=object(),
            get_provider=lambda _name: provider,
            dependencies=_dependencies(qdrant),
        )

    first_batch_ids = set(qdrant.points)
    assert len(first_batch_ids) == 2
    assert qdrant.completion_points() == []

    qdrant.fail_on_batch = None
    retry = ingest_documents(
        _request("AAAAABBBBBCCCCC", operation_id="attempt-2"),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    assert retry["ok"] is True
    assert set(qdrant.points) == _expected_point_ids()
    assert first_batch_ids.issubset(set(qdrant.upsert_batches[-2]))
    assert set(qdrant.upsert_batches[-2] + qdrant.upsert_batches[-1]) == (
        _expected_point_ids()
    )
    assert qdrant.delete_calls == 0
    assert len(qdrant.completion_points()) == 3

    embedding_calls = len(provider.embedded)
    replay = ingest_documents(
        _request("AAAAABBBBBCCCCC", operation_id="attempt-3"),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    assert replay["points"] == 0
    assert replay["summary"]["documents"]["incremental_unchanged_docs"] == 1
    assert len(provider.embedded) == embedding_calls


def test_one_matching_direct_text_point_does_not_prove_completion() -> None:
    qdrant = MemoryQdrant()
    content_hash = "a" * 64
    records = [
        {
            "doc_id": "direct-document",
            "content": f"chunk-{index}",
            "payload": {
                "doc_id": "direct-document",
                "chunk_index": index,
                "content_hash": content_hash,
                "source_identity": "doc:direct-document",
                "ingestion_mode": "direct_text",
            },
        }
        for index in range(3)
    ]
    qdrant.points["partial-point"] = {
        "id": "partial-point",
        "payload": dict(records[0]["payload"]),
    }
    doc_stats: dict[str, Any] = {
        "processed_docs": 1,
        "skipped_docs": 0,
        "doc_ids": ["direct-document"],
        "chunks_per_doc": {"direct-document": 3},
    }

    plan = plan_incremental_ingest(
        records,
        doc_stats=doc_stats,
        qdrant=qdrant,
        collection="direct-text",
        operation_id="retry",
        logger_obj=logging.getLogger("test_direct_completion"),
        page_registry=QdrantPageState(qdrant),
    )

    assert plan.unchanged_doc_ids == set()
    assert plan.chunk_records == records


def test_direct_text_embedding_failure_aborts_before_qdrant_commit(caplog) -> None:
    qdrant = MemoryQdrant()

    with caplog.at_level(logging.ERROR):
        with pytest.raises(EmbeddingError, match="direct-text document"):
            ingest_documents(
                _request("AAAAABBBBBCCCCC", operation_id="embedding-failure"),
                rag_service=object(),
                get_provider=lambda _name: RecordingProvider(fail_on="BBBBB"),
                dependencies=_dependencies(qdrant),
            )

    assert qdrant.points == {}
    assert qdrant.upsert_batches == []
    assert qdrant.completion_points() == []
    assert "sensitive details" not in caplog.text

    result = ingest_documents(
        _request("AAAAABBBBBCCCCC", operation_id="embedding-retry"),
        rag_service=object(),
        get_provider=lambda _name: RecordingProvider(),
        dependencies=_dependencies(qdrant),
    )

    assert result["ok"] is True
    assert set(qdrant.points) == _expected_point_ids()
    assert len(qdrant.completion_points()) == 3


def test_direct_text_fails_if_completion_cannot_be_published() -> None:
    qdrant = MemoryQdrant()
    qdrant.fail_completion = True

    with pytest.raises(RuntimeError, match="completion"):
        ingest_documents(
            _request("AAAAABBBBBCCCCC", operation_id="completion-failure"),
            rag_service=object(),
            get_provider=lambda _name: RecordingProvider(),
            dependencies=_dependencies(qdrant),
        )

    assert set(qdrant.points) == _expected_point_ids()
    assert qdrant.completion_points() == []

    qdrant.fail_completion = False
    result = ingest_documents(
        _request("AAAAABBBBBCCCCC", operation_id="completion-retry"),
        rag_service=object(),
        get_provider=lambda _name: RecordingProvider(),
        dependencies=_dependencies(qdrant),
    )

    assert result["ok"] is True
    assert set(qdrant.points) == _expected_point_ids()
    assert len(qdrant.completion_points()) == 3


def test_duplicate_completion_publication_is_idempotent() -> None:
    qdrant = MemoryQdrant()
    ingest_documents(
        _request("AAAAABBBBBCCCCC", operation_id="initial-completion"),
        rag_service=object(),
        get_provider=lambda _name: RecordingProvider(),
        dependencies=_dependencies(qdrant),
    )
    records = [
        {
            "doc_id": "direct-document",
            "content": chunk,
            "payload": {
                **qdrant.points[point_id]["payload"],
                "chunk_index": index,
            },
        }
        for index, (point_id, chunk) in enumerate(
            zip(sorted(_expected_point_ids()), ["AAAAA", "BBBBB", "CCCCC"], strict=True)
        )
    ]
    record = build_page_state_record(
        doc_id="direct-document",
        records=records,
        collection="direct-text",
        neo4j_database=None,
    )
    assert record is not None
    state = QdrantPageState(qdrant)
    before = [dict(point["payload"]) for point in qdrant.completion_points()]

    state.mark_completed([record])
    state.mark_completed([record])

    assert qdrant.completion_writes == 3
    assert [dict(point["payload"]) for point in qdrant.completion_points()] == before


def test_changed_chunk_shape_removes_stale_completed_points() -> None:
    qdrant = MemoryQdrant()
    ingest_documents(
        _request("AAAAABBBBBCCCCC", operation_id="three-chunks"),
        rag_service=object(),
        get_provider=lambda _name: RecordingProvider(),
        dependencies=_dependencies(qdrant),
    )

    result = ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="two-chunks",
            chunk_chars=10,
        ),
        rag_service=object(),
        get_provider=lambda _name: RecordingProvider(),
        dependencies=_dependencies(qdrant),
    )

    assert result["ok"] is True
    assert set(qdrant.points) == {
        str(uuid.uuid5(uuid.NAMESPACE_URL, f"direct-document:{index}"))
        for index in range(2)
    }
    assert qdrant.delete_calls == 1
    assert len(qdrant.completion_points()) == 2
