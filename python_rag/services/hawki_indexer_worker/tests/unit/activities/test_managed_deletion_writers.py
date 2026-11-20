"""The sink barrier rejects unknown and running writer executions."""

import asyncio
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.client import WorkflowExecutionStatus
from temporalio.exceptions import ApplicationError

from hawki_indexer_worker.activities import deletion


@pytest.mark.parametrize("status", [WorkflowExecutionStatus.RUNNING, None])
def test_writer_barrier_rejects_execution_without_terminal_proof(monkeypatch, status):
    client = SimpleNamespace(
        get_workflow_handle=Mock(
            return_value=SimpleNamespace(
                describe=AsyncMock(return_value=SimpleNamespace(status=status))
            )
        )
    )
    monkeypatch.setattr(deletion.Client, "connect", AsyncMock(return_value=client))
    monkeypatch.setattr(
        deletion.IndexerSettings,
        "from_env",
        lambda: SimpleNamespace(
            temporal_address="test:7233", temporal_namespace="test"
        ),
    )
    body = {
        "operation_id": "delete_" + "d" * 64,
        "managed_document_id": "adoc_a",
        "dataset_id": "dataset-a",
        "targets": [],
        "writers": [{"workflow_id": "writer-a", "run_id": "writer-run"}],
    }
    with pytest.raises(ApplicationError):
        asyncio.run(deletion.verify_managed_deletion_writers(body))


def test_writer_barrier_reads_the_recorded_execution(monkeypatch):
    description = AsyncMock(
        return_value=SimpleNamespace(status=WorkflowExecutionStatus.COMPLETED)
    )
    client = SimpleNamespace(
        get_workflow_handle=Mock(return_value=SimpleNamespace(describe=description))
    )
    monkeypatch.setattr(deletion.Client, "connect", AsyncMock(return_value=client))
    monkeypatch.setattr(
        deletion.IndexerSettings,
        "from_env",
        lambda: SimpleNamespace(
            temporal_address="test:7233", temporal_namespace="test"
        ),
    )
    body = {
        "operation_id": "delete_" + "d" * 64,
        "managed_document_id": "adoc_a",
        "dataset_id": "dataset-a",
        "targets": [],
        "writers": [{"workflow_id": "writer-a", "run_id": "writer-run"}],
    }
    asyncio.run(deletion.verify_managed_deletion_writers(body))
    client.get_workflow_handle.assert_called_once_with("writer-a", run_id="writer-run")
