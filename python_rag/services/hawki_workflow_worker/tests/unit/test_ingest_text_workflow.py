"""Direct text workflow behavior."""

from __future__ import annotations

import asyncio
from collections import deque
from typing import Any

import pytest

from hawki_rag_contracts.pipeline.temporal import (
    INDEX_MARKDOWN_ACTIVITY,
    MARK_SOURCE_READY_ACTIVITY,
)
from hawki_rag_contracts.pipeline.identity import document_id
from hawki_workflow_worker.workflows import ingest_text
from hawki_workflow_worker.workflows.ingest_text import IngestTextWorkflow


class _WorkflowRuntime:
    def __init__(self, results: list[dict[str, Any]]) -> None:
        self.results = deque(results)
        self.calls: list[tuple[str, dict[str, Any], dict[str, Any]]] = []

    async def execute_activity(
        self,
        name: str,
        payload: dict[str, Any],
        **options: Any,
    ) -> dict[str, Any]:
        self.calls.append((name, payload, options))
        return self.results.popleft()


def test_workflow_indexes_existing_markdown_without_scrape_or_conversion(
    monkeypatch,
) -> None:
    runtime = _WorkflowRuntime(
        [
            {"status": "success", "documents_indexed": 1},
            {"status": "ready", "source_id": "source-a"},
        ]
    )
    monkeypatch.setattr(ingest_text, "workflow", runtime)
    workflow_input = {
        "source_id": "source-a",
        "source_url": "external://document-a",
        "external_document_id": "document-a",
        "markdown_path": "/shared/sources/source-a/markdown/document.md",
        "markdown_output_path": "/shared/sources/source-a/markdown",
        "content_hash": "a" * 64,
        "task_queues": {"indexer": "indexer-a"},
    }

    result = asyncio.run(IngestTextWorkflow().run(workflow_input))

    assert result == {"status": "ready", "source_id": "source-a"}
    assert [call[0] for call in runtime.calls] == [
        INDEX_MARKDOWN_ACTIVITY,
        MARK_SOURCE_READY_ACTIVITY,
    ]
    assert all(call[2]["task_queue"] == "indexer-a" for call in runtime.calls)
    artifact = runtime.calls[0][1]["convert_result"]["artifacts"][0]
    assert artifact["uri"] == workflow_input["markdown_path"]
    assert artifact["document_id"] == document_id("source-a", "document.md")


def test_workflow_projects_failed_indexing_as_a_terminal_callback(monkeypatch) -> None:
    runtime = _WorkflowRuntime(
        [
            {"status": "failed", "error_details": "Embedding failed."},
            {
                "source_id": "source-a",
                "status": "failed",
                "error_details": "Embedding failed.",
            },
        ]
    )
    monkeypatch.setattr(ingest_text, "workflow", runtime)

    result = asyncio.run(
        IngestTextWorkflow().run(
            {
                "source_id": "source-a",
                "source_url": "external://document-a",
                "external_document_id": "document-a",
                "markdown_path": "/shared/document.md",
                "markdown_output_path": "/shared",
                "content_hash": "a" * 64,
            }
        )
    )

    assert result["status"] == "failed"
    assert result["error_details"] == "Embedding failed."
    assert [call[0] for call in runtime.calls] == [
        INDEX_MARKDOWN_ACTIVITY,
        MARK_SOURCE_READY_ACTIVITY,
    ]
    assert runtime.calls[1][1]["ingest_result"]["status"] == "failed"


def test_workflow_projects_skipped_indexing_as_a_terminal_callback(monkeypatch) -> None:
    runtime = _WorkflowRuntime(
        [
            {"status": "skipped", "skipped_documents": 1},
            {"source_id": "source-a", "status": "skipped"},
        ]
    )
    monkeypatch.setattr(ingest_text, "workflow", runtime)

    result = asyncio.run(
        IngestTextWorkflow().run(
            {
                "source_id": "source-a",
                "markdown_path": "/shared/document.md",
                "markdown_output_path": "/shared",
                "content_hash": "a" * 64,
            }
        )
    )

    assert result["status"] == "skipped"
    assert [call[0] for call in runtime.calls] == [
        INDEX_MARKDOWN_ACTIVITY,
        MARK_SOURCE_READY_ACTIVITY,
    ]


def test_workflow_does_not_project_ready_when_indexing_raises(monkeypatch) -> None:
    class FailingIndexRuntime:
        def __init__(self) -> None:
            self.calls: list[str] = []

        async def execute_activity(
            self,
            name: str,
            payload: dict[str, Any],
            **options: Any,
        ) -> dict[str, Any]:
            del payload, options
            self.calls.append(name)
            raise RuntimeError("indexing incomplete")

    runtime = FailingIndexRuntime()
    monkeypatch.setattr(ingest_text, "workflow", runtime)

    with pytest.raises(RuntimeError, match="indexing incomplete"):
        asyncio.run(
            IngestTextWorkflow().run(
                {
                    "source_id": "source-a",
                    "markdown_path": "/shared/document.md",
                    "markdown_output_path": "/shared",
                    "content_hash": "a" * 64,
                }
            )
        )

    assert runtime.calls == [INDEX_MARKDOWN_ACTIVITY]
