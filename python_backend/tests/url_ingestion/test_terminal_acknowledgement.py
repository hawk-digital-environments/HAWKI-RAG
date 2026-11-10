"""PostgreSQL outcome evidence survives crashes and expired Temporal history."""

import asyncio
from unittest.mock import patch

import pytest
import sqlalchemy as sa

from hawki_backend.url_ingestion.scheduler import tick
from hawki_backend.workflow_control.schema import operations
from hawki_workflow_control.temporal_client import TemporalExecution
from hawki_workflow_control.temporal_client import WorkflowOwnershipConflict

from .conftest import configure, create
from .test_dispatch_recovery import ProcessStopped, interrupted_start
from .test_lifecycle import generation, ready
from .test_retries import outcome, set_outcome


def durable_start(env):
    async def start(**kwargs):
        return TemporalExecution(
            kwargs["workflow_id"],
            "run-" + kwargs["workflow_input"]["url_ingestion_operation_id"],
            url_dispatch_protocol=1,
        )

    env.port.start_ingest_workflow.side_effect = start
    return generation(env, create(env))


def terminal(row, success=False):
    return outcome(
        row,
        "COMPLETED",
        result={"status": "success" if success else "failed"},
        error=None,
        awaiting_ack=True,
    )


def test_outcome_and_finalization_commit_before_acknowledgement(env):
    row = durable_start(env)
    evidence = terminal(row)
    set_outcome(env, evidence)

    async def acknowledge(**kwargs):
        stored = env.repository.generation(row["url_ingestion_operation_id"])
        assert stored["writer_state"]["url_workflow_terminal"] == evidence
        assert stored["stage"] == "completed"
        assert (
            env.repository.source("default", row["source_id"])["pending_operation_id"]
            is None
        )
        assert kwargs["run_id"] == row["run_id"]

    env.port.acknowledge_url_terminal.side_effect = acknowledge
    assert asyncio.run(tick(env.service))[0]["result"] == "failed"
    assert asyncio.run(tick(env.service)) == []
    env.port.acknowledge_url_terminal.assert_awaited_once()
    env.port.start_ingest_workflow.assert_awaited_once()


@pytest.mark.parametrize("boundary", ["finalize", "ack", "save_ack"])
def test_restart_recovers_durable_outcome_without_temporal_history(env, boundary):
    row = durable_start(env)
    set_outcome(env, terminal(row))
    target = {
        "finalize": (env.repository, "fail_workflow"),
        "ack": (env.port, "acknowledge_url_terminal"),
        "save_ack": (env.repository, "acknowledge_workflow_terminal"),
    }[boundary]
    with patch.object(*target, side_effect=ProcessStopped):
        with pytest.raises(ProcessStopped):
            asyncio.run(tick(env.service))
    recovered = configure(env.engine, env.root)
    recovered.port.workflow_status.side_effect = AssertionError(
        "Expired history must not be read"
    )
    asyncio.run(tick(recovered.service))
    stored = recovered.repository.generation(row["url_ingestion_operation_id"])
    assert stored["stage"] == "completed"
    assert stored["writer_state"]["url_workflow_acknowledged"] is True
    assert stored["run_id"] == row["run_id"]
    assert stored["generation"] == row["generation"]
    assert (
        recovered.repository.source("default", row["source_id"])["pending_operation_id"]
        is None
    )
    assert asyncio.run(tick(recovered.service)) == []
    recovered.port.start_ingest_workflow.assert_not_awaited()
    recovered.port.workflow_status.assert_not_awaited()
    recovered.port.acknowledge_url_terminal.assert_awaited_once()


def test_lost_ack_response_remains_in_scheduler_inventory_after_finalization(env):
    row = durable_start(env)
    set_outcome(env, terminal(row))
    env.port.acknowledge_url_terminal.side_effect = TimeoutError()
    assert asyncio.run(tick(env.service))[0]["result"] == "pending"
    assert (
        env.repository.source("default", row["source_id"])["pending_operation_id"]
        is None
    )
    env.port.workflow_status.side_effect = AssertionError(
        "Use durable terminal evidence"
    )
    env.port.acknowledge_url_terminal.side_effect = None
    assert asyncio.run(tick(env.service)) == []
    assert env.port.acknowledge_url_terminal.await_count == 2
    assert env.repository.generation(row["url_ingestion_operation_id"])["writer_state"][
        "url_workflow_acknowledged"
    ]


