"""Scheduler-only recovery of the manual start/dispatch acknowledgement gap."""

import asyncio
from unittest.mock import AsyncMock, patch

import pytest
import sqlalchemy as sa

from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.scheduler import tick
from hawki_backend.url_ingestion.schema import generations, requests, sources
from hawki_backend.workflow_control.schema import operations
from hawki_workflow_control.temporal_client import (
    TemporalUnavailable,
    WorkflowOwnershipConflict,
)

from .conftest import configure, create
from .test_retries import outcome, set_outcome


class ProcessStopped(BaseException):
    """Simulate process death without dispatch exception recovery running."""


def interrupted_start(env, boundary="url_receipt", **changes):
    claim = env.control.repository.claim

    def crash_after_claim(operation_id, **kwargs):
        assert claim(operation_id, **kwargs)
        raise ProcessStopped()

    target = {
        "url_receipt": (env.repository, "dispatched"),
        "control_receipt": (env.control.repository, "finish"),
        "before_start": (env.control, "start"),
        "after_claim": (env.control.repository, "claim"),
    }[boundary]
    effect = crash_after_claim if boundary == "after_claim" else ProcessStopped
    with patch.object(*target, side_effect=effect):
        with pytest.raises(ProcessStopped):
            create(env, **changes)
    with env.engine.connect() as connection:
        row = dict(connection.execute(sa.select(generations)).mappings().one())
    assert row["stage"] == "prepared" and row["run_id"] is None
    assert row["workflow_operation_id"] is None
    assert (
        env.repository.source("default", row["source_id"])["pending_operation_id"]
        == row["url_ingestion_operation_id"]
    )
    return row


def restart(env, row):
    restarted = configure(env.engine, env.root)
    restarted.port.reconcile_start.return_value = {
        "workflow_id": row["workflow_id"],
        "run_id": "run-" + row["url_ingestion_operation_id"],
        "schedule_id": None,
    }
    return restarted


def assert_same_operation(env, row):
    with env.engine.connect() as connection:
        for table in (sources, generations, requests, operations):
            assert connection.scalar(sa.select(sa.func.count()).select_from(table)) == 1
    stored = env.repository.generation(row["url_ingestion_operation_id"])
    assert stored["generation"] == row["generation"]
    assert stored["workflow_id"] == row["workflow_id"]
    assert stored["stage"] == "dispatched"
    assert stored["run_id"] == "run-" + row["url_ingestion_operation_id"]
    assert stored["workflow_operation_id"] is not None


@pytest.mark.parametrize("cadence", [None, "none", ""])
@pytest.mark.parametrize("boundary", ["url_receipt", "control_receipt"])
def test_manual_accepted_start_recovers_after_restart_without_request_replay(
    env, cadence, boundary
):
    row = interrupted_start(env, boundary, refresh_cadence=cadence)
    env.port.start_ingest_workflow.assert_awaited_once()
    recovered = restart(env, row)
    recovered.service.recrawl = AsyncMock(side_effect=AssertionError("new generation"))
    assert asyncio.run(tick(recovered.service)) == [
        {"source_id": row["source_id"], "result": "resumed"}
    ]
    assert_same_operation(recovered, row)
    recovered.port.start_ingest_workflow.assert_not_awaited()
    assert recovered.port.reconcile_start.await_count == (
        1 if boundary == "control_receipt" else 0
    )
    assert asyncio.run(tick(recovered.service)) == []
    recovered.port.start_ingest_workflow.assert_not_awaited()
    recovered.service.recrawl.assert_not_awaited()
    stored = recovered.repository.generation(row["url_ingestion_operation_id"])
    set_outcome(recovered, outcome(stored, "FAILED"))
    assert asyncio.run(tick(recovered.service)) == [
        {"source_id": row["source_id"], "result": "failed"}
    ]
    source = recovered.repository.source("default", row["source_id"])
    assert source["pending_operation_id"] is None and source["status"] == "failed"


def test_manual_prepared_start_not_yet_submitted_is_dispatched_once(env):
    row = interrupted_start(env, "before_start")
    env.port.start_ingest_workflow.assert_not_awaited()
    recovered = restart(env, row)
    assert asyncio.run(tick(recovered.service))[0]["result"] == "resumed"
    assert_same_operation(recovered, row)
    assert asyncio.run(tick(recovered.service)) == []
    recovered.port.start_ingest_workflow.assert_awaited_once()
    recovered.port.reconcile_start.assert_not_awaited()


def test_crash_after_committed_claim_before_temporal_rpc_recovers_same_operation(env):
    row = interrupted_start(env, "after_claim", refresh_cadence=None)
    env.port.start_ingest_workflow.assert_not_awaited()
    with env.engine.connect() as connection:
        ledger = dict(connection.execute(sa.select(operations)).mappings().one())
    assert ledger["status"] == "dispatching" and ledger["receipt"] is None
    recovered = configure(env.engine, env.root)
    # Definitive NOT_FOUND from Temporal, rather than an unavailable lookup.
    recovered.port.reconcile_start.return_value = None
    assert asyncio.run(tick(recovered.service)) == [
        {"source_id": row["source_id"], "result": "resumed"}
    ]
    assert_same_operation(recovered, row)
    recovered.port.start_ingest_workflow.assert_awaited_once()
    call = recovered.port.start_ingest_workflow.call_args.kwargs
    assert call["control_operation"] == ledger["operation_id"]
    assert call["workflow_input"] == row["workflow_input"]
    assert asyncio.run(tick(recovered.service)) == []
    recovered.port.start_ingest_workflow.assert_awaited_once()


