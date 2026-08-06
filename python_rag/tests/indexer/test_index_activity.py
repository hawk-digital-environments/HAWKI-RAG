from __future__ import annotations

import hashlib
import json
from pathlib import Path
from types import SimpleNamespace
from typing import Any

import pytest
from temporalio import activity

from hawki_artifact_store.identity import document_id
from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.status import PipelineStageStatus
from hawki_rag_contracts.temporal import (
    INDEX_MARKDOWN_ACTIVITY,
    MARK_SOURCE_READY_ACTIVITY,
)
from hawki_indexer_worker.activities import index as index_activity
from hawki_indexer_worker.adapters.artifact_store import load_passthrough_metadata
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
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


def _workflow_input(tmp_path: Path) -> dict[str, Any]:
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
            "chunk_chars": 1200,
            "chunk_overlap": 250,
            "batch_size": 64,
            "graph": False,
        },
    }


def test_temporal_activity_names_remain_compatible() -> None:
    index_definition = activity._Definition.must_from_callable(
        index_activity.ingest_markdown_files
    )
    ready_definition = activity._Definition.must_from_callable(
        index_activity.mark_source_ready
    )
    assert index_definition.name == INDEX_MARKDOWN_ACTIVITY == "ingest_markdown_files"
    assert ready_definition.name == MARK_SOURCE_READY_ACTIVITY == "mark_source_ready"


def test_activity_calls_indexing_logic_directly_and_writes_manifest(
    tmp_path: Path,
    monkeypatch,
) -> None:
    workflow_input = _workflow_input(tmp_path)
    markdown_dir = Path(workflow_input["markdown_output_path"])
    markdown_dir.mkdir(parents=True)
    markdown_file = markdown_dir / "page.md"
    markdown_file.write_text("# Title\n\nUseful content", encoding="utf-8")
    calls: list[Any] = []
    callback_statuses: list[PipelineStageStatus] = []
    heartbeats: list[object] = []

    def fake_ingest(body, **kwargs):
        calls.append((body, kwargs))
        return {
            "ok": True,
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
                "graph_preview": None,
            },
        }

    def fake_status(*_args, **kwargs):
        callback_statuses.append(kwargs["status"])
        return {"accepted": True}

    class HandoffOnlyArtifactStore(LocalArtifactStore):
        def list_markdown(self, _location: str) -> list[str]:
            raise AssertionError("typed converter artifacts must avoid rediscovery")

    monkeypatch.setattr(index_activity, "ingest_documents", fake_ingest)
    content = markdown_file.read_text(encoding="utf-8")
    result = index_activity.run_index_activity(
        {
            "workflow_input": workflow_input,
            "convert_result": {
                "source_id": "source-1",
                "status": "success",
                "markdown_dir": str(markdown_dir),
                "artifacts": [
                    {
                        "uri": str(markdown_file),
                        "relative_path": "page.md",
                        "sha256": hashlib.sha256(
                            markdown_file.read_bytes()
                        ).hexdigest(),
                        "size_bytes": markdown_file.stat().st_size,
                        "media_type": "text/markdown",
                        "source_id": "source-1",
                        "document_id": document_id("source-1", "page.md"),
                        "content_hash": hashlib.sha256(
                            content.encode("utf-8")
                        ).hexdigest(),
                        "source_artifact_uri": str(tmp_path / "raw"),
                    }
                ],
            },
        },
        settings=_settings(tmp_path),
        artifact_store=HandoffOnlyArtifactStore(tmp_path),
        graph_service=object(),
        provider_resolver=lambda _name: object(),
        workflow_dependencies=IngestWorkflowDependencies(),
        status_reporter=fake_status,
        activity_info=SimpleNamespace(
            workflow_id="workflow-1", workflow_run_id="run-1", attempt=1
        ),
        heartbeat_sender=heartbeats.append,
    )

    assert result["status"] == "success"
    assert result["documents_indexed"] == 1
    assert callback_statuses == [PipelineStageStatus.RUNNING]
    assert len(calls) == 1
    body, kwargs = calls[0]
    assert body.collection == "dataset-1"
    assert body.docs[0].payload["dataset_id"] == "dataset-1"
    assert kwargs["rag_service"] is not None
    assert "bridge" not in kwargs
    assert heartbeats
    manifest = json.loads(Path(workflow_input["ingest_manifest_path"]).read_text())
    assert manifest[0]["document_id"] == str(body.docs[0].id)


