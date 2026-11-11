"""PostgreSQL admission-fence and durable-cleanup tests.

The dataset row is the single admission fence shared by text, managed and URL
admission, grant writes, token redemption, archive and finalization. These
tests exercise that shared transaction directly, and they prove that an
archived row remains durable cleanup work across coordinator instances.
"""

from __future__ import annotations

import threading
import time
from datetime import datetime, timedelta, timezone

import pytest
import sqlalchemy as sa
from sqlalchemy.engine import Engine

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.datasets.cleanup import DatasetCleanupCoordinator
from hawki_backend.managed_documents.repository import ManagedRepository
from hawki_backend.managed_documents.schema import documents
from hawki_backend.persistence.datasets import (
    DatasetInactiveError,
    DatasetStore,
    lock_active_dataset,
)
from hawki_backend.persistence.schema import (
    ARCHIVED_STATUS,
    dataset_grant_tokens,
    dataset_grants,
    datasets,
)
from hawki_backend.text_ingestion.repository import TextOperationRepository
from hawki_backend.text_ingestion.schema import ingest_text_operations
from hawki_backend.url_ingestion.repository import UrlRepository

TENANT = "tenant-a"
DATASET = "dataset-a"
USER_ID = "user-1"
NOW = datetime(2026, 11, 10, 12, 0, tzinfo=timezone.utc)


@pytest.fixture
def database(engine: Engine, migrated: None) -> Engine:
    """Return an engine whose owned dataset tables are empty."""
    with engine.begin() as connection:
        connection.execute(documents.delete())
        connection.execute(dataset_grant_tokens.delete())
        connection.execute(dataset_grants.delete())
        connection.execute(datasets.delete())
    return engine


def _user() -> AuthenticatedPrincipal:
    return AuthenticatedPrincipal(
        kind=PrincipalKind.USER,
        subject=USER_ID,
        tenant_id=TENANT,
        client_id="hawki-console",
        scopes=frozenset({"rag:query", "rag:ingest"}),
        issuer="https://issuer.example",
        expires_at=datetime(2100, 1, 1, tzinfo=timezone.utc),
    )


def _create(store: DatasetStore) -> None:
    store.create(
        TENANT,
        dataset_id=DATASET,
        name="Dataset A",
        description=None,
        qdrant_collection="hawki_dataset_a",
        neo4j_namespace="hawki_dataset_a",
        embedding_provider="ollama",
        embedding_model="bge-m3",
        graph_enabled=False,
        requested={},
        issue_token=False,
        now=NOW,
    )


def _wait_for_blocked_writer(engine: Engine) -> None:
    """Wait until another transaction is actually waiting on a lock."""

    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        with engine.connect() as connection:
            waiting = connection.execute(
                sa.text(
                    "select count(*) from pg_stat_activity "
                    "where state = 'active' and wait_event_type = 'Lock'"
                )
            ).scalar_one()
        if waiting:
            return
        time.sleep(0.01)
    raise AssertionError("the admission transaction never waited on the fence")


def test_a_grant_write_cannot_commit_after_an_archive(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    locked = threading.Event()
    release = threading.Event()
    outcome: dict[str, object] = {}

    def archive() -> None:
        with database.begin() as connection:
            lock_active_dataset(connection, TENANT, DATASET)
            locked.set()
            release.wait(5)
            connection.execute(
                datasets.update()
                .where(
                    datasets.c.tenant_id == TENANT,
                    datasets.c.dataset_id == DATASET,
                )
                .values(status=ARCHIVED_STATUS, updated_at=NOW)
            )

    def grant() -> None:
        locked.wait(5)
        try:
            store.upsert_grant(TENANT, DATASET, "user", USER_ID, "query")
        except DatasetInactiveError:
            outcome["result"] = "inactive"
        else:
            outcome["result"] = "granted"

    archiver = threading.Thread(target=archive)
    writer = threading.Thread(target=grant)
    archiver.start()
    assert locked.wait(5)
    writer.start()
    _wait_for_blocked_writer(database)
    release.set()
    archiver.join(5)
    writer.join(5)

    assert outcome["result"] == "inactive"
    with database.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(dataset_grants)
            ).scalar_one()
            == 0
        )


