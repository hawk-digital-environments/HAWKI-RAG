"""Run against a disposable PostgreSQL database; SQLite proves no locking claim."""

import asyncio
import os
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from threading import Barrier, Event

import pytest
import sqlalchemy as sa
from alembic import command
from alembic.config import Config

from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.schema import TABLES, sources, generations
from hawki_backend.workflow_control.schema import operations

from .conftest import configure, seed, create, MANAGED_TABLES
from hawki_backend.text_ingestion.schema import ingest_text_operations
from .test_lifecycle import generation, ready, writer, event


@pytest.fixture
def pg(tmp_path, monkeypatch):
    dsn = os.environ.get("HAWKI_EPOCH11_TEST_DSN")
    if not dsn:
        pytest.skip(
            "Set HAWKI_EPOCH11_TEST_DSN to a disposable *_test PostgreSQL database"
        )
    if not sa.engine.make_url(dsn).database.endswith("_test"):
        pytest.fail("Refusing a non-test database")
    monkeypatch.setenv("HAWKI_BACKEND_DB_DSN", dsn)
    command.upgrade(
        Config(str(Path(__file__).resolve().parents[2] / "alembic.ini")), "head"
    )
    engine = sa.create_engine(dsn)

    def clear():
        with engine.begin() as connection:
            for table in (
                *reversed(TABLES),
                *reversed(MANAGED_TABLES),
                ingest_text_operations,
                operations,
                dataset_grants,
                datasets,
            ):
                connection.execute(table.delete())

    clear()
    seed(engine)
    yield configure(engine, tmp_path)
    clear()
    engine.dispose()


@pytest.mark.parametrize("changed", [False, True])
def test_same_idempotency_key_race(pg, changed):
    barrier = Barrier(2)

    def call(index):
        barrier.wait(timeout=10)
        try:
            create(pg, metadata={"item": index if changed else 0})
            return 201
        except UrlIngestionError as error:
            return error.status

    with ThreadPoolExecutor(2) as pool:
        statuses = list(pool.map(call, [0, 1]))
    assert 201 in statuses
    if changed:
        assert 409 in statuses
    with pg.engine.connect() as connection:
        for table in (sources, generations, operations):
            assert connection.scalar(sa.select(sa.func.count()).select_from(table)) == 1
    pg.port.start_ingest_workflow.assert_awaited_once()


def test_concurrent_recrawl_has_one_successor(pg):
    old = generation(pg, create(pg))
    ready(pg, old)
    entered = Event()
    release = Event()

    def cleanup(*args):
        entered.set()
        assert release.wait(10)

    pg.service.cleanup.delete.side_effect = cleanup
    with ThreadPoolExecutor(2) as pool:
        first = pool.submit(create, pg, "recrawl-a")
        assert entered.wait(10)
        second = pool.submit(create, pg, "recrawl-b")
        release.set()
        assert generation(pg, first.result(timeout=15))["generation"] == 2
        with pytest.raises(UrlIngestionError):
            second.result(timeout=15)
    with pg.engine.connect() as connection:
        assert (
            connection.scalar(sa.select(sa.func.count()).select_from(generations)) == 2
        )


def test_delete_recrawl_race_retains_one_mutation_owner(pg):
    old = generation(pg, create(pg))
    writer(pg, old)
    with pytest.raises(UrlIngestionError) as caught:
        asyncio.run(pg.service.delete(pg.principal, old["source_id"], "delete"))
    assert caught.value.status == 202
    with pytest.raises(UrlIngestionError):
        create(pg, "recrawl")
    writer(pg, old, "release")
    asyncio.run(pg.service.delete(pg.principal, old["source_id"], "delete"))
    assert pg.repository.source("default", old["source_id"])["status"] == "deleted"


