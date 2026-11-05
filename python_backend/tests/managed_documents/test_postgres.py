"""Actual PostgreSQL correctness: no concurrency claims are based on SQLite."""

import asyncio
import os
from concurrent.futures import ThreadPoolExecutor
from io import BytesIO
from pathlib import Path
from threading import Barrier, Event

import pytest
import sqlalchemy as sa
from alembic import command
from alembic.config import Config

from hawki_backend.managed_documents.input import ManagedError, ManagedInput
from hawki_backend.managed_documents.schema import (
    TABLES,
    documents,
    revisions,
    mutations,
)
from hawki_backend.workflow_control.schema import operations
from hawki_backend.persistence.schema import datasets, dataset_grants

from .conftest import configure, seed
from .test_lifecycle import create, revision, released, ready, count, writer


@pytest.fixture
def pg(tmp_path, monkeypatch):
    dsn = os.environ.get("HAWKI_EPOCH10_TEST_DSN")
    if not dsn:
        pytest.skip(
            "Set HAWKI_EPOCH10_TEST_DSN to a disposable *_test PostgreSQL database"
        )
    if not sa.engine.make_url(dsn).database.endswith("_test"):
        pytest.fail("Refusing a non-test database")
    monkeypatch.setenv("HAWKI_BACKEND_DB_DSN", dsn)
    command.upgrade(
        Config(str(Path(__file__).resolve().parents[2] / "alembic.ini")), "head"
    )
    engine = sa.create_engine(dsn)
    with engine.begin() as connection:
        for table in (*reversed(TABLES), operations, dataset_grants, datasets):
            connection.execute(table.delete())
    seed(engine)
    yield configure(engine, tmp_path)
    with engine.begin() as connection:
        for table in (*reversed(TABLES), operations, dataset_grants, datasets):
            connection.execute(table.delete())
    engine.dispose()


def mutate(env, key, *, purpose="create", doc_id=None, content=b"hello"):
    return asyncio.run(
        env.service.mutate(
            env.principal,
            purpose,
            ManagedInput.model_validate(
                {"dataset_id": "files"} if purpose == "create" else {"force": True}
            )
            if purpose != "delete"
            else ManagedInput(),
            "a.txt" if purpose != "delete" else None,
            BytesIO(content) if purpose != "delete" else None,
            key,
            doc_id,
        )
    )


@pytest.mark.parametrize("changed", [False, True])
def test_concurrent_same_key_has_one_document_revision_and_dispatch(pg, changed):
    barrier = Barrier(2)

    def call(content):
        barrier.wait(timeout=10)
        try:
            return mutate(pg, "same", content=content)[0]
        except ManagedError as error:
            return error.status

    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(call, [b"hello", b"changed" if changed else b"hello"]))
    assert 202 in results
    assert results.count(409) == int(changed)
    for table in (documents, revisions, mutations, operations):
        assert count(pg, table) == 1
    pg.port.start_ingest_workflow.assert_awaited_once()


def test_concurrent_same_content_different_keys_both_create(pg):
    barrier = Barrier(2)

    def call(key):
        barrier.wait(timeout=10)
        return mutate(pg, key)

    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(call, ["one", "two"]))
    assert [status for status, _ in results] == [202, 202]
    assert count(pg, documents) == 2
    assert len({body["document"]["id"] for _, body in results}) == 2


@pytest.mark.parametrize("second_purpose", ["update", "delete"])
def test_put_vs_put_or_delete_has_one_mutation_owner(pg, second_purpose):
    payload = create(pg)
    row = revision(pg, payload)
    released(pg, row)
    ready(pg, row)
    entered, release = Event(), Event()

    def cleanup(*args):
        entered.set()
        if not release.wait(10):
            raise RuntimeError("synchronization timeout")

    pg.service.cleanup.delete.side_effect = cleanup
    doc_id = payload["document"]["id"]
    with ThreadPoolExecutor(2) as pool:
        first = pool.submit(mutate, pg, "put-1", purpose="update", doc_id=doc_id)
        assert entered.wait(10)
        try:
            with pytest.raises(ManagedError) as caught:
                mutate(pg, "put-2", purpose=second_purpose, doc_id=doc_id)
            assert caught.value.status == 409
        finally:
            release.set()
        assert first.result(timeout=10)[0] == 202
    doc = pg.repository.document("default", doc_id)
    assert doc["generation"] == 1 and doc["current_revision_id"] == row["revision_id"]
    assert count(pg, revisions) == 2


