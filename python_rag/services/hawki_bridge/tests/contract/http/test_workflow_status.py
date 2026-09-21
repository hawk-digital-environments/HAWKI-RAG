"""Specific-run workflow status and terminal failure reporting."""

import asyncio
from datetime import timedelta
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.api.failure.v1 import Failure
from temporalio.api.history.v1 import (
    HistoryEvent,
    WorkflowExecutionFailedEventAttributes,
)
from temporalio.client import WorkflowExecutionStatus, WorkflowHistoryEventFilterType

from hawki_bridge.adapters.temporal_client import TemporalBridgeClient
from hawki_bridge.http.routers.temporal import build_temporal_router


@pytest.mark.parametrize(
    "status", [WorkflowExecutionStatus.RUNNING, WorkflowExecutionStatus.FAILED]
)
def test_status_reads_only_the_requested_run_and_fetches_only_terminal_failure(status):
    event = HistoryEvent(
        workflow_execution_failed_event_attributes=WorkflowExecutionFailedEventAttributes(
            failure=Failure(
                message="Activity task failed",
                cause=Failure(message="Complete result exceeds size limit."),
            )
        )
    )
    handle = SimpleNamespace(
        describe=AsyncMock(return_value=SimpleNamespace(status=status)),
        fetch_history=AsyncMock(return_value=SimpleNamespace(events=[event])),
    )
    temporal = Mock()
    temporal.get_workflow_handle.return_value = handle
    bridge = TemporalBridgeClient(SimpleNamespace())
    bridge.connect_temporal = AsyncMock(return_value=temporal)
    result = asyncio.run(bridge.workflow_status(workflow_id="workflow", run_id="run"))
    temporal.get_workflow_handle.assert_called_once_with("workflow", run_id="run")
    assert result["status"] == status.name
    if status == WorkflowExecutionStatus.FAILED:
        assert (
            result["error"]
            == "Activity task failed: Complete result exceeds size limit."
        )
        handle.fetch_history.assert_awaited_once_with(
            event_filter_type=WorkflowHistoryEventFilterType.CLOSE_EVENT,
            rpc_timeout=timedelta(seconds=5),
        )
    else:
        assert result["error"] is None
        handle.fetch_history.assert_not_awaited()


def test_status_endpoint_requires_run_id_and_delegates(asgi_test_client_class):
    from fastapi import FastAPI

    bridge = SimpleNamespace(
        workflow_status=AsyncMock(
            return_value={
                "workflow_id": "workflow",
                "run_id": "run",
                "status": "RUNNING",
                "error": None,
            }
        )
    )
    app = FastAPI()
    app.include_router(
        build_temporal_router(
            settings=None, logger=Mock(), client_factory=lambda _: bridge
        )
    )
    with asgi_test_client_class(app) as client:
        assert (
            client.post(
                "/temporal/workflows/status", json={"workflow_id": "workflow"}
            ).status_code
            == 422
        )
        result = client.post(
            "/temporal/workflows/status",
            json={"workflow_id": "workflow", "run_id": "run"},
        )
        assert result.status_code == 200
        assert result.json()["status"] == "RUNNING"
    bridge.workflow_status.assert_awaited_once_with(
        workflow_id="workflow", run_id="run"
    )
