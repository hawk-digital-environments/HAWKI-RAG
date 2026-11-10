"""URL failure reconciliation reads only exact, closed Temporal executions."""

import asyncio
from datetime import timedelta
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.client import WorkflowExecutionStatus

from hawki_workflow_control.settings import WorkflowControlSettings
from hawki_workflow_control.temporal_client import TemporalControlClient


@pytest.mark.parametrize("include_result", [False, True])
@pytest.mark.parametrize(
    "status",
    [
        WorkflowExecutionStatus.RUNNING,
        WorkflowExecutionStatus.COMPLETED,
        WorkflowExecutionStatus.FAILED,
        WorkflowExecutionStatus.TIMED_OUT,
        WorkflowExecutionStatus.CANCELED,
        WorkflowExecutionStatus.TERMINATED,
    ],
)
def test_workflow_status_reads_result_only_when_requested_and_completed(
    status, include_result
):
    handle = SimpleNamespace(
        describe=AsyncMock(
            return_value=SimpleNamespace(status=status, memo=AsyncMock(return_value={}))
        ),
        fetch_history=AsyncMock(return_value=SimpleNamespace(events=[])),
        result=AsyncMock(return_value={"status": "failed", "phase": "scrape_source"}),
    )
    sdk = SimpleNamespace(get_workflow_handle=Mock(return_value=handle))
    adapter = TemporalControlClient(WorkflowControlSettings())
    adapter.connect_temporal = AsyncMock(return_value=sdk)
    receipt = asyncio.run(
        adapter.workflow_status(
            workflow_id="url-workflow",
            run_id="exact-run",
            include_result=include_result,
        )
    )
    sdk.get_workflow_handle.assert_called_once_with("url-workflow", run_id="exact-run")
    assert receipt["status"] == status.name
    if include_result and status is WorkflowExecutionStatus.COMPLETED:
        assert receipt["result"] == {"status": "failed", "phase": "scrape_source"}
        handle.result.assert_awaited_once_with(
            follow_runs=False, rpc_timeout=timedelta(seconds=5)
        )
    else:
        assert set(receipt) == {"workflow_id", "run_id", "status", "error"}
        handle.result.assert_not_awaited()