def test_same_key_cleanup_retry_cannot_overlap_a_delayed_cleaner(pg):
    payload = create(pg)
    row = revision(pg, payload)
    released(pg, row)
    entered, release = Event(), Event()

    def cleanup(*args):
        entered.set()
        if not release.wait(10):
            raise RuntimeError("synchronization timeout")

    pg.service.cleanup.delete.side_effect = cleanup
    with ThreadPoolExecutor(2) as pool:
        first = pool.submit(
            mutate, pg, "delete", purpose="delete", doc_id=payload["document"]["id"]
        )
        assert entered.wait(10)
        try:
            assert (
                mutate(
                    pg, "delete", purpose="delete", doc_id=payload["document"]["id"]
                )[0]
                == 202
            )
        finally:
            release.set()
        assert first.result(timeout=10)[0] == 200
    pg.service.cleanup.delete.assert_called_once()


def test_writer_admission_and_delete_are_serialized(pg):
    payload = create(pg)
    row = revision(pg, payload)
    pg.repository.record_writer(writer(row))
    doc_id = payload["document"]["id"]
    assert mutate(pg, "delete", purpose="delete", doc_id=doc_id)[0] == 202
    with pytest.raises(ManagedError):
        pg.repository.record_writer(writer(row, attempt=2))
    pg.service.cleanup.delete.assert_not_called()
    pg.repository.record_writer(writer(row, action="release"))
    assert mutate(pg, "delete", purpose="delete", doc_id=doc_id)[0] == 200


def test_stale_generation_never_publishes(pg):
    payload = create(pg)
    row = revision(pg, payload)
    released(pg, row)
    with pg.engine.begin() as connection:
        connection.execute(documents.update().values(generation=9))
    assert ready(pg, row)["ignored"] is True
    assert (
        pg.repository.document("default", payload["document"]["id"])[
            "current_revision_id"
        ]
        is None
    )


def test_event_payload_collision_is_serialized_by_postgres(pg):
    payload = create(pg)
    row = revision(pg, payload)
    released(pg, row)
    barrier = Barrier(2)

    def complete(stamp):
        barrier.wait(timeout=10)
        try:
            ready(pg, row, occurred_at=stamp)
            return "accepted"
        except ManagedError as error:
            return error.status

    with ThreadPoolExecutor(2) as pool:
        results = list(
            pool.map(complete, ["2026-11-05T10:00:00Z", "2026-11-05T11:00:00Z"])
        )
    assert sorted(map(str, results)) == ["409", "accepted"]


@pytest.mark.parametrize("stage", ["fenced", "drained", "cleaned", "prepared"])
def test_replacement_crash_resumes_durable_stage_without_new_identity(
    pg, monkeypatch, stage
):
    payload = create(pg)
    row = revision(pg, payload)
    released(pg, row)
    ready(pg, row)
    original = pg.repository.advance
    original_prepare = pg.repository.prepare_successor
    original_claim = pg.repository.claim

    def advance(operation, next_stage):
        original(operation, next_stage)
        if next_stage == stage:
            raise SystemExit("simulated process crash")

    def claim(*args, **kwargs):
        operation = original_claim(*args, **kwargs)
        if stage == "fenced":
            raise SystemExit("simulated process crash")
        return operation

    def prepare(operation):
        result = original_prepare(operation)
        if stage == "prepared":
            raise SystemExit("simulated process crash")
        return result

    with monkeypatch.context() as patch:
        patch.setattr(pg.repository, "claim", claim)
        patch.setattr(pg.repository, "advance", advance)
        patch.setattr(pg.repository, "prepare_successor", prepare)
        with pytest.raises(SystemExit):
            mutate(pg, "replace", purpose="update", doc_id=payload["document"]["id"])
    assert (
        mutate(pg, "replace", purpose="update", doc_id=payload["document"]["id"])[0]
        == 202
    )
    assert count(pg, revisions) == 2 and count(pg, operations) == 2
    assert pg.port.start_ingest_workflow.await_count == 2


