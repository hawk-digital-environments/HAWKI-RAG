"""URL identity rejects closed reuse and verifies complete execution provenance."""

import asyncio
from datetime import timedelta
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.common import WorkflowIDConflictPolicy, WorkflowIDReusePolicy
from temporalio.exceptions import WorkflowAlreadyStartedError
from temporalio.service import RPCError, RPCStatusCode

from hawki_workflow_control.settings import WorkflowControlSettings
from hawki_workflow_control.temporal_client import (
    TemporalControlClient,
    WorkflowOwnershipConflict,
)

URL_INPUT = {
    "owner": "url",
    "source_id": "source-a",
    "generation": 7,
    "url_ingestion_operation_id": "url-operation-a",
}
MEMO = {
    "hawki_control_operation": "control-operation-a",
    "hawki_workflow_family": "url",
    "hawki_workflow_type": "IngestSourceWorkflow",
    "hawki_source_id": "source-a",
    "hawki_url_generation": 7,
    "hawki_url_operation": "url-operation-a",
}


def adapter_fixture(memo=None, workflow_type="IngestSourceWorkflow"):
    description = SimpleNamespace(
        run_id="exact-run",
        workflow_type=workflow_type,
        memo=AsyncMock(return_value=MEMO if memo is None else memo),
    )
    handle = SimpleNamespace(
        first_execution_run_id="exact-run",
        describe=AsyncMock(return_value=description),
    )
    sdk = SimpleNamespace(
        start_workflow=AsyncMock(return_value=handle),
        get_workflow_handle=Mock(return_value=handle),
    )
    adapter = TemporalControlClient(WorkflowControlSettings())
    adapter.connect_temporal = AsyncMock(return_value=sdk)
    return adapter, sdk, handle


@pytest.mark.parametrize("replacement", [False, True])
@pytest.mark.parametrize("closed", [False, True])
def test_url_start_rejects_closed_reuse_and_recovers_exact_owned_run(
    replacement, closed
):
    adapter, sdk, _ = adapter_fixture()
    if closed:
        sdk.start_workflow.side_effect = WorkflowAlreadyStartedError(
            "workflow-a", "IngestSourceWorkflow", run_id="exact-run"
        )
    result = asyncio.run(
        adapter.start_ingest_workflow(
            workflow_id="workflow-a",
            workflow_input=URL_INPUT,
            control_operation="control-operation-a",
            reuse_completed=replacement,
        )
    )
    assert result.run_id == "exact-run"
    options = sdk.start_workflow.call_args.kwargs
    assert options["id_reuse_policy"] == WorkflowIDReusePolicy.REJECT_DUPLICATE
    assert options["id_conflict_policy"] == WorkflowIDConflictPolicy.USE_EXISTING
    assert options["memo"] == MEMO | {"hawki_url_dispatch_protocol": 1}
    assert options["execution_timeout"] is None
    assert options["run_timeout"] is None
    assert options["rpc_timeout"] == timedelta(seconds=5)
    assert all(
        call.kwargs == {"run_id": "exact-run"}
        for call in sdk.get_workflow_handle.call_args_list
    )


@pytest.mark.parametrize(
    "field", list(MEMO) + ["actual_type", "missing_run", "hawki_url_dispatch_protocol"]
)
@pytest.mark.parametrize("path", ["start", "closed_start", "reconcile"])
def test_url_adoption_fails_closed_for_every_foreign_ownership_field(field, path):
    memo = dict(MEMO)
    if field in memo or field == "hawki_url_dispatch_protocol":
        memo[field] = "foreign"
    adapter, sdk, handle = adapter_fixture(
        memo, "OtherWorkflow" if field == "actual_type" else "IngestSourceWorkflow"
    )
    if field == "missing_run":
        handle.describe.return_value.run_id = None
    if path == "closed_start":
        sdk.start_workflow.side_effect = WorkflowAlreadyStartedError(
            "workflow-a", "IngestSourceWorkflow", run_id="exact-run"
        )
    if path == "reconcile":
        invocation = adapter.reconcile_start(
            workflow_id="workflow-a",
            operation_id="control-operation-a",
            workflow_input=URL_INPUT,
        )
    else:
        invocation = adapter.start_ingest_workflow(
            workflow_id="workflow-a",
            workflow_input=URL_INPUT,
            control_operation="control-operation-a",
        )
    with pytest.raises(WorkflowOwnershipConflict):
        asyncio.run(invocation)


