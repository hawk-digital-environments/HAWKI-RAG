"""Deterministic workflow behavior and packaging tests."""

from __future__ import annotations

import asyncio
import ast
from collections import deque
from pathlib import Path
import tomllib
from typing import Any

from hawki_rag_contracts.pipeline.temporal import (
    CONVERT_FILES_ACTIVITY,
    INDEX_MARKDOWN_ACTIVITY,
    MARK_SOURCE_READY_ACTIVITY,
    SCRAPE_SOURCE_ACTIVITY,
)
from hawki_workflow_worker.settings import WorkflowWorkerSettings
from hawki_workflow_worker.workflows import ingest_source
from hawki_workflow_worker.workflows.ingest_source import IngestSourceWorkflow


PYTHON_RAG = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)
SERVICE_ROOT = PYTHON_RAG / "services" / "hawki_workflow_worker"


class _Logger:
    def info(self, *_args: object, **_kwargs: object) -> None:
        return None


class _WorkflowRuntime:
    def __init__(
        self,
        results: list[dict[str, Any]],
        *,
        indexer_queue_patch: bool = True,
    ) -> None:
        self.logger = _Logger()
        self.results = deque(results)
        self.calls: list[tuple[str, dict[str, Any], dict[str, Any]]] = []
        self.indexer_queue_patch = indexer_queue_patch

    def patched(self, _patch_id: str) -> bool:
        return self.indexer_queue_patch

    async def execute_activity(
        self,
        name: str,
        payload: dict[str, Any],
        **options: Any,
    ) -> dict[str, Any]:
        self.calls.append((name, payload, options))
        return self.results.popleft()


def test_workflow_preserves_activity_order_and_prefers_the_indexer_queue(
    monkeypatch,
) -> None:
    runtime = _WorkflowRuntime(
        [
            {"status": "success", "raw_dir": "/shared/raw"},
            {"status": "success", "markdown_dir": "/shared/markdown"},
            {"status": "success", "documents_indexed": 1},
            {"status": "ready", "source_id": "source-a"},
        ]
    )
    monkeypatch.setattr(ingest_source, "workflow", runtime)
    workflow_input = {
        "source_id": "source-a",
        "source_url": "https://example.test/source",
        "task_queues": {
            "scraper": "scraper-a",
            "converter": "converter-a",
            "indexer": "indexer-a",
            "ingestion": "legacy-indexer-a",
        },
    }

    result = asyncio.run(IngestSourceWorkflow().run(workflow_input))

    assert result == {"status": "ready", "source_id": "source-a"}
    assert [call[0] for call in runtime.calls] == [
        SCRAPE_SOURCE_ACTIVITY,
        CONVERT_FILES_ACTIVITY,
        INDEX_MARKDOWN_ACTIVITY,
        MARK_SOURCE_READY_ACTIVITY,
    ]
    assert [call[2]["task_queue"] for call in runtime.calls] == [
        "scraper-a",
        "converter-a",
        "indexer-a",
        "indexer-a",
    ]


def test_replayed_pre_indexer_history_keeps_the_legacy_queue(monkeypatch) -> None:
    runtime = _WorkflowRuntime(
        [
            {"status": "success"},
            {"status": "success"},
            {"status": "success"},
            {"status": "ready"},
        ],
        indexer_queue_patch=False,
    )
    monkeypatch.setattr(ingest_source, "workflow", runtime)

    asyncio.run(
        IngestSourceWorkflow().run(
            {
                "source_id": "source-a",
                "task_queues": {
                    "indexer": "new-indexer-queue",
                    "ingestion": "legacy-ingestion-queue",
                },
            }
        )
    )

    assert [call[2]["task_queue"] for call in runtime.calls[-2:]] == [
        "legacy-ingestion-queue",
        "legacy-ingestion-queue",
    ]


def test_workflow_stops_after_a_failed_phase_and_keeps_failure_shape(
    monkeypatch,
) -> None:
    runtime = _WorkflowRuntime([{"status": "failed", "error": "crawler unavailable"}])
    monkeypatch.setattr(ingest_source, "workflow", runtime)

    result = asyncio.run(
        IngestSourceWorkflow().run(
            {"source_id": "source-a", "source_url": "https://example.test/source"}
        )
    )

    assert result == {
        "source_id": "source-a",
        "source_url": "https://example.test/source",
        "phase": SCRAPE_SOURCE_ACTIVITY,
        "status": "failed",
        "error_details": "crawler unavailable",
    }
    assert len(runtime.calls) == 1