def test_crash_before_final_publication_rolls_back_revision_and_event_atomically(pg):
    payload = create(pg)
    row = revision(pg, payload)
    released(pg, row)

    def crash(conn, cursor, statement, parameters, context, executemany):
        if statement.startswith(
            "UPDATE hawki_backend.managed_documents SET generation="
        ):
            raise SystemExit("crash before current-generation publication")

    sa.event.listen(pg.engine, "before_cursor_execute", crash)
    try:
        with pytest.raises(SystemExit):
            ready(pg, row)
    finally:
        sa.event.remove(pg.engine, "before_cursor_execute", crash)
    assert pg.repository.revision(row["revision_id"])["status"] == "running"
    assert (
        pg.repository.document("default", payload["document"]["id"])["generation"] == 0
    )
    assert ready(pg, row)["duplicate"] is False
    assert (
        pg.repository.document("default", payload["document"]["id"])["generation"] == 1
    )


def test_database_rejects_cross_document_current_revision(pg):
    from sqlalchemy.exc import IntegrityError

    first = create(pg)
    second = create(pg, key="two")
    a, b = revision(pg, first), revision(pg, second)
    released(pg, a)
    ready(pg, a)
    with pytest.raises(IntegrityError), pg.engine.begin() as connection:
        connection.execute(
            documents.update()
            .where(documents.c.managed_document_id == first["document"]["id"])
            .values(current_revision_id=b["revision_id"])
        )


def test_generation_drift_after_sink_cleanup_cannot_finish_delete(pg):
    payload = create(pg)
    row = revision(pg, payload)
    released(pg, row)
    ready(pg, row)

    def drift(*args):
        with pg.engine.begin() as connection:
            connection.execute(
                documents.update().values(generation=2, current_revision_id=None)
            )

    pg.service.cleanup.delete.side_effect = drift
    with pytest.raises(ManagedError) as caught:
        mutate(pg, "delete", purpose="delete", doc_id=payload["document"]["id"])
    assert caught.value.status == 409
    assert (
        pg.repository.document("default", payload["document"]["id"])["status"]
        != "deleted"
    )


@pytest.mark.parametrize("purpose", ["create", "update"])
@pytest.mark.parametrize("boundary", ["claim", "publish", "state"])
def test_postgres_artifact_claim_and_publication_recovery(
    pg, monkeypatch, purpose, boundary
):
    from .test_artifact_recovery import test_crash_reconciles_same_owned_destination

    test_crash_reconciles_same_owned_destination(pg, monkeypatch, purpose, boundary)


@pytest.mark.parametrize("purpose", ["create", "update"])
def test_postgres_claim_transaction_failure_leaves_no_final_artifact(pg, purpose):
    from .test_artifact_recovery import (
        test_transaction_failure_before_claim_commit_cannot_publish,
    )

    test_transaction_failure_before_claim_commit_cannot_publish(pg, purpose)


def test_postgres_publication_update_rollback_reconciles_same_artifact(pg):
    from .test_artifact_recovery import (
        test_publication_state_transaction_rollback_retains_recoverable_owner,
    )

    test_publication_state_transaction_rollback_retains_recoverable_owner(pg)


def test_postgres_public_reads_follow_python_lifecycle(pg):
    from dataclasses import replace
    from .test_reads import (
        test_create_then_public_detail_and_list_without_laravel_rows,
        test_completion_is_authoritative_for_reads,
    )

    with pg.engine.begin() as connection:
        connection.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="files",
                principal_type="user",
                principal_id="42",
                permission="query",
            )
        )
    pg.authenticator.authenticate.return_value = replace(
        pg.principal, scopes=frozenset({"rag:query", "rag:ingest"})
    )
    test_create_then_public_detail_and_list_without_laravel_rows(pg)
    test_completion_is_authoritative_for_reads(pg)
