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