def test_callback_generation_and_event_id_race(pg):
    old = generation(pg, create(pg))
    ready(pg, old)
    create(pg, "recrawl")
    barrier = Barrier(2)

    def complete(payload_hash):
        barrier.wait(timeout=10)
        try:
            return pg.repository.complete(event(old, "racing"), payload_hash, "ready")
        except UrlIngestionError as error:
            return error.status

    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(complete, ["a", "b"]))
    assert 409 in results
    receipt = next(value for value in results if isinstance(value, dict))
    assert receipt["ignored"] is True
    assert pg.repository.source("default", old["source_id"])["current_generation"] == 1


@pytest.mark.parametrize("revoke", ["archive", "grant"])
def test_dataset_revocation_commits_before_claim_is_rechecked(pg, revoke):
    original = pg.service.policy

    class RevokeAfterPolicy:
        def decide(self, value):
            result = original.decide(value)
            with pg.engine.begin() as connection:
                connection.execute(
                    datasets.update().values(status="archived")
                    if revoke == "archive"
                    else dataset_grants.delete()
                )
            return result

    pg.service.policy = RevokeAfterPolicy()
    with pytest.raises(UrlIngestionError) as caught:
        create(pg)
    assert caught.value.status == 404
    with pg.engine.connect() as connection:
        assert (
            connection.scalar(sa.select(sa.func.count()).select_from(generations)) == 0
        )


def test_postgres_rejects_cross_source_current_generation(pg):
    first = generation(pg, create(pg))
    ready(pg, first)
    second = generation(pg, create(pg, "other", source_url="https://example.org/other"))
    with pytest.raises(sa.exc.IntegrityError):
        with pg.engine.begin() as connection:
            connection.execute(
                sources.update()
                .where(sources.c.source_id == second["source_id"])
                .values(current_generation=999)
            )


def test_delete_observed_generation_cannot_delete_concurrent_successor(pg):
    old = generation(pg, create(pg))
    ready(pg, old)
    observed = Event()
    proceed = Event()
    original_claim = pg.repository.claim

    def delayed_claim(principal, values, *args, **kwargs):
        if values["purpose"] == "delete":
            observed.set()
            assert proceed.wait(10)
        return original_claim(principal, values, *args, **kwargs)

    pg.repository.claim = delayed_claim
    with ThreadPoolExecutor(2) as pool:
        deletion = pool.submit(
            lambda: asyncio.run(
                pg.service.delete(pg.principal, old["source_id"], "delete-old")
            )
        )
        assert observed.wait(10)
        successor = generation(pg, create(pg, "newer-request"))
        proceed.set()
        with pytest.raises(UrlIngestionError) as caught:
            deletion.result(timeout=15)
        assert caught.value.status == 409
    assert pg.repository.source("default", old["source_id"])["generation"] == 2
    assert (
        pg.repository.generation(successor["url_ingestion_operation_id"])[
            "writer_state"
        ]["managed_writer_gate"]
        == "open"
    )


def test_schedule_and_manual_recrawl_claim_only_one_successor(pg):
    from .test_schedules import make_due
    from hawki_backend.url_ingestion.scheduler import tick

    old = generation(pg, create(pg, refresh_cadence="daily"))
    ready(pg, old)
    make_due(pg, old)
    barrier = Barrier(2)
    original = pg.service.start

    async def synchronized(*args, **kwargs):
        barrier.wait(timeout=10)
        return await original(*args, **kwargs)

    pg.service.start = synchronized

    def manual():
        try:
            return asyncio.run(
                pg.service.recrawl(pg.principal, old["source_id"], {}, "manual")
            )[0]
        except UrlIngestionError as error:
            return error.status

    with ThreadPoolExecutor(2) as pool:
        a = pool.submit(manual)
        b = pool.submit(lambda: asyncio.run(tick(pg.service)))
        manual_result = a.result(timeout=20)
        scheduled_result = b.result(timeout=20)
    assert manual_result in {201, 202, 409}
    assert scheduled_result[0]["result"] in {"claimed", "skipped", "pending"}
    assert pg.repository.source("default", old["source_id"])["generation"] == 2
    with pg.engine.connect() as connection:
        assert (
            connection.scalar(sa.select(sa.func.count()).select_from(generations)) == 2
        )
    assert pg.port.start_ingest_workflow.await_count == 2


