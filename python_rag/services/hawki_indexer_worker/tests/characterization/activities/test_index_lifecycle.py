from __future__ import annotations

import hashlib
import json
from collections.abc import Callable
from pathlib import Path
from types import SimpleNamespace
from typing import Any

import pytest

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.pipeline.ingestion import (
    IndexActivityInput,
    IndexResult,
    ReadyActivityInput,
)
from hawki_rag_contracts.pipeline.status import PipelineStageStatus
from hawki_indexer_worker.adapters.composition import (
    build_ingest_workflow_dependencies,
)
from hawki_indexer_worker.application.index_execution import (
    IndexActivityContext,
    IndexActivityDependencies,
    execute_index_activity,
)
from hawki_indexer_worker.application.ready_projection import (
    ReadyProjectionContext,
    project_source_ready,
)
from hawki_indexer_worker.indexing.batch_execution import IngestDocuments
from hawki_indexer_worker.settings import IndexerSettings


def _settings(tmp_path: Path) -> IndexerSettings:
    return IndexerSettings(
        temporal_address="temporal:7233",
        temporal_namespace="default",
        task_queue="rag-ingestion-task-queue",
        activity_worker_threads=1,
        callback_url="http://laravel.test/internal/events",
        callback_secret="test-secret",
        callback_timeout_seconds=1.0,
        callback_retry_attempts=1,
        rag_working_dir=tmp_path / "rag",
    )


def _workflow_input(tmp_path: Path, *, batch_size: int = 2) -> dict[str, Any]:
    return {
        "source_id": "source-1",
        "source_url": "https://example.test/source",
        "dataset_id": "dataset-1",
        "job_id": "job-1",
        "task_id": "task-1",
        "storage": {"shared_root": str(tmp_path)},
        "raw_output_path": str(tmp_path / "raw"),
        "markdown_output_path": str(tmp_path / "markdown"),
        "ingest_manifest_path": str(tmp_path / "manifest.json"),
        "ingestion": {
            "provider": "ollama",
            "embedding_model": "bge-m3",
            "collection": "dataset-1",
            "neo4j_namespace": "hawki_dataset_1",
            "chunk_chars": 1200,
            "chunk_overlap": 250,
            "batch_size": batch_size,
            "graph": True,
        },
    }


def _write_markdown_files(directory: Path, count: int) -> list[Path]:
    directory.mkdir(parents=True)
    paths = []
    for number in range(1, count + 1):
        path = directory / f"document-{number}.md"
        path.write_text(f"# Document {number}\n\nContent {number}", encoding="utf-8")
        paths.append(path)
    return paths


def _run_index_activity(
    tmp_path: Path,
    workflow_input: dict[str, Any],
    *,
    status_reporter: Callable[..., dict[str, Any]],
    document_ingester: IngestDocuments,
    heartbeat_sender: Callable[[object], None] | None = None,
) -> IndexResult:
    return execute_index_activity(
        IndexActivityInput.model_validate(
            {
                "workflow_input": workflow_input,
                "convert_result": {
                    "markdown_dir": workflow_input["markdown_output_path"],
                },
            }
        ),
        context=IndexActivityContext(
            settings=_settings(tmp_path),
            activity_info=SimpleNamespace(
                workflow_id="workflow-1",
                workflow_run_id="run-1",
                attempt=1,
            ),
            heartbeat_sender=heartbeat_sender,
        ),
        dependencies=IndexActivityDependencies(
            artifact_store=LocalArtifactStore(tmp_path),
            graph_service=object(),
            provider_resolver=lambda _name: object(),
            workflow_dependencies=build_ingest_workflow_dependencies(),
            status_reporter=status_reporter,
            ingest_documents=document_ingester,
        ),
    )


