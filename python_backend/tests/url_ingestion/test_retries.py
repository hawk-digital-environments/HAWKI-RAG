"""Attempt callbacks preserve ownership; exact Temporal outcomes retire it."""

import asyncio

import pytest
import sqlalchemy as sa

from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.scheduler import tick
from hawki_backend.url_ingestion.schema import events

from .conftest import create, request
from .test_callbacks import signed
from .test_lifecycle import event, generation, ready, writer
from .test_quiescence import receipt as crawler_receipt


def attempt(env, row, status, number=1):
    value = event(
        row,
        f"scrape-{number}-{status}",
        activity_id="scrape_source",
        producer="scraper",
        stage="scrape",
        phase="scrape_source",
        status=status,
        attempt=number,
        metrics={"crawler_receipt": crawler_receipt(row)}
        if status == "completed"
        else {},
        errors=[{"code": "transport", "message": "Response lost", "retryable": True}]
        if status == "failed"
        else [],
    )
    response = signed(env, "events", value.model_dump(mode="json", by_alias=True))
    assert response.status_code == 202
    return response.json()


def outcome(row, status, **extra):
    return {
        "workflow_id": row["workflow_id"],
        "run_id": row["run_id"],
        "status": status,
        **extra,
    }


def set_outcome(env, body):
    env.port.workflow_status.side_effect = None
    env.port.workflow_status.return_value = body


def test_retryable_scrape_failure_then_success_admits_both_writers_and_publishes(env):
    row = generation(env, create(env))
    assert attempt(env, row, "failed")["ignored"] is False
    source = env.repository.source("default", row["source_id"])
    operation = env.repository.generation(row["url_ingestion_operation_id"])
    assert source["pending_operation_id"] == row["url_ingestion_operation_id"]
    assert source["status"] == "running"
    assert operation["stage"] == "dispatched"
    assert operation["completion"] is None
    assert operation["writer_state"]["managed_writer_gate"] == "open"
    assert (
        request(env, "/v1/pipeline/recovery/failed-jobs", method="GET").json()["jobs"]
        == []
    )
    with env.engine.connect() as connection:
        receipt = connection.execute(sa.select(events.c.receipt)).scalar_one()
    assert receipt["status"] == "failed" and receipt["attempt"] == 1
    assert receipt["errors"][0]["retryable"] is True
    assert asyncio.run(tick(env.service)) == []
    assert attempt(env, row, "completed", 2)["ignored"] is False
    assert (
        writer(
            env,
            row,
            activity_id="inspect_and_convert_files",
            event_id="converter-acquire",
        )["status"]
        == "active"
    )
    writer(
        env,
        row,
        "release",
        activity_id="inspect_and_convert_files",
        event_id="converter-release",
    )
    assert writer(env, row)["status"] == "active"
    ready(env, row)
    source = env.repository.source("default", row["source_id"])
    assert source["status"] == "ready"
    assert source["pending_operation_id"] is None
    assert source["current_generation"] == row["generation"]
    assert (
        env.repository.generation(row["url_ingestion_operation_id"])["stage"]
        == "completed"
    )
    env.port.start_ingest_workflow.assert_awaited_once()


@pytest.mark.parametrize(
    "status,result",
    [
        ("FAILED", None),
        ("TIMED_OUT", None),
        ("CANCELED", None),
        ("TERMINATED", None),
        ("COMPLETED", {"status": "failed"}),
        ("COMPLETED", {"status": "skipped"}),
    ],
)
def test_terminal_workflow_failure_finalizes_once_and_late_callbacks_cannot_revive(
    env, status, result
):
    row = generation(env, create(env))
    attempt(env, row, "failed")
    writer(env, row)
    set_outcome(env, outcome(row, status, result=result))
    assert asyncio.run(tick(env.service)) == [
        {"source_id": row["source_id"], "result": "failed"}
    ]
    stored = env.repository.generation(row["url_ingestion_operation_id"])
    assert stored["stage"] == "completed" and stored["completion"]["status"] == "failed"
    assert stored["completion"]["workflow_status"] == status
    assert stored["writer_state"]["managed_writer_gate"] == "closed"
    assert all(
        w["status"] == "active"
        for w in stored["writer_state"]["managed_writers"].values()
    )
    source = env.repository.source("default", row["source_id"])
    assert source["status"] == "failed" and source["pending_operation_id"] is None
    assert asyncio.run(env.service.reconcile_workflow(row)) is False
    assert (
        env.repository.generation(row["url_ingestion_operation_id"])["completion"]
        == stored["completion"]
    )
    assert attempt(env, row, "completed", 2)["ignored"] is True
    with pytest.raises(UrlIngestionError):
        writer(env, row, activity_id="new-activity", event_id="late-acquire")
    # Closing admission never manufactures drainage for an admitted writer.
    with pytest.raises(UrlIngestionError) as caught:
        asyncio.run(env.service.delete(env.principal, row["source_id"], "delete"))
    assert caught.value.status == 202
    env.service.cleanup.delete.assert_not_called()
    writer(env, row, "release")
    asyncio.run(env.service.delete(env.principal, row["source_id"], "delete"))


