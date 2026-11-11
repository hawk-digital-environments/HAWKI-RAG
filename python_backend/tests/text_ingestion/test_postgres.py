"""Disposable PostgreSQL verifies actual claim and cleanup serialization."""

import asyncio
import os
from concurrent.futures import ThreadPoolExecutor
from threading import Event, Barrier
from types import SimpleNamespace
from pathlib import Path

import pytest
import sqlalchemy as sa
from alembic import command
from alembic.config import Config
from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.authorization.policy import LocalGrantPolicy
from hawki_backend.text_ingestion.repository import TextOperationRepository
from hawki_backend.text_ingestion.schema import (
    ingest_text_operations,
    ingest_text_events,
)
from hawki_backend.text_ingestion.service import (
    TextIngestionService,
    IdempotencyConflict,
    Busy,
    WorkflowUncertain,
)
from hawki_backend.text_ingestion.artifacts import TextArtifactStorage
from hawki_backend.text_ingestion.validation import TextIngestionRequest
from hawki_backend.workflow_control.repository import OperationRepository
from hawki_backend.workflow_control.schema import operations
from hawki_backend.workflow_control.service import WorkflowControl
from .conftest import payload
from .test_grants import grants, source
from .test_writer_fencing import writer_event
from hawki_backend.main import create_app
from .test_delete_snapshot import (
    test_delayed_delete_selects_b_after_a_was_deleted as check_collection_interleaving,
    test_stale_final_snapshot_never_removes_artifacts_or_marks_successor_deleted as check_stale_snapshot,
)
from hawki_backend.text_ingestion.writers import WriterEvent, WriterConflict


@pytest.fixture
def pg(env, monkeypatch):
    dsn = os.environ.get("HAWKI_EPOCH9_TEST_DSN")
    if not dsn:
        pytest.skip("Set HAWKI_EPOCH9_TEST_DSN to a disposable *_test database")
    if not sa.engine.make_url(dsn).database.endswith("_test"):
        pytest.fail("refusing non-test database")
    monkeypatch.setenv("HAWKI_BACKEND_DB_DSN", dsn)
    command.upgrade(
        Config(str(Path(__file__).resolve().parents[2] / "alembic.ini")), "head"
    )
    engine = sa.create_engine(dsn)
    with engine.begin() as conn:
        for table in (
            ingest_text_events,
            ingest_text_operations,
            operations,
            dataset_grants,
            datasets,
        ):
            conn.execute(table.delete())
        conn.execute(
            datasets.insert().values(
                tenant_id="default",
                dataset_id="dataset-a",
                status="active",
                name="Dataset A",
                qdrant_collection="hawki_dataset_a",
                neo4j_namespace="hawki_dataset_a",
                embedding_provider="ollama",
                embedding_model="bge-m3",
            )
        )
        conn.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="dataset-a",
                principal_type="user",
                principal_id="42",
                permission="ingest",
            )
        )
    repository = TextOperationRepository(engine)
    env.port.repository = repository
    control = WorkflowControl(OperationRepository(engine), env.port)
    service = TextIngestionService(
        repository=repository,
        artifacts=TextArtifactStorage(str(env.root)),
        vectors=env.vectors,
        settings=env.service._settings,
        policy=LocalGrantPolicy(DatabaseGrantReader(engine)),
        query_settings=env.service._query_settings,
        workflow_control=control,
    )
    yield SimpleNamespace(
        **{
            **vars(env),
            "engine": engine,
            "repository": repository,
            "service": service,
            "control": control,
            "app": create_app(
                authenticator=env.authenticator,
                text_ingestion_service=service,
                text_ingestion_secret="event-test-secret",
            ),
        }
    )
    with engine.begin() as conn:
        for table in (
            ingest_text_events,
            ingest_text_operations,
            operations,
            dataset_grants,
            datasets,
        ):
            conn.execute(table.delete())
    engine.dispose()


def ingest(pg, key="key-1", text=None):
    value = payload()
    if text is not None:
        value["text"] = text
    return asyncio.run(
        pg.service.ingest(pg.principal, TextIngestionRequest.model_validate(value), key)
    )


def finish(pg):
    row = pg.repository.find("default", "key-1")
    pg.repository.complete(
        event_id="done",
        payload_hash="a" * 64,
        workflow_id=row["workflow_id"],
        source_id=row["source_id"],
        status="ready",
        run_id="run-1",
    )


