"""Disposable PostgreSQL proves admission serialization; SQLite makes no lock claim."""

import os
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from threading import Event

import pytest
import sqlalchemy as sa
from alembic import command
from alembic.config import Config
from fastapi import HTTPException

from hawki_backend.ops.admission import prepare_clear, finish_clear
from hawki_backend.persistence.datasets import lock_active_dataset, DatasetInactiveError
from hawki_backend.persistence.schema import metadata
from hawki_backend.text_ingestion.schema import ingest_text_operations
from hawki_backend.workflow_control.repository import (
    OperationRepository,
    OperationConflict,
)
from hawki_backend.workflow_control.schema import operations
from .conftest import configure


@pytest.fixture
def pg(monkeypatch):
    dsn = os.environ.get("RAG_EPOCH14_TEST_DSN")
    if not dsn:
        pytest.skip(
            "Set RAG_EPOCH14_TEST_DSN to a disposable *_test PostgreSQL database"
        )
    if not sa.engine.make_url(dsn).database.endswith("_test"):
        pytest.fail("Refusing a non-test database")
    monkeypatch.setenv("RAG_BACKEND_DB_DSN", dsn)
    command.upgrade(
        Config(str(Path(__file__).resolve().parents[2] / "alembic.ini")), "head"
    )
    engine = sa.create_engine(dsn)
    with engine.begin() as connection:
        names = ", ".join(
            '"hawki_backend"."' + table.name + '"' for table in metadata.tables.values()
        )
        connection.execute(sa.text("TRUNCATE TABLE " + names + " CASCADE"))
    yield configure(engine)
    engine.dispose()


def seed_text(connection, *, status="started", writer_status="active"):
    connection.execute(
        ingest_text_operations.insert().values(
            tenant_id="a",
            dataset_id="one",
            idempotency_key="key",
            principal_type="user",
            principal_id="reader",
            external_document_id="external",
            source_id="source",
            fingerprint="hash",
            content_hash="hash",
            markdown_path="/disposable/document.md",
            task_id="task",
            job_id="job",
            workflow_id="workflow",
            lifecycle_status=status,
            request_metadata={
                "managed_writer_protocol": 1,
                "managed_writer_registered": True,
                "managed_writer_gate": "open",
                "workflow_input": {"text_writer_protocol": 1},
                "managed_writers": {"writer": {"status": writer_status}},
            },
        )
    )


def wait_for_lock(engine):
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        with engine.connect() as connection:
            if connection.execute(
                sa.text(
                    "SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock'"
                )
            ).scalar_one():
                return
        time.sleep(0.01)
    raise AssertionError("concurrent admission did not wait on the dataset fence")


def test_clear_waits_for_committing_writer_and_refuses(pg):
    locked, release = Event(), Event()

    def writer():
        with pg.engine.begin() as connection:
            lock_active_dataset(connection, "a", "one")
            seed_text(connection)
            locked.set()
            assert release.wait(5)

    with ThreadPoolExecutor(2) as pool:
        writing = pool.submit(writer)
        assert locked.wait(5)
        clearing = pool.submit(prepare_clear, pg.engine, "a", "one")
        try:
            wait_for_lock(pg.engine)
        finally:
            release.set()
        writing.result(5)
        with pytest.raises(HTTPException) as error:
            clearing.result(5)
        assert error.value.status_code == 409
    with pg.engine.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(operations)
            ).scalar_one()
            == 0
        )


@pytest.mark.parametrize("writer_status", ["active", "uncertain"])
def test_terminal_status_does_not_hide_writer(pg, writer_status):
    with pg.engine.begin() as connection:
        seed_text(connection, status="failed", writer_status=writer_status)
    with pytest.raises(HTTPException) as error:
        prepare_clear(pg.engine, "a", "one")
    assert error.value.status_code == 409


def test_released_terminal_writer_allows_clear(pg):
    with pg.engine.begin() as connection:
        seed_text(connection, status="ready", writer_status="released")
    operation, namespace = prepare_clear(pg.engine, "a", "one")
    assert namespace == "physical-graph-one"
    finish_clear(pg.engine, operation, {"deleted_nodes": 0, "deleted_relationships": 0})
    with pg.engine.begin() as connection:
        assert lock_active_dataset(connection, "a", "one").dataset_id == "one"


def test_lost_acknowledgement_keeps_admission_closed_after_restart(pg):
    operation, _ = prepare_clear(pg.engine, "a", "one")
    finish_clear(pg.engine, operation, None)
    another_engine = sa.create_engine(pg.engine.url)
    try:
        with another_engine.begin() as connection:
            with pytest.raises(DatasetInactiveError):
                lock_active_dataset(connection, "a", "one")
            assert lock_active_dataset(connection, "a", "two").dataset_id == "two"
    finally:
        another_engine.dispose()


def test_concurrent_clear_has_one_durable_owner(pg):
    def prepare():
        try:
            return prepare_clear(pg.engine, "a", "one")[0]
        except HTTPException as error:
            return error.status_code

    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: prepare(), range(2)))
    assert sum(isinstance(value, str) for value in results) == 1
    assert 409 in results


def test_unregistered_compatibility_dispatch_cannot_enter_python_graph(pg, monkeypatch):
    monkeypatch.setenv("RAG_BACKEND_QUERY_DATASET_SOURCE", "database")
    payload = {
        "workflow_input": {
            "dataset_id": "one",
            "ingestion": {"graph": True, "neo4j_namespace": "physical-graph-one"},
        }
    }
    with pytest.raises(OperationConflict):
        OperationRepository(pg.engine, dataset_source="database").prepare(
            "source", "compatibility", payload, ["job"]
        )
    with pg.engine.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(operations)
            ).scalar_one()
            == 0
        )


@pytest.mark.parametrize(
    "representation", ["database", "DATABASE", " database ", "database ", " database"]
)
@pytest.mark.parametrize("action", ["source", "text", "schedule"])
def test_normalized_compatibility_dispatch_waits_for_clear_fence(
    pg, monkeypatch, representation, action
):
    from .test_authority_fence import startup_repository, payload

    repository = startup_repository(pg, monkeypatch, representation)
    locked, release = Event(), Event()

    def clear():
        # Hold the same row lock while clear admission commits on this connection.
        with pg.engine.begin() as connection:
            lock_active_dataset(connection, "a", "one")
            connection.execute(
                operations.insert().values(
                    operation_id="a" * 64,
                    workflow_id="graph-clear-test",
                    action="graph_clear",
                    input_digest="a" * 64,
                    status="dispatching",
                    payload={"tenant_id": "a", "dataset_id": "one"},
                )
            )
            locked.set()
            assert release.wait(5)

    with ThreadPoolExecutor(2) as pool:
        clearing = pool.submit(clear)
        assert locked.wait(5)
        dispatching = pool.submit(
            repository.prepare,
            action,
            "blocked",
            payload(text_writer_protocol=1),
            ["job"],
        )
        try:
            wait_for_lock(pg.engine)
        finally:
            release.set()
        clearing.result(5)
        with pytest.raises(DatasetInactiveError):
            dispatching.result(5)
    with pg.engine.connect() as connection:
        assert (
            connection.execute(
                sa.select(operations.c.operation_id).where(
                    operations.c.action == action
                )
            ).first()
            is None
        )