@pytest.mark.parametrize(
    "status,result",
    [
        ("RUNNING", None),
        ("UNKNOWN", None),
        ("CONTINUED_AS_NEW", None),
        ("COMPLETED", None),
        ("COMPLETED", {"status": "ready"}),
    ],
)
def test_nonfailure_or_unverified_outcome_preserves_pending_operation(
    env, status, result
):
    row = generation(env, create(env))
    attempt(env, row, "failed")
    set_outcome(env, outcome(row, status, result=result))
    assert asyncio.run(env.service.reconcile_workflow(row)) is False
    assert (
        env.repository.source("default", row["source_id"])["pending_operation_id"]
        == row["url_ingestion_operation_id"]
    )


@pytest.mark.parametrize("mode", ["unavailable", "wrong_run", "wrong_workflow"])
def test_uncertain_or_foreign_temporal_outcome_never_finalizes(env, mode):
    row = generation(env, create(env))
    set_outcome(env, outcome(row, "FAILED"))
    if mode == "unavailable":
        env.port.workflow_status.side_effect = TimeoutError()
    else:
        env.port.workflow_status.return_value[
            "run_id" if mode == "wrong_run" else "workflow_id"
        ] = "foreign"
    assert asyncio.run(tick(env.service)) == [
        {"source_id": row["source_id"], "result": "pending"}
    ]
    assert (
        env.repository.source("default", row["source_id"])["pending_operation_id"]
        == row["url_ingestion_operation_id"]
    )


def test_predecessor_failure_evidence_cannot_finalize_successor(env):
    old = generation(env, create(env))
    new = generation(env, create(env, "recrawl"))
    set_outcome(env, outcome(old, "FAILED"))
    assert asyncio.run(env.service.reconcile_workflow(old)) is False
    assert attempt(env, old, "failed")["ignored"] is True
    assert (
        env.repository.source("default", new["source_id"])["pending_operation_id"]
        == new["url_ingestion_operation_id"]
    )
    assert writer(env, new)["status"] == "active"


def test_terminal_failed_manual_source_can_be_recovered(env):
    row = generation(env, create(env))
    set_outcome(env, outcome(row, "FAILED"))
    asyncio.run(tick(env.service))
    response = request(env, f"/v1/pipeline/recovery/jobs/{row['job_id']}/retry")
    assert response.status_code == 200
    assert response.json()["recovery"]["retried"] == 1
    assert env.repository.source("default", row["source_id"])["generation"] == 2


def test_nonretryable_attempt_still_requires_authoritative_workflow_termination(env):
    row = generation(env, create(env))
    value = event(
        row,
        "nonretryable-attempt",
        producer="scraper",
        activity_id="scrape_source",
        stage="scrape",
        status="failed",
        errors=[{"code": "invalid", "message": "Invalid crawl", "retryable": False}],
    )
    response = signed(env, "events", value.model_dump(mode="json", by_alias=True))
    assert response.status_code == 202 and response.json()["ignored"] is False
    assert asyncio.run(tick(env.service)) == []
    assert (
        env.repository.source("default", row["source_id"])["pending_operation_id"]
        == row["url_ingestion_operation_id"]
    )
    set_outcome(env, outcome(row, "FAILED"))
    assert asyncio.run(tick(env.service))[0]["result"] == "failed"
    assert (
        env.repository.source("default", row["source_id"])["pending_operation_id"]
        is None
    )
