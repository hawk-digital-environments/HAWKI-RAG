from __future__ import annotations

import logging
from typing import Any
import uuid

import pytest

from hawki_indexer_worker.domain.errors import DocumentCompletionError, EmbeddingError
from hawki_indexer_worker.indexing.incremental import plan_incremental_ingest
from hawki_indexer_worker.indexing.orchestration import ingest_documents
from hawki_indexer_worker.indexing.page_state import (
    QdrantPageState,
    build_page_state_record,
    direct_text_metadata_fingerprint,
)
from services.hawki_indexer_worker.tests.unit.indexing.direct_text_test_support import (
    MemoryQdrant,
    RecordingProvider,
    direct_text_dependencies as _dependencies,
    direct_text_request as _request,
    expected_point_ids as _expected_point_ids,
)


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


def test_same_text_and_metadata_skips_all_vector_and_payload_writes() -> None:
    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    request = _request(
        "AAAAABBBBBCCCCC",
        operation_id="initial",
        metadata={"audience": "students", "nested": {"a": 1, "b": 2}},
    )
    ingest_documents(
        request,
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )
    embeddings_before = list(provider.embedded)
    batches_before = list(qdrant.upsert_batches)
    completion_writes_before = qdrant.completion_writes

    result = ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="replay",
            metadata={"nested": {"b": 2, "a": 1}, "audience": "students"},
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    assert result["points"] == 0
    assert result["summary"]["documents"]["incremental_unchanged_docs"] == 1
    assert provider.embedded == embeddings_before
    assert qdrant.upsert_batches == batches_before
    assert qdrant.payload_refresh_writes == 0
    assert qdrant.completion_writes == completion_writes_before


def test_metadata_fingerprint_sorts_objects_and_preserves_list_order() -> None:
    first = {
        "metadata": {"nested": {"a": 1, "b": 2}, "ordered": ["one", "two"]},
        "content": "first chunk",
        "job_id": "job-1",
    }
    reordered = {
        "metadata": {"ordered": ["one", "two"], "nested": {"b": 2, "a": 1}},
        "content": "different chunk",
        "job_id": "job-2",
        "workflow_id": "workflow-2",
    }
    list_changed = {"metadata": {"nested": {"a": 1, "b": 2}, "ordered": ["two", "one"]}}

    assert direct_text_metadata_fingerprint(first) == direct_text_metadata_fingerprint(
        reordered
    )
    assert direct_text_metadata_fingerprint(first) != direct_text_metadata_fingerprint(
        list_changed
    )


def test_metadata_only_change_refreshes_every_point_without_embedding() -> None:
    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="initial",
            metadata={"audience": "students"},
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )
    point_ids = set(qdrant.points)
    vectors = {
        point_id: list(point["vector"]) for point_id, point in qdrant.points.items()
    }
    embeddings_before = list(provider.embedded)

    result = ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="metadata-change",
            metadata={"audience": "staff", "labels": ["one", "two"]},
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    assert result["points"] == 0
    assert set(qdrant.points) == point_ids
    assert provider.embedded == embeddings_before
    assert qdrant.payload_refresh_writes == 1
    for point_id, point in qdrant.points.items():
        assert point["vector"] == vectors[point_id]
        assert point["payload"]["metadata"] == {
            "audience": "staff",
            "labels": ["one", "two"],
        }
        assert point["payload"]["rawki_document_complete"] is True


def test_display_name_change_refreshes_title_without_embedding() -> None:
    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="initial",
            display_name="Old title",
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )
    embeddings_before = list(provider.embedded)

    ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="rename",
            display_name="New title",
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    assert provider.embedded == embeddings_before
    assert qdrant.payload_refresh_writes == 1
    assert {point["payload"]["display_name"] for point in qdrant.points.values()} == {
        "New title"
    }
    assert {point["payload"]["title"] for point in qdrant.points.values()} == {
        "New title"
    }