@pytest.mark.parametrize("conflicting", [False, True])
def test_concurrent_same_key_converges_on_one_preparation(pg, conflicting):
    entered, release = Event(), Event()
    original = pg.port.start_text_ingest_workflow.side_effect

    async def delayed(**kwargs):
        entered.set()
        if not release.wait(10):
            raise RuntimeError("test synchronization timeout")
        return await original(**kwargs)

    pg.port.start_text_ingest_workflow.side_effect = delayed
    with ThreadPoolExecutor(2) as pool:
        first = pool.submit(ingest, pg)
        assert entered.wait(10)
        try:
            with pytest.raises(
                IdempotencyConflict if conflicting else WorkflowUncertain
            ):
                ingest(pg, text="different" if conflicting else None)
        finally:
            release.set()
        result = first.result(10)
    assert ingest(pg).workflow_id == result.workflow_id
    pg.port.start_text_ingest_workflow.assert_awaited_once()
    with pg.engine.connect() as conn:
        for table in (ingest_text_operations, operations):
            assert (
                conn.execute(sa.select(sa.func.count()).select_from(table)).scalar()
                == 1
            )


def test_same_content_distinct_key_after_completion(pg):
    first = ingest(pg)
    finish(pg)
    second = ingest(pg, "key-2")
    assert first.workflow_id != second.workflow_id
    assert first.source_id == second.source_id


def test_delete_race_blocks_reingestion_and_second_cleanup(pg):
    first = ingest(pg)
    finish(pg)
    entered, release = Event(), Event()

    def cleanup(**kwargs):
        entered.set()
        if not release.wait(10):
            raise RuntimeError("test synchronization timeout")

    pg.vectors.delete.side_effect = cleanup
    with ThreadPoolExecutor(2) as pool:
        deleting = pool.submit(
            asyncio.run, pg.service.delete(pg.principal, first.source_id, "delete-1")
        )
        assert entered.wait(10)
        try:
            with pytest.raises(Busy):
                ingest(pg, "key-2")
            with pytest.raises(Busy):
                asyncio.run(
                    pg.service.delete(pg.principal, first.source_id, "delete-1")
                )
        finally:
            release.set()
        assert deleting.result(10).replayed is False
    assert ingest(pg, "key-2").source_id == first.source_id


def test_concurrent_different_keys_have_one_active_owner(pg):
    barrier = Barrier(2)

    def call(key):
        barrier.wait()
        try:
            return ingest(pg, key)
        except Busy:
            return "busy"

    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(call, ["key-1", "key-2"]))
    assert results.count("busy") == 1
    pg.port.start_text_ingest_workflow.assert_awaited_once()


def test_grant_postgres_locks_and_transaction_rollback(pg):
    statements = []
    sa.event.listen(
        pg.engine,
        "before_cursor_execute",
        lambda conn, cursor, statement, parameters, context, executemany: (
            statements.append(statement)
        ),
    )
    plan = grants.make_plan(source())
    with pg.engine.begin() as conn:
        conn.execute(dataset_grants.delete())
    with pytest.raises(RuntimeError), pg.engine.begin() as conn:
        grants.apply_plan(conn, plan, plan.digest)
        raise RuntimeError("abort")
    with pg.engine.connect() as conn:
        assert grants.compare_target(conn, plan)["status"] == "FAIL"
    assert any("pg_advisory_xact_lock" in query for query in statements)
    assert any("LOCK TABLE hawki_backend.datasets" in query for query in statements)


def test_grant_cli_modes_and_source_tenant_guard(pg, monkeypatch, capsys):
    meta = sa.MetaData(schema="public")
    tables = {
        "datasets": sa.Table(
            "datasets",
            meta,
            sa.Column("dataset_id", sa.Text),
            sa.Column("status", sa.Text),
            sa.Column("tenant_id", sa.Text),
        ),
        "users": sa.Table(
            "users",
            meta,
            sa.Column("id", sa.Integer),
            sa.Column("isRemoved", sa.Boolean),
        ),
        "dataset_grants": sa.Table(
            "dataset_grants",
            meta,
            sa.Column("dataset_id", sa.Text),
            sa.Column("principal_type", sa.Text),
            sa.Column("principal_id", sa.Text),
            sa.Column("permission", sa.Text),
        ),
    }
    meta.create_all(pg.engine)
    try:
        with pg.engine.begin() as conn:
            for name, table in tables.items():
                values = source()[name]
                if name == "datasets":
                    values = [{**row, "tenant_id": "default"} for row in values]
                conn.execute(table.insert(), values)
            conn.execute(dataset_grants.delete())
        monkeypatch.setenv(
            "HAWKI_INGEST_MIGRATION_SOURCE_DSN", os.environ["HAWKI_EPOCH9_TEST_DSN"]
        )
        assert grants.main(["--dry-run"]) == 1
        output = __import__("json").loads(capsys.readouterr().out)
        for mode in ["--apply", "--apply", "--verify"]:
            args = [mode] + (
                ["--confirm-plan", output["plan_sha256"]] if mode == "--apply" else []
            )
            assert grants.main(args) == 0
            capsys.readouterr()
        with pg.engine.begin() as conn:
            conn.execute(tables["datasets"].update().values(tenant_id="other"))
        assert grants.main(["--dry-run"]) == 1
        assert "ambiguous tenant" in capsys.readouterr().out
    finally:
        meta.drop_all(pg.engine)