def test_publication_before_dispatch_receipt_is_still_acknowledged(env):
    row = interrupted_start(env, "control_receipt")
    row["run_id"] = "run-" + row["url_ingestion_operation_id"]
    ready(env, row)
    recovered = configure(env.engine, env.root)
    recovered.port.reconcile_start.return_value = {
        "workflow_id": row["workflow_id"],
        "run_id": row["run_id"],
        "url_dispatch_protocol": 1,
    }
    set_outcome(recovered, terminal(row, success=True))
    assert asyncio.run(tick(recovered.service)) == []
    stored = recovered.repository.generation(row["url_ingestion_operation_id"])
    assert stored["stage"] == "completed"
    assert stored["workflow_operation_id"] is not None
    assert stored["writer_state"]["url_workflow_acknowledged"]
    assert recovered.repository.source("default", row["source_id"])["status"] == "ready"
    recovered.port.start_ingest_workflow.assert_not_awaited()
    recovered.port.acknowledge_url_terminal.assert_awaited_once()


def test_success_cannot_close_before_publication_is_durable(env):
    row = durable_start(env)
    set_outcome(env, terminal(row, success=True))
    assert asyncio.run(tick(env.service))[0]["result"] == "pending"
    env.port.acknowledge_url_terminal.assert_not_awaited()
    ready(env, row)
    assert asyncio.run(tick(env.service)) == []
    env.port.acknowledge_url_terminal.assert_awaited_once()


def test_legacy_uncertain_absence_does_not_mean_never_executed(env):
    row = interrupted_start(env, "after_claim")
    with env.engine.begin() as connection:
        ledger = connection.execute(sa.select(operations)).mappings().one()
        payload = dict(ledger["payload"])
        payload.pop("url_dispatch_protocol")
        connection.execute(operations.update().values(payload=payload))
    recovered = configure(env.engine, env.root)
    assert asyncio.run(tick(recovered.service))[0]["result"] == "pending"
    recovered.port.start_ingest_workflow.assert_not_awaited()
    assert (
        recovered.repository.source("default", row["source_id"])["pending_operation_id"]
        == row["url_ingestion_operation_id"]
    )


@pytest.mark.parametrize("boundary", ["before_start", "after_claim"])
def test_foreign_ownership_stays_closed_after_foreign_history_expires(env, boundary):
    row = interrupted_start(env, boundary)
    recovered = configure(env.engine, env.root)
    if boundary == "before_start":
        recovered.port.start_ingest_workflow.side_effect = WorkflowOwnershipConflict()
    else:
        recovered.port.reconcile_start.side_effect = WorkflowOwnershipConflict()
    assert asyncio.run(tick(recovered.service))[0]["result"] == "pending"
    before = recovered.port.start_ingest_workflow.await_count
    recovered.port.reconcile_start.side_effect = None
    recovered.port.reconcile_start.return_value = None
    assert asyncio.run(tick(recovered.service))[0]["result"] == "pending"
    assert recovered.port.start_ingest_workflow.await_count == before
    assert (
        recovered.repository.source("default", row["source_id"])["pending_operation_id"]
        == row["url_ingestion_operation_id"]
    )


def test_late_foreign_observation_cannot_erase_a_confirmed_receipt(env):
    row = durable_start(env)
    with env.engine.connect() as connection:
        before = dict(connection.execute(sa.select(operations)).mappings().one())
    env.control.repository.reject_url_ownership(row["workflow_operation_id"])
    with env.engine.connect() as connection:
        assert (
            dict(connection.execute(sa.select(operations)).mappings().one()) == before
        )


def test_stale_terminal_snapshot_cannot_erase_acknowledgement_or_writer_state(env):
    row = durable_start(env)
    evidence = terminal(row)
    env.repository.record_workflow_terminal(row, evidence)
    env.repository.acknowledge_workflow_terminal(row)
    env.repository.record_workflow_terminal(row, evidence)
    state = env.repository.generation(row["url_ingestion_operation_id"])["writer_state"]
    assert state["url_workflow_acknowledged"]
    assert state["managed_writer_gate"] == "open"


@pytest.mark.parametrize(
    "mutation", ["run_id", "workflow_id", "status", "awaiting_ack"]
)
def test_foreign_terminal_evidence_is_rejected(env, mutation):
    from hawki_backend.url_ingestion.errors import UrlIngestionError

    row = durable_start(env)
    evidence = terminal(row) | {mutation: "foreign"}
    with pytest.raises(UrlIngestionError):
        env.repository.record_workflow_terminal(row, evidence)
    assert (
        "url_workflow_terminal"
        not in env.repository.generation(row["url_ingestion_operation_id"])[
            "writer_state"
        ]
    )
