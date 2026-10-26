"""Temporal orchestration that keeps configured RAG inference models loaded."""

from __future__ import annotations

from datetime import timedelta
from typing import Any

from temporalio import workflow

from hawki_rag_contracts.pipeline.temporal import (
    MODEL_WARMUP_WORKFLOW,
    WARM_MODEL_ACTIVITY,
    WARM_RETRIEVAL_ACTIVITY,
)
from hawki_workflow_worker.workflows.retry_policy import warmup_activity_retry_policy

# First inference after a cold model load can download weights; allow minutes.
_WARM_ACTIVITY_START_TO_CLOSE = timedelta(minutes=10)
_WARM_ACTIVITY_SCHEDULE_TO_CLOSE = timedelta(minutes=15)


@workflow.defn(name=MODEL_WARMUP_WORKFLOW)
class ModelWarmupWorkflow:
    """Load every configured model, then run one real retrieval query.

    Model warmup activities run sequentially on the workflow worker's own
    task queue: model loads contend for one accelerator, so parallelism buys
    nothing. The final activity exercises the bridge service API end to end
    (embed -> search -> rerank), which also loads the reranker model. The
    provider is part of the workflow input, resolved from configuration at
    worker startup, so the warmup follows provider switches without code
    changes.
    """

    @workflow.run
    async def run(self, workflow_input: dict[str, Any]) -> dict[str, Any]:
        provider = str(workflow_input.get("provider") or "ollama")
        query = str(workflow_input.get("query") or "Reply with OK.")
        models = [
            model
            for model in workflow_input.get("models", [])
            if isinstance(model, dict) and model.get("name")
        ]
        task_queue = workflow.info().task_queue
        retry_policy = warmup_activity_retry_policy()

        steps: list[dict[str, Any]] = []
        for model in models:
            steps.append(
                await workflow.execute_activity(
                    WARM_MODEL_ACTIVITY,
                    {
                        "provider": provider,
                        "model": str(model.get("name")),
                        "kind": str(model.get("kind") or "chat"),
                        "query": query,
                    },
                    task_queue=task_queue,
                    start_to_close_timeout=_WARM_ACTIVITY_START_TO_CLOSE,
                    schedule_to_close_timeout=_WARM_ACTIVITY_SCHEDULE_TO_CLOSE,
                    retry_policy=retry_policy,
                )
            )

        steps.append(
            await workflow.execute_activity(
                WARM_RETRIEVAL_ACTIVITY,
                {"query": query},
                task_queue=task_queue,
                start_to_close_timeout=_WARM_ACTIVITY_START_TO_CLOSE,
                schedule_to_close_timeout=_WARM_ACTIVITY_SCHEDULE_TO_CLOSE,
                retry_policy=retry_policy,
            )
        )

        return {"status": "ok", "steps": steps}


__all__ = ["ModelWarmupWorkflow"]