def test_new_workflow_reports_a_non_success_index_result_in_a_cheap_activity(
    monkeypatch,
) -> None:
    runtime = _WorkflowRuntime(
        [
            {"status": "success"},
            {"status": "success", "markdown_dir": "/shared/markdown"},
            {"status": "skipped", "error_details": "No usable Markdown."},
            {"status": "skipped"},
        ]
    )
    monkeypatch.setattr(ingest_source, "workflow", runtime)
    workflow_input = {"source_id": "source-a"}

    result = asyncio.run(IngestSourceWorkflow().run(workflow_input))

    assert result == {
        "source_id": "source-a",
        "source_url": None,
        "phase": INDEX_MARKDOWN_ACTIVITY,
        "status": "failed",
        "error_details": "No usable Markdown.",
    }
    assert [call[0] for call in runtime.calls[-2:]] == [
        INDEX_MARKDOWN_ACTIVITY,
        MARK_SOURCE_READY_ACTIVITY,
    ]


def test_pre_patch_non_success_history_keeps_the_original_early_return(
    monkeypatch,
) -> None:
    runtime = _WorkflowRuntime(
        [
            {"status": "success"},
            {"status": "success"},
            {"status": "skipped", "error_details": "No usable Markdown."},
        ],
        indexer_queue_patch=False,
    )
    monkeypatch.setattr(ingest_source, "workflow", runtime)

    result = asyncio.run(IngestSourceWorkflow().run({"source_id": "source-a"}))

    assert result["phase"] == INDEX_MARKDOWN_ACTIVITY
    assert len(runtime.calls) == 3


def test_workflow_worker_settings_are_narrow_and_keep_legacy_defaults(
    monkeypatch,
) -> None:
    monkeypatch.delenv("TEMPORAL_ADDRESS", raising=False)
    monkeypatch.delenv("TEMPORAL_NAMESPACE", raising=False)
    monkeypatch.delenv("TEMPORAL_RAG_WORKFLOW_TASK_QUEUE", raising=False)

    settings = WorkflowWorkerSettings.from_environment()

    assert settings.temporal_address == "temporal:7233"
    assert settings.temporal_namespace == "default"
    assert settings.workflow_task_queue == "rag-workflow-task-queue"
    assert not hasattr(settings, "db_password")
    assert not hasattr(settings, "bridge_url")


def test_workflow_module_is_deterministic_and_service_package_is_exactly_pinned() -> (
    None
):
    pyproject = tomllib.loads(
        (SERVICE_ROOT / "pyproject.toml").read_text(encoding="utf-8")
    )
    assert pyproject["project"]["requires-python"] == "==3.13.14"
    assert pyproject["project"]["dependencies"] == [
        "hawki-rag-contracts==0.1.0",
        "temporalio==1.30.0",
    ]
    assert pyproject["build-system"]["requires"] == ["uv_build==0.11.26"]

    workflow_source = (
        SERVICE_ROOT
        / "src"
        / "hawki_workflow_worker"
        / "workflows"
        / "ingest_source.py"
    ).read_text(encoding="utf-8")
    imports: set[str] = set()
    for node in ast.walk(ast.parse(workflow_source)):
        if isinstance(node, ast.Import):
            imports.update(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            imports.add(node.module)
    forbidden_roots = {
        "os",
        "pathlib",
        "random",
        "requests",
        "time",
        "fastapi",
        "psycopg",
        "hawki_bridge",
        "hawki_scraper_worker",
        "hawki_converter_worker",
        "hawki_indexer_worker",
        "hawki_reranker",
    }
    assert not {name.split(".", 1)[0] for name in imports} & forbidden_roots

    for source in (SERVICE_ROOT / "src" / "hawki_workflow_worker").rglob("*.py"):
        assert len(source.read_text(encoding="utf-8").splitlines()) < 600, source
