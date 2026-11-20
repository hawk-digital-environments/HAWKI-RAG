from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.pipeline.ingestion import IngestionStatus
from hawki_indexer_worker.adapters.composition import (
    build_ingest_workflow_dependencies,
)
from hawki_indexer_worker.indexing.batch_execution import (
    IndexBatchDependencies,
    IndexBatchRequest,
    execute_index_batches,
)


def test_execute_index_batches_records_skipped_artifacts_and_progress(
    tmp_path: Path,
) -> None:
    markdown_dir = tmp_path / "markdown"
    markdown_dir.mkdir()
    indexable_file = markdown_dir / "indexable.md"
    indexable_file.write_text("# Indexable", encoding="utf-8")
    empty_file = markdown_dir / "empty.md"
    empty_file.write_text("  \n", encoding="utf-8")
    manifest_path = tmp_path / "manifest.json"
    workflow_input: dict[str, Any] = {
        "source_id": "source-1",
        "source_url": "https://example.test/source",
        "dataset_id": "dataset-1",
        "job_id": "job-1",
        "task_id": "task-1",
        "raw_output_path": str(tmp_path / "raw"),
        "ingestion": {
            "provider": "ollama",
            "collection": "dataset-1",
            "batch_size": 2,
            "graph": False,
        },
    }
    requests = []
    heartbeats: list[object] = []

    def fake_ingest(request, **_kwargs):
        requests.append(request)
        return {
            "points": 1,
            "summary": {
                "documents": {
                    "processed_docs": 1,
                    "skipped_docs": 0,
                    "incremental_new_docs": 1,
                    "incremental_changed_docs": 0,
                    "incremental_unchanged_docs": 0,
                    "total_chunks": 1,
                }
            },
        }

    result = execute_index_batches(
        IndexBatchRequest(
            files=[str(empty_file), str(indexable_file)],
            workflow_input=workflow_input,
            markdown_dir=str(markdown_dir),
            manifest_path=str(manifest_path),
            artifacts_by_path={},
        ),
        IndexBatchDependencies(
            artifact_store=LocalArtifactStore(tmp_path),
            graph_service=object(),
            provider_resolver=lambda _name: object(),
            workflow_dependencies=build_ingest_workflow_dependencies(),
            ingest_documents=fake_ingest,
            heartbeat_sender=heartbeats.append,
        ),
    )

    assert result.status is IngestionStatus.SUCCESS
    assert result.documents_indexed == 1
    assert result.skipped_documents == 1
    assert len(requests) == 1
    assert requests[0].idempotency_key == (
        f"source-1:job-1:{requests[0].docs[0].id}:ingest"
    )
    assert heartbeats == [
        {
            "phase": "ingest_markdown_files",
            "batch": 1,
            "documents_indexed": 1,
        }
    ]
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    assert [record["relative_path"] for record in manifest] == ["indexable.md"]
