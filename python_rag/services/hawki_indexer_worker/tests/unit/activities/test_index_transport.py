from __future__ import annotations

from pathlib import Path
from types import SimpleNamespace

import pytest
from pydantic import ValidationError

from hawki_rag_contracts.pipeline.ingestion import (
    IndexActivityInput,
    IndexResult,
    IngestionStatus,
    ReadyActivityInput,
)
from hawki_indexer_worker.activities import index as index_activity
from hawki_indexer_worker.application.ready_projection import ReadyProjection
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


def test_index_transport_validates_after_settings_before_creating_sender(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    calls: list[str] = []

    def load_settings(_cls: type[IndexerSettings]) -> IndexerSettings:
        calls.append("settings")
        return _settings(tmp_path)

    def create_sender(_settings: IndexerSettings) -> None:
        calls.append("sender")
        raise AssertionError("invalid input must not create a callback sender")

    monkeypatch.setattr(
        index_activity.IndexerSettings,
        "from_env",
        classmethod(load_settings),
    )
    monkeypatch.setattr(index_activity, "create_callback_sender", create_sender)

    with pytest.raises(ValidationError):
        index_activity.ingest_markdown_files({})

    assert calls == ["settings"]


def test_ready_transport_validates_before_settings_or_sender_creation(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    calls: list[str] = []

    def load_settings(_cls: type[IndexerSettings]) -> IndexerSettings:
        calls.append("settings")
        raise AssertionError("invalid input must not load settings")

    def create_sender(_settings: IndexerSettings) -> None:
        calls.append("sender")
        raise AssertionError("invalid input must not create a callback sender")

    monkeypatch.setattr(
        index_activity.IndexerSettings,
        "from_env",
        classmethod(load_settings),
    )
    monkeypatch.setattr(index_activity, "create_callback_sender", create_sender)

    with pytest.raises(ValidationError):
        index_activity.mark_source_ready({})

    assert calls == []


def test_index_transport_decodes_composes_and_encodes(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    settings = _settings(tmp_path)
    captured = {}
    activity_info = SimpleNamespace(
        workflow_id="workflow-1",
        workflow_run_id="run-1",
        attempt=1,
    )
    graph_service = object()
    workflow_dependencies = object()

    def fake_execute(activity_input, *, context, dependencies):
        captured.update(
            activity_input=activity_input,
            context=context,
            dependencies=dependencies,
        )
        return IndexResult(
            source_id="source-1",
            status=IngestionStatus.SKIPPED,
            error_details="No Markdown files were found.",
        )

    monkeypatch.setattr(
        index_activity.IndexerSettings,
        "from_env",
        classmethod(lambda _cls: settings),
    )
    monkeypatch.setattr(index_activity.activity, "info", lambda: activity_info)
    monkeypatch.setattr(index_activity.activity, "heartbeat", lambda _details: None)
    monkeypatch.setattr(
        index_activity,
        "create_graph_extractor",
        lambda *_args, **_kwargs: graph_service,
    )
    monkeypatch.setattr(
        index_activity,
        "build_ingest_workflow_dependencies",
        lambda: workflow_dependencies,
    )
    monkeypatch.setattr(index_activity, "execute_index_activity", fake_execute)

    result = index_activity.ingest_markdown_files(
        {
            "workflow_input": {"source_id": "source-1"},
            "convert_result": {},
        }
    )

    assert isinstance(captured["activity_input"], IndexActivityInput)
    assert captured["context"].settings is settings
    assert captured["context"].activity_info is activity_info
    assert captured["dependencies"].graph_service is graph_service
    assert captured["dependencies"].workflow_dependencies is workflow_dependencies
    assert result["status"] == "skipped"
    assert result["error_details"] == "No Markdown files were found."


def test_ready_transport_decodes_invokes_and_encodes(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    settings = _settings(tmp_path)
    captured = {}
    activity_info = SimpleNamespace(
        workflow_id="workflow-1",
        workflow_run_id="run-1",
        attempt=1,
    )
    ingest_result = IndexResult(
        source_id="source-1",
        status=IngestionStatus.SUCCESS,
        documents_indexed=1,
    )

    def fake_project(activity_input, *, context):
        captured.update(activity_input=activity_input, context=context)
        return ReadyProjection(
            source_id="source-1",
            source_url="https://example.test/source",
            status=IngestionStatus.READY,
            workflow_status=ingest_result,
            document_version=None,
            error_details=None,
        )

    monkeypatch.setattr(
        index_activity.IndexerSettings,
        "from_env",
        classmethod(lambda _cls: settings),
    )
    monkeypatch.setattr(index_activity.activity, "info", lambda: activity_info)
    monkeypatch.setattr(index_activity, "project_source_ready", fake_project)

    result = index_activity.mark_source_ready(
        {
            "workflow_input": {"source_id": "source-1"},
            "convert_result": {},
            "ingest_result": {"status": "success"},
        }
    )

    assert isinstance(captured["activity_input"], ReadyActivityInput)
    assert captured["context"].settings is settings
    assert captured["context"].activity_info is activity_info
    assert result["status"] == "ready"
    assert result["documents_indexed"] == 1