def test_scheduled_prepared_start_still_recovers(env):
    row = interrupted_start(env, "before_start")
    with env.engine.begin() as connection:
        body = dict(connection.execute(sa.select(requests.c.body)).scalar_one())
        body["_scheduled"] = True
        connection.execute(requests.update().values(body=body))
    assert asyncio.run(tick(env.service))[0]["result"] == "resumed"
    assert_same_operation(env, row)
    env.port.start_ingest_workflow.assert_awaited_once()


def test_prepared_inventory_is_not_truncated_by_refresh_batch_limit(env):
    with patch.object(env.control, "start", side_effect=ProcessStopped):
        for index in range(2):
            with pytest.raises(ProcessStopped):
                create(env, str(index), source_url=f"https://example.org/{index}")
    assert [item["result"] for item in asyncio.run(tick(env.service, limit=1))] == [
        "resumed",
        "resumed",
    ]
    assert env.port.start_ingest_workflow.await_count == 2
    assert asyncio.run(tick(env.service, limit=1)) == []


def test_uncertain_acceptance_unavailable_retains_ownership_without_redispatch(env):
    row = interrupted_start(env, "control_receipt")
    recovered = configure(env.engine, env.root)
    recovered.port.reconcile_start.side_effect = TemporalUnavailable()
    for _ in range(2):
        assert asyncio.run(tick(recovered.service)) == [
            {"source_id": row["source_id"], "result": "pending"}
        ]
    recovered.port.start_ingest_workflow.assert_not_awaited()
    assert (
        recovered.repository.source("default", row["source_id"])["pending_operation_id"]
        == row["url_ingestion_operation_id"]
    )


@pytest.mark.parametrize("error", [TimeoutError(), WorkflowOwnershipConflict()])
def test_ambiguous_or_foreign_lookup_retains_operation_and_can_recover_later(
    env, error
):
    row = interrupted_start(env, "after_claim")
    recovered = configure(env.engine, env.root)
    recovered.port.reconcile_start.side_effect = error
    for _ in range(2):
        assert asyncio.run(tick(recovered.service))[0]["result"] == "pending"
    recovered.port.start_ingest_workflow.assert_not_awaited()
    source = recovered.repository.source("default", row["source_id"])
    assert source["generation"] == row["generation"]
    assert source["pending_operation_id"] == row["url_ingestion_operation_id"]
    recovered.port.reconcile_start.side_effect = None
    recovered.port.reconcile_start.return_value = {
        "workflow_id": row["workflow_id"],
        "run_id": "run-" + row["url_ingestion_operation_id"],
    }
    assert asyncio.run(tick(recovered.service))[0]["result"] == "resumed"
    assert_same_operation(recovered, row)
    recovered.port.start_ingest_workflow.assert_not_awaited()


def test_ambiguous_start_response_reconciles_without_second_execution(env):
    env.port.start_ingest_workflow.side_effect = TimeoutError()
    with pytest.raises(UrlIngestionError) as failed:
        create(env)
    assert failed.value.status == 503
    with env.engine.connect() as connection:
        row = dict(connection.execute(sa.select(generations)).mappings().one())
    recovered = restart(env, row)
    assert asyncio.run(tick(recovered.service))[0]["result"] == "resumed"
    assert_same_operation(recovered, row)
    assert asyncio.run(tick(recovered.service)) == []
    env.port.start_ingest_workflow.assert_awaited_once()
    recovered.port.start_ingest_workflow.assert_not_awaited()


def test_already_dispatched_row_is_not_redispatched_even_from_stale_selection(env):
    row = interrupted_start(env)
    asyncio.run(env.service.resume(row))
    env.control.start = AsyncMock(side_effect=AssertionError("redispatch"))
    asyncio.run(env.service.resume(row))
    assert asyncio.run(tick(env.service)) == []
    env.control.start.assert_not_awaited()


@pytest.mark.parametrize("mutation", ["delete", "recrawl"])
def test_stale_prepared_selection_cannot_dispatch_predecessor(env, mutation):
    old = interrupted_start(env)
    if mutation == "delete":
        asyncio.run(env.service.delete(env.principal, old["source_id"], "delete"))
    else:
        create(env, "recrawl")
    before = env.repository.source("default", old["source_id"])
    stored = env.repository.generation(old["url_ingestion_operation_id"])
    env.control.start = AsyncMock(side_effect=AssertionError("stale dispatch"))
    with pytest.raises(UrlIngestionError) as caught:
        asyncio.run(env.service.resume(old))
    assert caught.value.status == 409
    assert env.repository.source("default", old["source_id"]) == before
    assert env.repository.generation(old["url_ingestion_operation_id"]) == stored
    env.control.start.assert_not_awaited()


@pytest.mark.parametrize("invalid", ["retired", "closed", "generation", "deleted"])
def test_scheduler_excludes_prepared_start_without_current_dispatch_authority(
    env, invalid
):
    row = interrupted_start(env)
    with env.engine.begin() as connection:
        if invalid == "retired":
            connection.execute(generations.update().values(retired=True))
        elif invalid == "closed":
            state = dict(row["writer_state"], managed_writer_gate="closed")
            connection.execute(generations.update().values(writer_state=state))
        elif invalid == "generation":
            connection.execute(sources.update().values(generation=2))
        else:
            connection.execute(sources.update().values(status="deleted"))
    env.service.resume = AsyncMock(side_effect=AssertionError("invalid recovery"))
    assert asyncio.run(tick(env.service)) == []
    env.service.resume.assert_not_awaited()