def test_overlapping_schedule_ticks_share_one_claim(pg):
    from .test_schedules import make_due
    from hawki_backend.url_ingestion.scheduler import tick

    old = generation(pg, create(pg, refresh_cadence="weekly"))
    ready(pg, old)
    make_due(pg, old)
    barrier = Barrier(2)
    original = pg.service.recrawl

    async def synchronized(*args, **kwargs):
        barrier.wait(timeout=10)
        return await original(*args, **kwargs)

    pg.service.recrawl = synchronized
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: asyncio.run(tick(pg.service)), [0, 1]))
    assert any(result[0]["result"] == "claimed" for result in results)
    assert pg.repository.source("default", old["source_id"])["generation"] == 2
    assert pg.port.start_ingest_workflow.await_count == 2


def test_postgres_recovery_owner_conflict_fails_closed(pg):
    from hawki_backend.url_ingestion.ownership import classify
    from hawki_backend.url_ingestion.identity import digest

    row = generation(pg, create(pg))
    with pg.engine.begin() as connection:
        connection.execute(
            operations.insert().values(
                operation_id=digest("conflicting"),
                workflow_id="legacy-workflow",
                action="source",
                input_digest=digest("legacy"),
                payload={"workflow_input": {"job_id": row["job_id"]}},
                status="confirmed",
            )
        )
    with pytest.raises(UrlIngestionError) as caught:
        classify(pg.repository, {"job_id": row["job_id"]})
    assert caught.value.status == 409


def test_managed_recovery_observed_revision_is_rechecked_under_postgres_lock(pg):
    from io import BytesIO

    from hawki_backend.managed_documents.input import ManagedInput, ManagedError
    from hawki_backend.managed_documents.schema import revisions
    from hawki_backend.url_ingestion.recovery import recover_managed
    from .test_managed_recovery import failed_managed

    managed, old = failed_managed(pg)
    entered = Event()
    release = Event()
    original = managed.repository.claim

    def delayed(*args, **kwargs):
        if kwargs.get("expected_revision_id"):
            entered.set()
            assert release.wait(10)
        return original(*args, **kwargs)

    managed.repository.claim = delayed
    with ThreadPoolExecutor(2) as pool:
        recovery = pool.submit(
            lambda: asyncio.run(
                recover_managed(
                    managed.service, managed.principal, old["job_id"], "retry"
                )
            )
        )
        assert entered.wait(10)
        asyncio.run(
            managed.service.mutate(
                managed.principal,
                "update",
                ManagedInput(force=True),
                "a.txt",
                BytesIO(b"new"),
                "manual",
                old["managed_document_id"],
            )
        )
        release.set()
        with pytest.raises(ManagedError) as caught:
            recovery.result(timeout=15)
        assert caught.value.status == 409
    with pg.engine.connect() as connection:
        assert connection.scalar(sa.select(sa.func.count()).select_from(revisions)) == 2


def test_terminal_failure_and_writer_admission_are_serialized(pg):
    from .test_retries import outcome

    row = generation(pg, create(pg))
    barrier = Barrier(2)

    def admit():
        barrier.wait(timeout=10)
        try:
            return writer(pg, row)["status"]
        except UrlIngestionError as error:
            return error.status

    def finalize():
        barrier.wait(timeout=10)
        return pg.repository.fail_workflow(row, outcome(row, "FAILED"))

    with ThreadPoolExecutor(2) as pool:
        acquired = pool.submit(admit)
        finalized = pool.submit(finalize)
        admitted = acquired.result(timeout=15)
        assert finalized.result(timeout=15) is True
    assert admitted in {"active", 409}
    stored = pg.repository.generation(row["url_ingestion_operation_id"])
    assert stored["writer_state"]["managed_writer_gate"] == "closed"
    assert (
        pg.repository.source("default", row["source_id"])["pending_operation_id"]
        is None
    )
    if admitted == "active":
        assert all(
            w["status"] == "active"
            for w in stored["writer_state"]["managed_writers"].values()
        )
        writer(pg, row, "release")


