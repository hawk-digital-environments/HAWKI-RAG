"""Temporal cancellation and workflow reuse behavior at the bridge boundary."""

from __future__ import annotations

import asyncio
from typing import Any

import pytest
from temporalio.client import WorkflowExecutionStatus, WorkflowFailureError
from temporalio.common import WorkflowIDConflictPolicy, WorkflowIDReusePolicy
from temporalio.service import RPCError, RPCStatusCode

from hawki_bridge.adapters.temporal_client import TemporalBridgeClient
from hawki_bridge.settings import load_settings


def test_temporal_cancel_and_wait_closes_a_running_execution_before_returning(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    calls: list[str] = []

    class Description:
        status = WorkflowExecutionStatus.RUNNING

    class Handle:
        async def describe(self) -> Description:
            calls.append("describe")
            return Description()

        async def cancel(self) -> None:
            calls.append("cancel")

        async def result(self) -> None:
            calls.append("result")

    class TemporalClient:
        @staticmethod
        def get_workflow_handle(workflow_id: str, *, run_id: str | None = None):
            assert workflow_id == "ingest-text-source-1"
            assert run_id == "run-direct"
            return Handle()

    async def client(_self: Any) -> TemporalClient:
        return TemporalClient()

    monkeypatch.setattr(TemporalBridgeClient, "connect_temporal", client)

    asyncio.run(
        TemporalBridgeClient(load_settings({})).cancel_workflow_and_wait(
            workflow_id="ingest-text-source-1",
            run_id="run-direct",
        )
    )

    assert calls == ["describe", "cancel", "result"]


def test_temporal_cancel_and_wait_leaves_a_closed_execution_unchanged(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    calls: list[str] = []

    class Description:
        status = WorkflowExecutionStatus.COMPLETED

    class Handle:
        async def describe(self) -> Description:
            calls.append("describe")
            return Description()

        async def cancel(self) -> None:
            calls.append("cancel")

    class TemporalClient:
        @staticmethod
        def get_workflow_handle(workflow_id: str, *, run_id: str | None = None):
            return Handle()

    async def client(_self: Any) -> TemporalClient:
        return TemporalClient()

    monkeypatch.setattr(TemporalBridgeClient, "connect_temporal", client)

    asyncio.run(
        TemporalBridgeClient(load_settings({})).cancel_workflow_and_wait(
            workflow_id="ingest-text-source-1",
            run_id="run-direct",
        )
    )

    assert calls == ["describe"]


def test_temporal_cancel_and_wait_accepts_the_expected_cancelled_result(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    calls: list[str] = []

    class Description:
        status = WorkflowExecutionStatus.RUNNING

    class Handle:
        async def describe(self) -> Description:
            calls.append("describe")
            return Description()

        async def cancel(self) -> None:
            calls.append("cancel")

        async def result(self) -> None:
            calls.append("result")
            raise WorkflowFailureError(cause=RuntimeError("cancelled"))

    class TemporalClient:
        @staticmethod
        def get_workflow_handle(workflow_id: str, *, run_id: str | None = None):
            return Handle()

    async def client(_self: Any) -> TemporalClient:
        return TemporalClient()

    monkeypatch.setattr(TemporalBridgeClient, "connect_temporal", client)

    asyncio.run(
        TemporalBridgeClient(load_settings({})).cancel_workflow_and_wait(
            workflow_id="ingest-text-source-1",
            run_id="run-direct",
        )
    )

    assert calls == ["describe", "cancel", "result"]


@pytest.mark.parametrize(
    ("status", "raises"),
    [
        (RPCStatusCode.NOT_FOUND, False),
        (RPCStatusCode.UNAVAILABLE, True),
    ],
)
def test_temporal_cancel_is_idempotent_only_when_the_execution_is_absent(
    monkeypatch: pytest.MonkeyPatch,
    status: RPCStatusCode,
    raises: bool,
) -> None:
    class MissingWorkflowHandle:
        async def cancel(self) -> None:
            raise RPCError("workflow execution not found", status, b"")

    class TemporalClient:
        @staticmethod
        def get_workflow_handle(workflow_id: str, *, run_id: str | None = None):
            assert workflow_id == "ingest-source-stale"
            assert run_id == "run-stale"
            return MissingWorkflowHandle()

    async def client(_self):
        return TemporalClient()

    monkeypatch.setattr(TemporalBridgeClient, "connect_temporal", client)
    bridge = TemporalBridgeClient(load_settings({}))
    cancellation = bridge.cancel_workflow(
        workflow_id="ingest-source-stale",
        run_id="run-stale",
    )

    if raises:
        with pytest.raises(RPCError) as captured:
            asyncio.run(cancellation)
        assert captured.value.status == status
    else:
        asyncio.run(cancellation)


def test_direct_text_workflow_reuses_active_or_failed_executions_only(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    captured: dict[str, Any] = {}

    class Handle:
        first_execution_run_id = "run-direct"

    class TemporalClient:
        @staticmethod
        async def start_workflow(*args: Any, **kwargs: Any) -> Handle:
            captured["args"] = args
            captured["kwargs"] = kwargs
            return Handle()

    async def client(_self: Any) -> TemporalClient:
        return TemporalClient()

    monkeypatch.setattr(TemporalBridgeClient, "connect_temporal", client)
    bridge = TemporalBridgeClient(load_settings({}))

    execution = asyncio.run(
        bridge.start_text_ingest_workflow(
            workflow_id="ingest-text-source-1",
            workflow_input={"source_id": "source-1"},
        )
    )

    assert execution.run_id == "run-direct"
    assert (
        captured["kwargs"]["id_reuse_policy"]
        == WorkflowIDReusePolicy.ALLOW_DUPLICATE_FAILED_ONLY
    )
    assert (
        captured["kwargs"]["id_conflict_policy"]
        == WorkflowIDConflictPolicy.USE_EXISTING
    )