def test_source_url_change_preserves_direct_identity_and_vectors() -> None:
    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="initial",
            source_url="https://example.test/old",
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )
    point_ids = set(qdrant.points)
    embeddings_before = list(provider.embedded)

    ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="url-change",
            source_url="https://example.test/new",
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    assert set(qdrant.points) == point_ids
    assert provider.embedded == embeddings_before
    assert qdrant.delete_calls == 0
    for point in qdrant.points.values():
        assert point["payload"]["doc_id"] == "direct-document"
        assert point["payload"]["source_url"] == "https://example.test/new"
        assert point["payload"]["canonical_url"] == "https://example.test/new"


def test_partial_metadata_refresh_is_reapplied_before_completion() -> None:
    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="initial",
            metadata={"revision": 1},
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )
    embeddings_before = list(provider.embedded)
    qdrant.fail_payload_refresh_after = 1

    with pytest.raises(DocumentCompletionError, match="metadata"):
        ingest_documents(
            _request(
                "AAAAABBBBBCCCCC",
                operation_id="metadata-failure",
                metadata={"revision": 2},
            ),
            rag_service=object(),
            get_provider=lambda _name: provider,
            dependencies=_dependencies(qdrant),
        )

    assert provider.embedded == embeddings_before
    assert {
        point["payload"]["metadata"]["revision"] for point in qdrant.points.values()
    } == {1, 2}

    qdrant.fail_payload_refresh_after = None
    result = ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="metadata-retry",
            metadata={"revision": 2},
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    assert result["ok"] is True
    assert provider.embedded == embeddings_before
    assert qdrant.payload_refresh_writes == 2
    assert {
        point["payload"]["metadata"]["revision"] for point in qdrant.points.values()
    } == {2}
    assert len(qdrant.completion_points()) == 3


def test_metadata_refresh_is_retried_when_completion_publication_fails() -> None:
    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="initial",
            metadata={"revision": 1},
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )
    embeddings_before = list(provider.embedded)
    previous_fingerprints = {
        point["payload"]["rawki_document_metadata_fingerprint"]
        for point in qdrant.points.values()
    }
    qdrant.fail_completion = True

    with pytest.raises(DocumentCompletionError, match="completion"):
        ingest_documents(
            _request(
                "AAAAABBBBBCCCCC",
                operation_id="completion-failure",
                metadata={"revision": 2},
            ),
            rag_service=object(),
            get_provider=lambda _name: provider,
            dependencies=_dependencies(qdrant),
        )

    assert provider.embedded == embeddings_before
    assert {
        point["payload"]["metadata"]["revision"] for point in qdrant.points.values()
    } == {2}
    assert {
        point["payload"]["rawki_document_metadata_fingerprint"]
        for point in qdrant.points.values()
    } == previous_fingerprints

    qdrant.fail_completion = False
    ingest_documents(
        _request(
            "AAAAABBBBBCCCCC",
            operation_id="completion-retry",
            metadata={"revision": 2},
        ),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    expected_fingerprint = direct_text_metadata_fingerprint(
        next(iter(qdrant.points.values()))["payload"]
    )
    assert provider.embedded == embeddings_before
    assert qdrant.payload_refresh_writes == 2
    assert {
        point["payload"]["rawki_document_metadata_fingerprint"]
        for point in qdrant.points.values()
    } == {expected_fingerprint}


def test_changed_text_still_reembeds_and_replaces_points() -> None:
    qdrant = MemoryQdrant()
    provider = RecordingProvider()
    ingest_documents(
        _request("AAAAABBBBBCCCCC", operation_id="initial"),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )
    embedding_count = len(provider.embedded)

    ingest_documents(
        _request("DDDDDEEEEEFFFFF", operation_id="content-change"),
        rag_service=object(),
        get_provider=lambda _name: provider,
        dependencies=_dependencies(qdrant),
    )

    assert len(provider.embedded) == embedding_count + 3
    assert qdrant.delete_calls == 1
    assert {point["payload"]["content"] for point in qdrant.points.values()} == {
        "DDDDD",
        "EEEEE",
        "FFFFF",
    }