@pytest.mark.parametrize("conflicting", [False, True])
def test_simultaneous_fresh_claims_are_serialized_by_postgres(
    pg, monkeypatch, conflicting
):
    barrier = Barrier(2)
    original = pg.repository.claim

    def synchronized(values, store_artifact=None):
        barrier.wait(timeout=10)
        return original(values, store_artifact)

    monkeypatch.setattr(pg.repository, "claim", synchronized)

    def call(text):
        try:
            return ingest(pg, text=text)
        except (IdempotencyConflict, WorkflowUncertain) as error:
            return error

    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(call, ["first", "second" if conflicting else "first"]))
    assert sum(isinstance(result, IdempotencyConflict) for result in results) == int(
        conflicting
    )
    assert sum(not isinstance(result, Exception) for result in results) >= 1
    with pg.engine.connect() as conn:
        assert (
            conn.execute(
                sa.select(sa.func.count()).select_from(ingest_text_operations)
            ).scalar()
            == 1
        )
    pg.port.start_text_ingest_workflow.assert_awaited_once()


def test_postgres_writer_admission_and_delete_share_source_lock(pg):
    first = ingest(pg)
    row = pg.repository.find("default", "key-1")
    event = WriterEvent.model_validate(writer_event(row))
    pg.repository.record_writer(event)
    from hawki_backend.text_ingestion.service import DeletionFailed

    with pytest.raises(DeletionFailed):
        asyncio.run(pg.service.delete(pg.principal, first.source_id, "delete-1"))
    with pytest.raises(WriterConflict):
        pg.repository.record_writer(event.model_copy(update={"attempt": 2}))
    pg.vectors.delete.assert_not_called()
    pg.repository.record_writer(event.model_copy(update={"action": "release"}))
    assert (
        asyncio.run(
            pg.service.delete(pg.principal, first.source_id, "delete-1")
        ).source_id
        == first.source_id
    )
    assert ingest(pg, "key-2").source_id == first.source_id


def test_simultaneous_writer_acquire_vs_delete_cannot_escape_fence(pg):
    from hawki_backend.text_ingestion.service import DeletionFailed

    first = ingest(pg)
    row = pg.repository.find("default", "key-1")
    barrier = Barrier(2)
    event = WriterEvent.model_validate(writer_event(row))

    def acquire():
        barrier.wait(timeout=10)
        try:
            pg.repository.record_writer(event)
            return True
        except WriterConflict:
            return False

    def cleanup():
        barrier.wait(timeout=10)
        try:
            asyncio.run(pg.service.delete(pg.principal, first.source_id, "delete-1"))
            return True
        except DeletionFailed:
            return False

    with ThreadPoolExecutor(2) as pool:
        writer_future, delete_future = pool.submit(acquire), pool.submit(cleanup)
        admitted, deleted = writer_future.result(10), delete_future.result(10)
    assert admitted != deleted
    if admitted:
        pg.vectors.delete.assert_not_called()
        pg.repository.record_writer(event.model_copy(update={"action": "release"}))
        assert asyncio.run(pg.service.delete(pg.principal, first.source_id, "delete-1"))
    with pytest.raises(WriterConflict):
        pg.repository.record_writer(event.model_copy(update={"attempt": 3}))


def test_postgres_collection_interleaving_uses_one_locked_snapshot(pg, monkeypatch):
    check_collection_interleaving(pg, monkeypatch)


@pytest.mark.parametrize("change", ["collection", "path", "fence", "workflow"])
def test_postgres_stale_final_transition_fails_closed(pg, change):
    check_stale_snapshot(pg, change)