def test_activity_rejects_converter_metadata_that_does_not_match_file(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    workflow_input = _workflow_input(tmp_path)
    markdown_dir = Path(workflow_input["markdown_output_path"])
    markdown_dir.mkdir(parents=True)
    markdown_file = markdown_dir / "page.md"
    markdown_file.write_text("# Actual content", encoding="utf-8")
    callback_statuses: list[PipelineStageStatus] = []

    def unexpected(*_args, **_kwargs):
        raise AssertionError("mismatched artifacts must not reach indexing")

    monkeypatch.setattr(index_activity, "ingest_documents", unexpected)
    with pytest.raises(RuntimeError, match="content_hash"):
        index_activity.run_index_activity(
            {
                "workflow_input": workflow_input,
                "convert_result": {
                    "source_id": "source-1",
                    "status": "success",
                    "markdown_dir": str(markdown_dir),
                    "artifacts": [
                        {
                            "uri": str(markdown_file),
                            "relative_path": "page.md",
                            "sha256": hashlib.sha256(
                                markdown_file.read_bytes()
                            ).hexdigest(),
                            "size_bytes": markdown_file.stat().st_size,
                            "media_type": "text/markdown",
                            "source_id": "source-1",
                            "document_id": document_id("source-1", "page.md"),
                            "content_hash": "0" * 64,
                        }
                    ],
                },
            },
            settings=_settings(tmp_path),
            artifact_store=LocalArtifactStore(tmp_path),
            graph_service=object(),
            provider_resolver=lambda _name: object(),
            workflow_dependencies=IngestWorkflowDependencies(),
            status_reporter=lambda *_args, **kwargs: (
                callback_statuses.append(kwargs["status"]) or {"accepted": True}
            ),
            activity_info=SimpleNamespace(
                workflow_id="workflow-1",
                workflow_run_id="run-1",
                attempt=1,
            ),
        )

    assert callback_statuses == [
        PipelineStageStatus.RUNNING,
        PipelineStageStatus.FAILED,
    ]


def test_empty_artifact_directory_returns_skipped_without_index_call(
    tmp_path: Path,
    monkeypatch,
) -> None:
    workflow_input = _workflow_input(tmp_path)
    markdown_dir = Path(workflow_input["markdown_output_path"])
    markdown_dir.mkdir(parents=True)
    callback_statuses: list[PipelineStageStatus] = []

    def unexpected(*_args, **_kwargs):
        raise AssertionError("indexing must not run for an empty artifact directory")

    monkeypatch.setattr(index_activity, "ingest_documents", unexpected)
    result = index_activity.run_index_activity(
        {
            "workflow_input": workflow_input,
            "convert_result": {"markdown_dir": str(markdown_dir)},
        },
        settings=_settings(tmp_path),
        artifact_store=LocalArtifactStore(tmp_path),
        graph_service=object(),
        provider_resolver=lambda _name: object(),
        workflow_dependencies=IngestWorkflowDependencies(),
        status_reporter=lambda *_args, **kwargs: (
            callback_statuses.append(kwargs["status"]) or {"accepted": True}
        ),
        activity_info=SimpleNamespace(
            workflow_id="workflow-1", workflow_run_id="run-1", attempt=1
        ),
    )
    assert result["status"] == "skipped"
    assert callback_statuses == [PipelineStageStatus.RUNNING]

    index_activity.run_mark_source_ready(
        {
            "workflow_input": workflow_input,
            "convert_result": {"markdown_dir": str(markdown_dir)},
            "ingest_result": result,
        },
        settings=_settings(tmp_path),
        status_reporter=lambda *_args, **kwargs: (
            callback_statuses.append(kwargs["status"]) or {"accepted": True}
        ),
        activity_info=SimpleNamespace(
            workflow_id="workflow-1", workflow_run_id="run-1", attempt=1
        ),
    )
    assert callback_statuses[-1] is PipelineStageStatus.SKIPPED


def test_indexer_reports_shared_storage_initialization_failure(tmp_path: Path) -> None:
    workflow_input = _workflow_input(tmp_path)
    missing_root = tmp_path / "missing-shared"
    workflow_input["storage"] = {"shared_root": str(missing_root)}
    callback_statuses: list[PipelineStageStatus] = []

    with pytest.raises(FileNotFoundError, match="Shared artifact root"):
        index_activity.run_index_activity(
            {
                "workflow_input": workflow_input,
                "convert_result": {
                    "markdown_dir": workflow_input["markdown_output_path"]
                },
            },
            settings=_settings(tmp_path),
            artifact_store=None,
            graph_service=object(),
            provider_resolver=lambda _name: object(),
            workflow_dependencies=IngestWorkflowDependencies(),
            status_reporter=lambda *_args, **kwargs: (
                callback_statuses.append(kwargs["status"]) or {"accepted": True}
            ),
            activity_info=SimpleNamespace(
                workflow_id="workflow-1", workflow_run_id="run-1", attempt=1
            ),
        )

    assert callback_statuses == [
        PipelineStageStatus.RUNNING,
        PipelineStageStatus.FAILED,
    ]


def test_ready_callback_is_a_separate_activity_with_result_references(
    tmp_path: Path,
) -> None:
    workflow_input = _workflow_input(tmp_path)
    markdown_dir = Path(workflow_input["markdown_output_path"])
    markdown_dir.mkdir(parents=True)
    manifest_path = Path(workflow_input["ingest_manifest_path"])
    manifest_path.write_text("[]", encoding="utf-8")
    callbacks: list[dict[str, Any]] = []

    result = index_activity.run_mark_source_ready(
        {
            "workflow_input": workflow_input,
            "convert_result": {"markdown_dir": str(markdown_dir)},
            "ingest_result": {
                "status": "success",
                "documents_indexed": 2,
                "chunks_indexed": 4,
                "vectors_upserted": 4,
                "graph_records_updated": 1,
                "failed_documents": 0,
                "skipped_documents": 1,
                "new_documents": 2,
                "changed_documents": 0,
                "unchanged_documents": 0,
                "document_version": "version-1",
                "error_details": None,
                "ingestion_summary": {
                    "timestamp": "2026-08-03T12:00:00Z",
                    "documents": {"processed_docs": 2, "total_chunks": 4},
                },
                "graph_preview": {"total_docs": 2, "total_triplets": 1},
                "graph_failures": [
                    {
                        "doc_id": "doc-2",
                        "file_path": "doc-2.md",
                        "chunks": 2,
                        "chars": 500,
                        "error": "Graph extraction timed out.",
                        "timestamp": "2026-08-03T11:59:59Z",
                    }
                ],
            },
        },
        settings=_settings(tmp_path),
        status_reporter=lambda *_args, **kwargs: (
            callbacks.append(kwargs) or {"accepted": True}
        ),
        activity_info=SimpleNamespace(
            workflow_id="workflow-1", workflow_run_id="run-1", attempt=1
        ),
    )

    assert result["status"] == "ready"
    callback = callbacks[0]
    assert callback["activity_id"] == MARK_SOURCE_READY_ACTIVITY
    assert callback["status"] is PipelineStageStatus.COMPLETED
    assert callback["total"] == 3
    assert callback["processed"] == 2
    assert callback["skipped"] == 1
    assert callback["artifacts"][0].uri == str(markdown_dir)
    assert callback["manifest"].uri == str(manifest_path)
    assert callback["document_version"] == "version-1"
    monitor = callback["monitor_artifacts"]
    assert monitor.summary["documents"]["processed_docs"] == 2
    assert monitor.graph_preview["total_triplets"] == 1
    assert monitor.graph_failures[0].doc_id == "doc-2"


def test_ready_callback_failure_does_not_enter_indexing_code(tmp_path: Path) -> None:
    workflow_input = _workflow_input(tmp_path)

    def unavailable_callback(*_args, **_kwargs):
        raise RuntimeError("callback unavailable")

    with pytest.raises(RuntimeError, match="callback unavailable"):
        index_activity.run_mark_source_ready(
            {
                "workflow_input": workflow_input,
                "convert_result": {
                    "markdown_dir": workflow_input["markdown_output_path"]
                },
                "ingest_result": {
                    "status": "success",
                    "documents_indexed": 1,
                },
            },
            settings=_settings(tmp_path),
            status_reporter=unavailable_callback,
            activity_info=SimpleNamespace(
                workflow_id="workflow-1", workflow_run_id="run-1", attempt=1
            ),
        )


def test_passthrough_artifact_forces_graph_indexing_without_bridge_http(
    tmp_path: Path,
    monkeypatch,
) -> None:
    workflow_input = _workflow_input(tmp_path)
    workflow_input["ingestion"]["neo4j_namespace"] = "hawki_dataset_1"
    raw_dir = Path(workflow_input["raw_output_path"])
    raw_dir.mkdir(parents=True)
    source_image = raw_dir / "image.jpg"
    source_image.write_bytes(b"image")
    markdown_dir = Path(workflow_input["markdown_output_path"])
    document_dir = markdown_dir / "image"
    document_dir.mkdir(parents=True)
    (document_dir / "content_markdown.md").write_text(
        "# Image handoff",
        encoding="utf-8",
    )
    (document_dir / "rawki_passthrough.json").write_text(
        json.dumps(
            {
                "converter_fallback": "raganything_passthrough",
                "file_path": str(source_image),
            }
        ),
        encoding="utf-8",
    )
    requests = []

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
                },
                "graph_preview": {"nodes": [], "edges": []},
            },
        }

    monkeypatch.setattr(index_activity, "ingest_documents", fake_ingest)
    index_activity.run_index_activity(
        {
            "workflow_input": workflow_input,
            "convert_result": {"markdown_dir": str(markdown_dir)},
        },
        settings=_settings(tmp_path),
        artifact_store=LocalArtifactStore(tmp_path),
        graph_service=object(),
        provider_resolver=lambda _name: object(),
        workflow_dependencies=IngestWorkflowDependencies(),
        status_reporter=lambda *_args, **_kwargs: {"accepted": True},
        activity_info=SimpleNamespace(
            workflow_id="workflow-1",
            workflow_run_id="run-1",
            attempt=1,
        ),
    )

    assert len(requests) == 1
    assert requests[0].graph is True
    assert requests[0].docs[0].payload["converter_fallback"] == (
        "raganything_passthrough"
    )


