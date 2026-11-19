"""URL failure reconciliation reads only exact, closed Temporal executions."""

import asyncio
from datetime import timedelta
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from google.protobuf.any_pb2 import Any
from temporalio.api.common.v1 import GrpcStatus
from temporalio.api.errordetails.v1 import NamespaceNotFoundFailure, NotFoundFailure
from temporalio.client import WorkflowExecutionStatus
from temporalio.service import RPCError, RPCStatusCode

from hawki_workflow_control.settings import WorkflowControlSettings
from hawki_workflow_control.temporal_client import (
    TemporalControlClient,
    TemporalUnavailable,
)


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


def not_found_error(*failures, raw=None):
    details = []
    for failure in failures:
        detail = Any()
        detail.Pack(failure)
        details.append(detail)
    status = GrpcStatus(code=5, details=details)
    return RPCError(
        "private error",
        RPCStatusCode.NOT_FOUND,
        status.SerializeToString() if raw is None else raw,
    )


@pytest.mark.parametrize("code", ["NOT_FOUND", "UNAVAILABLE", "DEADLINE_EXCEEDED"])
def test_missing_history_is_distinct_from_outage_and_never_terminal(code):
    handle = SimpleNamespace(
        describe=AsyncMock(
            side_effect=not_found_error(NotFoundFailure())
            if code == "NOT_FOUND"
            else RPCError("private error", RPCStatusCode[code], b"")
        ),
        result=AsyncMock(),
    )
    sdk = SimpleNamespace(get_workflow_handle=Mock(return_value=handle))
    adapter = TemporalControlClient(WorkflowControlSettings())
    adapter.connect_temporal = AsyncMock(return_value=sdk)
    operation = adapter.workflow_status(workflow_id="workflow", run_id="exact-run")
    if code == "NOT_FOUND":
        assert asyncio.run(operation) == {
            "workflow_id": "workflow",
            "run_id": "exact-run",
            "status": "NOT_FOUND",
            "error": None,
        }
    else:
        with pytest.raises(RPCError):
            asyncio.run(operation)
    sdk.get_workflow_handle.assert_called_once_with("workflow", run_id="exact-run")
    handle.result.assert_not_awaited()


@pytest.mark.parametrize(
    "error",
    [
        not_found_error(NamespaceNotFoundFailure(namespace="wrong-namespace")),
        not_found_error(),
        not_found_error(NotFoundFailure(), NamespaceNotFoundFailure(namespace="wrong")),
        not_found_error(raw=b"malformed"),
        not_found_error(raw=GrpcStatus(code=14).SerializeToString()),
        RPCError("proxy 404", RPCStatusCode.NOT_FOUND, b""),
    ],
)
def test_uncertain_not_found_never_becomes_workflow_absence(error):
    adapter = TemporalControlClient(WorkflowControlSettings())
    handle = SimpleNamespace(describe=AsyncMock(side_effect=error))
    adapter.connect_temporal = AsyncMock(
        return_value=SimpleNamespace(
            get_workflow_handle=Mock(return_value=handle),
        )
    )
    with pytest.raises(TemporalUnavailable):
        asyncio.run(adapter.workflow_status(workflow_id="workflow", run_id="run"))


def test_connection_failure_is_not_execution_absence():
    adapter = TemporalControlClient(WorkflowControlSettings())
    adapter.connect_temporal = AsyncMock(side_effect=TemporalUnavailable())
    with pytest.raises(TemporalUnavailable):
        asyncio.run(adapter.workflow_status(workflow_id="workflow", run_id="run"))