def test_postgres_successful_scraper_retry_retains_writer_and_publication_authority(pg):
    from .test_retries import (
        test_retryable_scrape_failure_then_success_admits_both_writers_and_publishes,
    )

    test_retryable_scrape_failure_then_success_admits_both_writers_and_publishes(pg)


def test_concurrent_terminal_reconciliation_finalizes_once(pg):
    from .test_retries import outcome

    row = generation(pg, create(pg))
    barrier = Barrier(2)

    def finalize(_):
        barrier.wait(timeout=10)
        return pg.repository.fail_workflow(row, outcome(row, "FAILED"))

    with ThreadPoolExecutor(2) as pool:
        assert sorted(pool.map(finalize, [0, 1])) == [False, True]


def test_delayed_terminal_observation_cannot_fail_concurrent_successor(pg):
    from .test_retries import outcome

    old = generation(pg, create(pg))
    observed = Event()
    proceed = Event()

    async def status(**kwargs):
        observed.set()
        assert proceed.wait(10)
        return outcome(old, "FAILED")

    pg.port.workflow_status.side_effect = status
    with ThreadPoolExecutor(2) as pool:
        reconciliation = pool.submit(
            lambda: asyncio.run(pg.service.reconcile_workflow(old))
        )
        assert observed.wait(10)
        successor = generation(pg, create(pg, "recrawl"))
        proceed.set()
        assert reconciliation.result(timeout=15) is False
    source = pg.repository.source("default", old["source_id"])
    assert source["pending_operation_id"] == successor["url_ingestion_operation_id"]
    assert writer(pg, successor)["status"] == "active"


@pytest.mark.parametrize(
    "boundary", ["before_start", "after_claim", "control_receipt", "url_receipt"]
)
def test_overlapping_scheduler_dispatch_recovery_uses_one_logical_start(pg, boundary):
    from .test_dispatch_recovery import (
        interrupted_start,
        restart,
        assert_same_operation,
    )
    from hawki_backend.url_ingestion.scheduler import tick

    row = interrupted_start(pg, boundary)
    recovered = (
        configure(pg.engine, pg.root) if boundary == "after_claim" else restart(pg, row)
    )
    barrier = Barrier(2)
    original = recovered.service.resume

    async def selected(operation):
        barrier.wait(timeout=10)
        await original(operation)

    recovered.service.resume = selected
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: asyncio.run(tick(recovered.service)), [0, 1]))
    assert any(result[0]["result"] == "resumed" for result in results)
    assert_same_operation(recovered, row)
    assert recovered.port.start_ingest_workflow.await_count == (
        1 if boundary in {"before_start", "after_claim"} else 0
    )
    assert pg.port.start_ingest_workflow.await_count == (
        0 if boundary in {"before_start", "after_claim"} else 1
    )
    recovered.service.resume = original
    assert asyncio.run(tick(recovered.service)) == []


@pytest.mark.parametrize("boundary", ["url_receipt", "after_claim"])
def test_request_records_dispatch_after_scheduler_selection(pg, boundary):
    from .test_dispatch_recovery import interrupted_start, assert_same_operation
    from hawki_backend.url_ingestion.scheduler import tick

    row = interrupted_start(pg, boundary)
    selected = Event()
    proceed = Event()
    original = pg.service.resume

    async def delayed(operation):
        selected.set()
        assert proceed.wait(10)
        await original(operation)

    pg.service.resume = delayed
    with ThreadPoolExecutor(2) as pool:
        recovery = pool.submit(lambda: asyncio.run(tick(pg.service)))
        assert selected.wait(10)
        asyncio.run(original(row))
        proceed.set()
        assert recovery.result(timeout=15)[0]["result"] == "resumed"
    assert_same_operation(pg, row)
    pg.port.start_ingest_workflow.assert_awaited_once()
    assert pg.port.reconcile_start.await_count == (
        1 if boundary == "after_claim" else 0
    )