def test_index_activity_batches_documents_and_accumulates_graph_results(
    tmp_path: Path,
) -> None:
    workflow_input = _workflow_input(tmp_path, batch_size=2)
    markdown_files = _write_markdown_files(
        Path(workflow_input["markdown_output_path"]),
        3,
    )
    requests = []
    heartbeats: list[object] = []
    statuses: list[PipelineStageStatus] = []
    responses = iter(
        [
            {
                "points": 3,
                "summary": {
                    "documents": {
                        "processed_docs": 2,
                        "skipped_docs": 0,
                        "incremental_new_docs": 1,
                        "incremental_changed_docs": 1,
                        "incremental_unchanged_docs": 0,
                        "total_chunks": 3,
                    },
                    "graph_preview": {
                        "nodes": [{"id": "a"}, {"id": "b"}],
                        "edges": [{"source": "a", "target": "b"}],
                    },
                },
                "graph_preview": {"total_docs": 2, "total_triplets": 1},
                "graph_failures": [
                    {
                        "doc_id": "document-2",
                        "file_path": "document-2.md",
                        "chunks": 1,
                        "chars": 20,
                        "error": "partial graph failure",
                        "timestamp": "2026-08-27T10:00:00Z",
                    }
                ],
            },
            {
                "points": 2,
                "summary": {
                    "documents": {
                        "processed_docs": 1,
                        "skipped_docs": 0,
                        "incremental_new_docs": 1,
                        "incremental_changed_docs": 0,
                        "incremental_unchanged_docs": 0,
                        "total_chunks": 2,
                    },
                    "graph_preview": {
                        "nodes": [{"id": "c"}],
                        "edges": [],
                    },
                },
                "graph_preview": {"total_docs": 1, "total_triplets": 0},
                "graph_failures": [],
            },
        ]
    )

    def fake_ingest(request, **_kwargs):
        requests.append(request)
        return next(responses)

    result = _run_index_activity(
        tmp_path,
        workflow_input,
        status_reporter=lambda *_args, **kwargs: (
            statuses.append(kwargs["status"]) or {"accepted": True}
        ),
        document_ingester=fake_ingest,
        heartbeat_sender=heartbeats.append,
    )

    assert [len(request.docs) for request in requests] == [2, 1]
    assert [request.idempotency_key for request in requests] == [
        f"source-1:job-1:{requests[0].docs[0].id}:ingest",
        f"source-1:job-1:{requests[1].docs[0].id}:ingest",
    ]
    assert statuses == [PipelineStageStatus.RUNNING]
    assert heartbeats == [
        {
            "phase": "ingest_markdown_files",
            "batch": 1,
            "documents_indexed": 2,
        },
        {
            "phase": "ingest_markdown_files",
            "batch": 2,
            "documents_indexed": 3,
        },
    ]
    assert result.model_dump(mode="json") == {
        "source_id": "source-1",
        "documents_indexed": 3,
        "chunks_indexed": 5,
        "vectors_upserted": 5,
        "graph_records_updated": 4,
        "failed_documents": 0,
        "skipped_documents": 0,
        "new_documents": 2,
        "changed_documents": 1,
        "unchanged_documents": 0,
        "status": "success",
        "error_details": None,
        "ingestion_summary": {
            "documents": {
                "processed_docs": 1,
                "skipped_docs": 0,
                "incremental_new_docs": 1,
                "incremental_changed_docs": 0,
                "incremental_unchanged_docs": 0,
                "total_chunks": 2,
            },
            "graph_preview": {"nodes": [{"id": "c"}], "edges": []},
        },
        "graph_preview": {"total_docs": 1, "total_triplets": 0},
        "graph_failures": [
            {
                "doc_id": "document-2",
                "file_path": "document-2.md",
                "chunks": 1,
                "chars": 20,
                "error": "partial graph failure",
                "timestamp": "2026-08-27T10:00:00Z",
            }
        ],
        "document_version": hashlib.sha256(
            "|".join(
                hashlib.sha256(path.read_bytes()).hexdigest() for path in markdown_files
            ).encode("utf-8")
        ).hexdigest()[:24],
    }
    manifest = json.loads(Path(workflow_input["ingest_manifest_path"]).read_text())
    assert [record["relative_path"] for record in manifest] == [
        "document-1.md",
        "document-2.md",
        "document-3.md",
    ]


def test_index_activity_reports_failure_after_completed_batch_without_manifest(
    tmp_path: Path,
) -> None:
    workflow_input = _workflow_input(tmp_path, batch_size=1)
    _write_markdown_files(Path(workflow_input["markdown_output_path"]), 2)
    statuses: list[PipelineStageStatus] = []
    heartbeats: list[object] = []
    calls = 0

    def fake_ingest(_request, **_kwargs):
        nonlocal calls
        calls += 1
        if calls == 2:
            raise RuntimeError("second batch failed")
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

    with pytest.raises(RuntimeError, match="second batch failed"):
        _run_index_activity(
            tmp_path,
            workflow_input,
            status_reporter=lambda *_args, **kwargs: (
                statuses.append(kwargs["status"]) or {"accepted": True}
            ),
            document_ingester=fake_ingest,
            heartbeat_sender=heartbeats.append,
        )

    assert statuses == [
        PipelineStageStatus.RUNNING,
        PipelineStageStatus.FAILED,
    ]
    assert heartbeats == [
        {
            "phase": "ingest_markdown_files",
            "batch": 1,
            "documents_indexed": 1,
        }
    ]
    assert not Path(workflow_input["ingest_manifest_path"]).exists()


def test_ready_projection_reports_failed_index_result(tmp_path: Path) -> None:
    workflow_input = _workflow_input(tmp_path)
    callbacks: list[dict[str, Any]] = []

    projection = project_source_ready(
        ReadyActivityInput.model_validate(
            {
                "workflow_input": workflow_input,
                "convert_result": {
                    "markdown_dir": workflow_input["markdown_output_path"],
                },
                "ingest_result": {
                    "status": "failed",
                    "documents_indexed": 1,
                    "failed_documents": 2,
                    "error_details": "indexing incomplete",
                },
            }
        ),
        context=ReadyProjectionContext(
            settings=_settings(tmp_path),
            status_reporter=lambda *_args, **kwargs: (
                callbacks.append(kwargs) or {"accepted": True}
            ),
            activity_info=SimpleNamespace(
                workflow_id="workflow-1",
                workflow_run_id="run-1",
                attempt=1,
            ),
        ),
    )
    result = projection.to_wire()

    assert result["status"] == "failed"
    assert result["documents_indexed"] == 1
    assert result["failed_documents"] == 2
    callback = callbacks[0]
    assert callback["status"] is PipelineStageStatus.FAILED
    assert callback["processed"] == 1
    assert callback["failed"] == 2
    assert isinstance(callback["error"], RuntimeError)
    assert str(callback["error"]) == "indexing incomplete"
