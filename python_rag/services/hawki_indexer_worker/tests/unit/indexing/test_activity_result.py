from __future__ import annotations

from hawki_rag_contracts.pipeline.ingestion import IndexResult, IngestionStatus
from hawki_indexer_worker.indexing.activity_result import IndexActivityAccumulator


def test_accumulator_produces_validated_index_result() -> None:
    accumulator = IndexActivityAccumulator("source-1")
    accumulator.record_skipped_documents()
    accumulator.accumulate_response(
        {
            "points": 3,
            "summary": {
                "documents": {
                    "processed_docs": 2,
                    "skipped_docs": 1,
                    "incremental_new_docs": 1,
                    "incremental_changed_docs": 1,
                    "incremental_unchanged_docs": 0,
                    "total_chunks": 3,
                },
                "graph_preview": {
                    "nodes": [{"id": "one"}],
                    "edges": [{"source": "one", "target": "two"}],
                },
            },
            "graph_preview": {"total_docs": 2, "total_triplets": 1},
            "graph_failures": [
                {
                    "doc_id": "doc-2",
                    "file_path": "doc-2.md",
                    "chunks": 1,
                    "chars": 20,
                    "error": "graph failed",
                    "timestamp": "2026-08-27T10:00:00Z",
                }
            ],
        }
    )

    result = accumulator.completed_result([{"content_hash": "hash-1"}])

    assert isinstance(result, IndexResult)
    assert result.status is IngestionStatus.SUCCESS
    assert result.documents_indexed == 2
    assert result.skipped_documents == 2
    assert result.new_documents == 1
    assert result.changed_documents == 1
    assert result.chunks_indexed == 3
    assert result.vectors_upserted == 3
    assert result.graph_records_updated == 2
    assert result.graph_preview == {"total_docs": 2, "total_triplets": 1}
    assert result.graph_failures[0].error == "graph failed"
    assert len(result.document_version or "") == 24


def test_accumulator_produces_skipped_result_with_reason() -> None:
    result = IndexActivityAccumulator("source-1").skipped_result(
        "No Markdown files were found."
    )

    assert result.status is IngestionStatus.SKIPPED
    assert result.error_details == "No Markdown files were found."
    assert result.document_version is None
