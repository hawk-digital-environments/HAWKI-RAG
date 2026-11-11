"""Model warmup workflow sequencing and payload behavior."""

from __future__ import annotations

import asyncio
from collections import deque
from typing import Any

from hawki_rag_contracts.pipeline.temporal import (
    WARM_MODEL_ACTIVITY,
    WARM_RETRIEVAL_ACTIVITY,
)
from hawki_workflow_worker.workflows import model_warmup
from hawki_workflow_worker.workflows.model_warmup import ModelWarmupWorkflow


class _WorkflowRuntime:
    def __init__(self, results: list[dict[str, Any]], task_queue: str) -> None:
        self.results = deque(results)
        self.task_queue = task_queue
        self.calls: list[tuple[str, dict[str, Any], dict[str, Any]]] = []

    async def execute_activity(
        self,
        name: str,
        payload: dict[str, Any],
        **options: Any,
    ) -> dict[str, Any]:
        self.calls.append((name, payload, options))
        return self.results.popleft()

    def info(self) -> Any:
        class _Info:
            task_queue = self.task_queue

        return _Info()


def test_warmup_workflow_loads_each_model_then_runs_one_retrieval(
    monkeypatch,
) -> None:
    runtime = _WorkflowRuntime(
        [
            {"activity": WARM_MODEL_ACTIVITY, "model": "bge-m3", "ok": True},
            {"activity": WARM_MODEL_ACTIVITY, "model": "llama3.1:8b", "ok": True},
            {"activity": WARM_RETRIEVAL_ACTIVITY, "ok": True},
        ],
        task_queue="rag-workflow-task-queue",
    )
    monkeypatch.setattr(model_warmup, "workflow", runtime)
    workflow_input = {
        "provider": "ollama",
        "query": "Reply with OK.",
        "models": [
            {"name": "bge-m3", "kind": "embed"},
            {"name": "llama3.1:8b", "kind": "chat"},
        ],
    }

    result = asyncio.run(ModelWarmupWorkflow().run(workflow_input))

    assert result["status"] == "ok"
    assert [call[0] for call in runtime.calls] == [
        WARM_MODEL_ACTIVITY,
        WARM_MODEL_ACTIVITY,
        WARM_RETRIEVAL_ACTIVITY,
    ]
    first, second, retrieval = (call[1] for call in runtime.calls)

    assert first["provider"] == "ollama"
    assert first["model"] == "bge-m3"
    assert first["kind"] == "embed"
    assert second["kind"] == "chat"
    assert retrieval == {"query": "Reply with OK."}

    options = runtime.calls[0][2]
    assert options["task_queue"] == "rag-workflow-task-queue"
    assert options["retry_policy"] is not None


def test_warmup_workflow_propagates_activity_failure(monkeypatch) -> None:
    class _FailingRuntime:
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
            raise RuntimeError("model missing")

        def info(self) -> Any:
            class _Info:
                task_queue = "rag-workflow-task-queue"

            return _Info()

    runtime = _FailingRuntime()
    monkeypatch.setattr(model_warmup, "workflow", runtime)

    try:
        asyncio.run(
            ModelWarmupWorkflow().run(
                {
                    "provider": "ollama",
                    "models": [{"name": "bge-m3", "kind": "embed"}],
                }
            )
        )
        raise AssertionError("expected the activity failure to propagate")
    except RuntimeError as exc:
        assert "model missing" in str(exc)

    assert runtime.calls == [WARM_MODEL_ACTIVITY]