@pytest.mark.parametrize("status", ["RUNNING", "COMPLETED", "FAILED", "CANCELED"])
def test_reconciliation_recovers_active_and_closed_receipts_without_starting(status):
    adapter, sdk, handle = adapter_fixture()
    handle.describe.return_value.status = status
    receipt = asyncio.run(
        adapter.reconcile_start(
            workflow_id="workflow-a",
            operation_id="control-operation-a",
            workflow_input=URL_INPUT,
        )
    )
    assert receipt == {
        "workflow_id": "workflow-a",
        "run_id": "exact-run",
        "schedule_id": None,
    }
    sdk.start_workflow.assert_not_awaited()


@pytest.mark.parametrize(
    "status",
    [
        RPCStatusCode.NOT_FOUND,
        RPCStatusCode.UNAVAILABLE,
        RPCStatusCode.DEADLINE_EXCEEDED,
    ],
)
def test_only_definitive_not_found_is_absence(status):
    adapter, sdk, handle = adapter_fixture()
    handle.describe.side_effect = RPCError("lookup", status, b"")
    call = adapter.reconcile_start(
        workflow_id="workflow-a",
        operation_id="control-operation-a",
        workflow_input=URL_INPUT,
    )
    if status == RPCStatusCode.NOT_FOUND:
        assert asyncio.run(call) is None
    else:
        with pytest.raises(RPCError):
            asyncio.run(call)
    sdk.start_workflow.assert_not_awaited()


def test_url_start_requires_ledger_operation_before_submitting():
    adapter, sdk, _ = adapter_fixture()
    with pytest.raises(WorkflowOwnershipConflict):
        asyncio.run(
            adapter.start_ingest_workflow(workflow_id="a", workflow_input=URL_INPUT)
        )
    sdk.start_workflow.assert_not_awaited()


@pytest.mark.parametrize(
    "terminal",
    [
        None,
        {"status": "FAILED", "error": "exhausted"},
        {"status": "COMPLETED", "result": {"status": "failed"}},
    ],
)
def test_running_url_exposes_only_final_outcome_without_closing_it(terminal):
    from temporalio.client import WorkflowExecutionStatus

    adapter, sdk, handle = adapter_fixture(MEMO | {"hawki_url_dispatch_protocol": 1})
    handle.describe.return_value.status = WorkflowExecutionStatus.RUNNING
    handle.query = AsyncMock(return_value=terminal)
    handle.signal = AsyncMock()
    result = asyncio.run(
        adapter.workflow_status(
            workflow_id="workflow-a", run_id="exact-run", include_result=True
        )
    )
    assert result["run_id"] == "exact-run"
    assert result["status"] == (terminal["status"] if terminal else "RUNNING")
    assert result.get("awaiting_ack", False) is (terminal is not None)
    handle.signal.assert_not_awaited()
    sdk.start_workflow.assert_not_awaited()


@pytest.mark.parametrize("missing", [False, True])
def test_acknowledgement_is_exact_run_and_history_expiry_never_starts_again(missing):
    from temporalio.client import WorkflowExecutionStatus

    adapter, sdk, handle = adapter_fixture(MEMO | {"hawki_url_dispatch_protocol": 1})
    handle.describe.return_value.status = WorkflowExecutionStatus.RUNNING
    handle.signal = AsyncMock()
    if missing:
        handle.describe.side_effect = RPCError("expired", RPCStatusCode.NOT_FOUND, b"")
    asyncio.run(
        adapter.acknowledge_url_terminal(
            workflow_id="workflow-a",
            run_id="exact-run",
            operation_id="control-operation-a",
            workflow_input=URL_INPUT,
        )
    )
    sdk.get_workflow_handle.assert_called_once_with("workflow-a", run_id="exact-run")
    sdk.start_workflow.assert_not_awaited()
    assert handle.signal.await_count == (0 if missing else 1)


def test_foreign_run_cannot_be_acknowledged():
    from temporalio.client import WorkflowExecutionStatus

    adapter, _, handle = adapter_fixture(MEMO | {"hawki_url_operation": "foreign"})
    handle.describe.return_value.status = WorkflowExecutionStatus.RUNNING
    handle.signal = AsyncMock()
    with pytest.raises(WorkflowOwnershipConflict):
        asyncio.run(
            adapter.acknowledge_url_terminal(
                workflow_id="workflow-a",
                run_id="exact-run",
                operation_id="control-operation-a",
                workflow_input=URL_INPUT,
            )
        )
    handle.signal.assert_not_awaited()
