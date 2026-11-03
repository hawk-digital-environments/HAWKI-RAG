"""Server-owned control settings; existing Temporal defaults are unchanged."""

import os
from dataclasses import dataclass
from datetime import timedelta

from hawki_rag_contracts.pipeline.temporal import (
    INGEST_SOURCE_WORKFLOW,
    WORKFLOW_TASK_QUEUE,
)


@dataclass(frozen=True)
class WorkflowControlSettings:
    temporal_address: str = "temporal:7233"
    temporal_namespace: str = "default"
    workflow_type: str = INGEST_SOURCE_WORKFLOW
    workflow_task_queue: str = WORKFLOW_TASK_QUEUE
    workflow_execution_timeout_seconds: int = 172800
    workflow_run_timeout_seconds: int = 86400
    workflow_task_timeout_seconds: int = 30

    @property
    def workflow_execution_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_execution_timeout_seconds)

    @property
    def workflow_run_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_run_timeout_seconds)

    @property
    def workflow_task_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_task_timeout_seconds)

    def cron_for_cadence(self, cadence: str) -> str:
        return {"daily": "0 2 * * *", "weekly": "0 2 * * 0", "monthly": "0 2 1 * *"}[
            cadence.strip().lower()
        ]


def load_settings() -> WorkflowControlSettings:
    return WorkflowControlSettings(
        temporal_address=os.environ.get("TEMPORAL_ADDRESS", "temporal:7233"),
        temporal_namespace=os.environ.get("TEMPORAL_NAMESPACE", "default"),
        workflow_type=os.environ.get(
            "TEMPORAL_INGEST_WORKFLOW_TYPE", INGEST_SOURCE_WORKFLOW
        ),
        workflow_task_queue=os.environ.get(
            "TEMPORAL_RAG_WORKFLOW_TASK_QUEUE", WORKFLOW_TASK_QUEUE
        ),
        workflow_execution_timeout_seconds=int(
            os.environ.get("TEMPORAL_WORKFLOW_EXECUTION_TIMEOUT", "172800")
        ),
        workflow_run_timeout_seconds=int(
            os.environ.get("TEMPORAL_WORKFLOW_RUN_TIMEOUT", "86400")
        ),
        workflow_task_timeout_seconds=int(
            os.environ.get("TEMPORAL_WORKFLOW_TASK_TIMEOUT", "30")
        ),
    )