def test_a_token_cannot_be_redeemed_after_an_archive(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    token, _ = store.issue_grant_token(TENANT, DATASET, now=NOW)
    locked = threading.Event()
    release = threading.Event()
    outcome: dict[str, object] = {}

    def archive() -> None:
        with database.begin() as connection:
            lock_active_dataset(connection, TENANT, DATASET)
            locked.set()
            release.wait(5)
            connection.execute(
                datasets.update()
                .where(
                    datasets.c.tenant_id == TENANT,
                    datasets.c.dataset_id == DATASET,
                )
                .values(status=ARCHIVED_STATUS, updated_at=NOW)
            )

    def redeem() -> None:
        locked.wait(5)
        try:
            store.redeem_grant_token(
                TENANT,
                DATASET,
                "user",
                USER_ID,
                token,
                now=NOW + timedelta(minutes=1),
            )
        except DatasetInactiveError:
            outcome["result"] = "inactive"
        else:
            outcome["result"] = "redeemed"

    archiver = threading.Thread(target=archive)
    redeemer = threading.Thread(target=redeem)
    archiver.start()
    assert locked.wait(5)
    redeemer.start()
    _wait_for_blocked_writer(database)
    release.set()
    archiver.join(5)
    redeemer.join(5)

    assert outcome["result"] == "inactive"
    with database.connect() as connection:
        assert (
            connection.execute(
                sa.select(dataset_grant_tokens.c.consumed_at)
            ).scalar_one()
            is None
        )
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(dataset_grants)
            ).scalar_one()
            == 0
        )


def test_text_admission_rechecks_after_waiting_for_archive(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    store.archive(TENANT, DATASET, now=NOW + timedelta(minutes=1))
    repository = TextOperationRepository(database)

    with pytest.raises(DatasetInactiveError):
        repository.claim(
            {
                "tenant_id": TENANT,
                "dataset_id": DATASET,
                "idempotency_key": "text-key",
                "source_id": "text-source",
                "request_metadata": {},
            }
        )

    with database.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(ingest_text_operations)
            ).scalar_one()
            == 0
        )


def test_managed_admission_refuses_an_archived_dataset(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    store.archive(TENANT, DATASET, now=NOW + timedelta(minutes=1))
    repository = ManagedRepository(database)

    with pytest.raises(DatasetInactiveError):
        repository.claim(
            {
                "mutation_id": "mutation-a",
                "tenant_id": TENANT,
                "managed_document_id": "document-a",
                "dataset_id": DATASET,
                "principal_id": USER_ID,
                "idempotency_key": "managed-key",
                "purpose": "create",
                "request_hash": "a" * 64,
                "request": {},
                "stage": "claimed",
            },
            None,
            dataset_id=DATASET,
        )


def test_url_admission_refuses_an_archived_dataset(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    store.archive(TENANT, DATASET, now=NOW + timedelta(minutes=1))
    repository = UrlRepository(database)

    with pytest.raises(DatasetInactiveError):
        repository.claim(
            _user(),
            {
                "request_id": "request-a",
                "tenant_id": TENANT,
                "principal_id": USER_ID,
                "dataset_id": DATASET,
                "idempotency_key": "url-key",
                "request_hash": "a" * 64,
                "task_id": "task-a",
                "purpose": "start",
                "body": {},
            },
            [],
            lambda row, request: {},
        )


def test_an_archived_dataset_is_durable_cleanup_work_across_restarts(
    database: Engine,
) -> None:
    store = DatasetStore(database)
    _create(store)
    store.archive(TENANT, DATASET, now=NOW + timedelta(minutes=1))
    with database.begin() as connection:
        connection.execute(
            documents.insert().values(
                managed_document_id="document-a",
                tenant_id=TENANT,
                dataset_id=DATASET,
                principal_id=USER_ID,
                generation=0,
                status="indexed",
                attributes={},
                created_at=NOW,
                updated_at=NOW,
            )
        )

    first = DatasetCleanupCoordinator(store)
    record, pending, blocking = _run(first)

    assert record.status == ARCHIVED_STATUS
    assert pending is True
    assert blocking == {"managed_documents": 1}

    # A restarted process sees the same durable row and finishes the work.
    with database.begin() as connection:
        connection.execute(documents.delete())
    second = DatasetCleanupCoordinator(store)
    record, pending, blocking = _run(second)

    assert record.status == "deleted"
    assert pending is False
    assert blocking == {}
    assert store.list_archived() == []


def _run(coordinator: DatasetCleanupCoordinator):
    import asyncio

    return asyncio.run(coordinator.run(TENANT, DATASET))