@pytest.mark.parametrize("mutation", ["delete", "recrawl"])
@pytest.mark.parametrize("boundary", ["url_receipt", "after_claim"])
def test_mutation_wins_after_scheduler_selects_prepared_predecessor(
    pg, mutation, boundary
):
    from .test_dispatch_recovery import interrupted_start
    from hawki_backend.url_ingestion.scheduler import tick

    old = interrupted_start(pg, boundary)
    selected = Event()
    proceed = Event()
    original = pg.service.resume

    async def delayed(operation):
        if operation["url_ingestion_operation_id"] == old["url_ingestion_operation_id"]:
            selected.set()
            assert proceed.wait(10)
        await original(operation)

    pg.service.resume = delayed
    with ThreadPoolExecutor(2) as pool:
        recovery = pool.submit(lambda: asyncio.run(tick(pg.service)))
        assert selected.wait(10)
        if mutation == "delete":
            asyncio.run(pg.service.delete(pg.principal, old["source_id"], "delete"))
        else:
            create(pg, "recrawl")
        source = pg.repository.source("default", old["source_id"])
        predecessor = pg.repository.generation(old["url_ingestion_operation_id"])
        proceed.set()
        assert recovery.result(timeout=15)[0]["result"] == "pending"
    assert pg.repository.source("default", old["source_id"]) == source
    assert pg.repository.generation(old["url_ingestion_operation_id"]) == predecessor
    assert pg.port.start_ingest_workflow.await_count == (
        1 if mutation == "delete" else 2
    )


@pytest.mark.parametrize("mutation", ["delete", "recrawl"])
@pytest.mark.parametrize("boundary", ["before_start", "after_claim"])
def test_dispatch_recovery_holds_source_lock_until_receipt_is_persisted(
    pg, mutation, boundary
):
    from .test_dispatch_recovery import interrupted_start
    from hawki_backend.url_ingestion.scheduler import tick

    old = interrupted_start(pg, boundary)
    dispatching = Event()
    proceed = Event()
    claiming = Event()
    original_start = pg.port.start_ingest_workflow.side_effect
    original_claim = pg.repository.claim

    async def delayed_start(**kwargs):
        if kwargs["workflow_id"] == old["workflow_id"]:
            dispatching.set()
            assert proceed.wait(10)
        return await original_start(**kwargs)

    def observed_claim(*args, **kwargs):
        claiming.set()
        return original_claim(*args, **kwargs)

    pg.port.start_ingest_workflow.side_effect = delayed_start
    pg.repository.claim = observed_claim

    def mutate():
        if mutation == "delete":
            return asyncio.run(
                pg.service.delete(pg.principal, old["source_id"], "delete")
            )
        return create(pg, "recrawl")

    with ThreadPoolExecutor(2) as pool:
        recovery = pool.submit(lambda: asyncio.run(tick(pg.service)))
        assert dispatching.wait(10)
        successor = pool.submit(mutate)
        assert claiming.wait(10)
        proceed.set()
        recovery.result(timeout=15)
        successor.result(timeout=15)
    source = pg.repository.source("default", old["source_id"])
    assert source["generation"] == 2
    assert source["status"] == ("deleted" if mutation == "delete" else "running")
    predecessor = pg.repository.generation(old["url_ingestion_operation_id"])
    assert predecessor["retired"] is True
    assert predecessor["run_id"] == "run-" + old["url_ingestion_operation_id"]
    assert pg.port.start_ingest_workflow.await_count == (
        1 if mutation == "delete" else 2
    )
