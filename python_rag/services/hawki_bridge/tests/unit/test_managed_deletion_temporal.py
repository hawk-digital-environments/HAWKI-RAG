"""Deterministic managed-operation identity across active, closed and failed runs."""

import asyncio
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.client import WorkflowFailureError
from temporalio.common import WorkflowIDConflictPolicy, WorkflowIDReusePolicy
from temporalio.exceptions import WorkflowAlreadyStartedError

from hawki_bridge.adapters.temporal_client import TemporalBridgeClient
from hawki_bridge.settings import load_settings


def fixture_client(monkeypatch):
    handle = SimpleNamespace(
        describe=AsyncMock(return_value=SimpleNamespace(run_id="run-1")),
        result=AsyncMock(return_value={"status": "completed", "results": []}),
        first_execution_run_id=None,
        result_run_id=None,
        run_id=None,
    )
    temporal = SimpleNamespace(
        start_workflow=AsyncMock(return_value=handle),
        get_workflow_handle=Mock(return_value=handle),
    )
    monkeypatch.setattr(
        TemporalBridgeClient, "connect_temporal", AsyncMock(return_value=temporal)
    )
    return TemporalBridgeClient(load_settings({})), temporal, handle


def test_active_deletion_returns_pending_without_changing_identity(monkeypatch):
    bridge, temporal, handle = fixture_client(monkeypatch)
    handle.result.side_effect = TimeoutError("still running")
    result = asyncio.run(
        bridge.start_managed_deletion(workflow_id="managed-delete-a", workflow_input={})
    )
    assert result == {
        "workflow_id": "managed-delete-a",
        "run_id": "run-1",
        "status": "pending",
    }
    assert (
        temporal.start_workflow.call_args.kwargs["id_reuse_policy"]
        == WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY
    )
    assert (
        temporal.start_workflow.call_args.kwargs["id_conflict_policy"]
        == WorkflowIDConflictPolicy.USE_EXISTING
    )


def test_completed_deletion_reuses_the_closed_execution(monkeypatch):
    bridge, temporal, _handle = fixture_client(monkeypatch)
    temporal.start_workflow.side_effect = WorkflowAlreadyStartedError(
        "managed-delete-a", "DeleteManagedDocumentWorkflow"
    )
    result = asyncio.run(
        bridge.start_managed_deletion(workflow_id="managed-delete-a", workflow_input={})
    )
    assert result["status"] == "completed" and result["run_id"] == "run-1"
    temporal.get_workflow_handle.assert_called_once_with("managed-delete-a")


def test_failed_execution_is_reported_failed(monkeypatch):
    bridge, _temporal, handle = fixture_client(monkeypatch)
    handle.result.side_effect = WorkflowFailureError(cause=RuntimeError("sink failed"))
    result = asyncio.run(
        bridge.start_managed_deletion(workflow_id="managed-delete-a", workflow_input={})
    )
    assert result["status"] == "failed"


def test_lost_replacement_start_ack_reuses_completed_run(monkeypatch):
    bridge, temporal, _handle = fixture_client(monkeypatch)
    temporal.start_workflow.side_effect = WorkflowAlreadyStartedError(
        "ingest-source-a", "IngestSourceWorkflow"
    )
    result = asyncio.run(
        bridge.start_ingest_workflow(
            workflow_id="ingest-source-a", workflow_input={}, reuse_completed=True
        )
    )
    assert result.run_id == "run-1"
    temporal.get_workflow_handle.assert_called_once_with("ingest-source-a")
    assert (
        temporal.start_workflow.call_args.kwargs["id_reuse_policy"]
        == WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY
    )


def test_ordinary_ingestion_keeps_existing_reuse_policy(monkeypatch):
    bridge, temporal, _handle = fixture_client(monkeypatch)
    asyncio.run(
        bridge.start_ingest_workflow(workflow_id="ingest-source-a", workflow_input={})
    )
    assert (
        temporal.start_workflow.call_args.kwargs["id_reuse_policy"]
        == WorkflowIDReusePolicy.ALLOW_DUPLICATE
    )


def test_ordinary_ingestion_does_not_swallow_unexpected_already_started(monkeypatch):
    bridge, temporal, _handle = fixture_client(monkeypatch)
    temporal.start_workflow.side_effect = WorkflowAlreadyStartedError(
        "ingest-source-a", "IngestSourceWorkflow"
    )
    with pytest.raises(WorkflowAlreadyStartedError):
        asyncio.run(
            bridge.start_ingest_workflow(
                workflow_id="ingest-source-a", workflow_input={}
            )
        )