def test_passthrough_metadata_cannot_follow_a_symlink_outside_shared_root(
    tmp_path: Path,
) -> None:
    shared_root = tmp_path / "shared"
    document_dir = shared_root / "markdown" / "document"
    document_dir.mkdir(parents=True)
    markdown_file = document_dir / "content_markdown.md"
    markdown_file.write_text("# Safe Markdown", encoding="utf-8")
    outside_metadata = tmp_path / "outside.json"
    outside_metadata.write_text('{"secret": true}', encoding="utf-8")
    (document_dir / "rawki_passthrough.json").symlink_to(outside_metadata)

    with pytest.raises(ValueError, match="shared root"):
        load_passthrough_metadata(
            LocalArtifactStore(shared_root),
            str(markdown_file),
            allowed_directories=(shared_root / "raw", shared_root / "markdown"),
        )


def test_passthrough_payload_paths_cannot_cross_source_workspaces(
    tmp_path: Path,
) -> None:
    shared_root = tmp_path / "shared"
    raw_dir = shared_root / "sources" / "source-a" / "raw"
    document_dir = shared_root / "sources" / "source-a" / "markdown" / "document"
    other_dir = shared_root / "sources" / "source-b" / "raw"
    raw_dir.mkdir(parents=True)
    document_dir.mkdir(parents=True)
    other_dir.mkdir(parents=True)
    markdown_file = document_dir / "content_markdown.md"
    markdown_file.write_text("# Safe Markdown", encoding="utf-8")
    other_image = other_dir / "private.jpg"
    other_image.write_bytes(b"private")
    (document_dir / "rawki_passthrough.json").write_text(
        json.dumps({"image_path": str(other_image)}),
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="outside its artifact directories"):
        load_passthrough_metadata(
            LocalArtifactStore(shared_root),
            str(markdown_file),
            allowed_directories=(raw_dir, document_dir.parent),
        )
