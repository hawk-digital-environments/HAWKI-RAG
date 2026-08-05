"""Environment-backed settings read only by the workflow worker process."""

from __future__ import annotations

from dataclasses import dataclass
import os

from hawki_rag_contracts.temporal import WORKFLOW_TASK_QUEUE


def _environment_value(name: str, default: str) -> str:
    value = os.environ.get(name)
    if isinstance(value, str) and value.strip():
        return value.strip()
    return default


@dataclass(frozen=True, slots=True)
class WorkflowWorkerSettings:
    """The only runtime settings required by the workflow worker."""

    temporal_address: str
    temporal_namespace: str
    workflow_task_queue: str

    @classmethod
    def from_environment(cls) -> "WorkflowWorkerSettings":
        """Load settings at process startup, outside workflow execution."""

        return cls(
            temporal_address=_environment_value("TEMPORAL_ADDRESS", "temporal:7233"),
            temporal_namespace=_environment_value("TEMPORAL_NAMESPACE", "default"),
            workflow_task_queue=_environment_value(
                "TEMPORAL_RAG_WORKFLOW_TASK_QUEUE",
                WORKFLOW_TASK_QUEUE,
            ),
        )


__all__ = ["WorkflowWorkerSettings"]
