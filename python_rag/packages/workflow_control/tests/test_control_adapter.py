"""Actual adapter options and reconciliation against simulated SDK handles."""

import asyncio
from datetime import timedelta
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.common import WorkflowIDConflictPolicy, WorkflowIDReusePolicy
from temporalio.service import RPCError, RPCStatusCode

from hawki_workflow_control.settings import WorkflowControlSettings
from hawki_workflow_control.temporal_client import (
    TemporalControlClient,
    WorkflowOwnershipConflict,
)


@pytest.mark.parametrize(
    "action,replacement,workflow,policy",
    [
        (
            "source",
            False,
            "IngestSourceWorkflow",
            WorkflowIDReusePolicy.ALLOW_DUPLICATE,
        ),
        (
            "source",
            True,
            "IngestSourceWorkflow",
            WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY,
        ),
        (
            "text",
            False,
            "IngestTextWorkflow",
            WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY,
        ),
        (
            "deletion",
            False,
            "DeleteManagedDocumentWorkflow",
            WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY,
        ),
    ],
)
def test_control_keeps_names_queues_timeouts_and_reuse_policies(
    action, replacement, workflow, policy
):
    handle = SimpleNamespace(
        first_execution_run_id="run-a",
        describe=AsyncMock(
            return_value=SimpleNamespace(
                run_id="run-a",
                memo=AsyncMock(return_value={"hawki_control_operation": "operation-a"}),
            )
        ),
        result=AsyncMock(return_value={"status": "completed"}),
    )
    sdk = SimpleNamespace(start_workflow=AsyncMock(return_value=handle))
    adapter = TemporalControlClient(WorkflowControlSettings())
    adapter.connect_temporal = AsyncMock(return_value=sdk)
    arguments = {
        "workflow_id": "compatible-id",
        "workflow_input": {"reference": "unchanged"},
        "control_operation": "operation-a",
    }
    if action == "source":
        invocation = adapter.start_ingest_workflow(
            **arguments, reuse_completed=replacement
        )
    elif action == "text":
        invocation = adapter.start_text_ingest_workflow(**arguments)
    else:
        invocation = adapter.start_managed_deletion(**arguments)
    asyncio.run(invocation)
    assert sdk.start_workflow.call_args.args == (workflow, {"reference": "unchanged"})
    options = sdk.start_workflow.call_args.kwargs
    assert options["id"] == "compatible-id"
    assert options["task_queue"] == "rag-workflow-task-queue"
    assert options["id_reuse_policy"] == policy
    assert options["id_conflict_policy"] == WorkflowIDConflictPolicy.USE_EXISTING
    assert options["execution_timeout"] == timedelta(seconds=172800)
    assert options["run_timeout"] == timedelta(seconds=86400)
    assert options["task_timeout"] == timedelta(seconds=30)
    assert options["memo"] == {"hawki_control_operation": "operation-a"}


@pytest.mark.parametrize(
    "memo,adopt",
    [
        ({}, False),
        ({"hawki_control_operation": "other"}, False),
        ({"hawki_control_operation": "operation-a"}, True),
    ],
)
def test_reconciliation_adopts_only_exact_operation_memo(memo, adopt):
    description = SimpleNamespace(
        run_id="proven-run", memo=AsyncMock(return_value=memo)
    )
    handle = SimpleNamespace(describe=AsyncMock(return_value=description))
    sdk = SimpleNamespace(
        get_workflow_handle=Mock(return_value=handle), start_workflow=AsyncMock()
    )
    adapter = TemporalControlClient(WorkflowControlSettings())
    adapter.connect_temporal = AsyncMock(return_value=sdk)
    receipt = asyncio.run(
        adapter.reconcile_start(workflow_id="compatible-id", operation_id="operation-a")
    )
    assert (receipt is not None) is adopt
    if adopt:
        assert receipt["run_id"] == "proven-run"
    sdk.start_workflow.assert_not_awaited()


@pytest.mark.parametrize(
    "status,raises",
    [(RPCStatusCode.NOT_FOUND, False), (RPCStatusCode.UNAVAILABLE, True)],
)
def test_schedule_delete_cannot_report_success_on_transport_failure(status, raises):
    handle = SimpleNamespace(
        delete=AsyncMock(side_effect=RPCError("sensitive endpoint", status, b""))
    )
    sdk = SimpleNamespace(get_schedule_handle=Mock(return_value=handle))
    adapter = TemporalControlClient(WorkflowControlSettings())
    adapter.connect_temporal = AsyncMock(return_value=sdk)
    call = adapter.delete_schedule(schedule_id="schedule-ingest-source-a")
    if raises:
        with pytest.raises(RPCError):
            asyncio.run(call)
    else:
        asyncio.run(call)


@pytest.mark.parametrize(
    "memo", [{}, {"hawki_control_operation": "different-task-operation"}]
)
def test_use_existing_does_not_assign_another_tasks_run_to_new_preparation(memo):
    handle = SimpleNamespace(
        first_execution_run_id="other-run",
        describe=AsyncMock(
            return_value=SimpleNamespace(memo=AsyncMock(return_value=memo))
        ),
    )
    sdk = SimpleNamespace(start_workflow=AsyncMock(return_value=handle))
    adapter = TemporalControlClient(WorkflowControlSettings())
    adapter.connect_temporal = AsyncMock(return_value=sdk)
    with pytest.raises(WorkflowOwnershipConflict):
        asyncio.run(
            adapter.start_ingest_workflow(
                workflow_id="stable-source-id",
                workflow_input={"job_id": "new-job"},
                control_operation="new-operation",
            )
        )
