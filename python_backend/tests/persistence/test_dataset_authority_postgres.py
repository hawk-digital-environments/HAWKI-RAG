"""PostgreSQL tests for the canonical dataset authority store.

These tests exercise the real statements, constraints and transactional
behaviour of `DatasetStore`. They are skipped unless a disposable database is
configured, exactly like the other persistence tests.
"""

from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
import hashlib

import pytest
import sqlalchemy as sa
from sqlalchemy.engine import Engine
from sqlalchemy.exc import IntegrityError

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization.models import AuthorizationAction
from hawki_backend.persistence.datasets import (
    DatasetConflictError,
    DatasetInactiveError,
    DatasetNotFoundError,
    DatasetStore,
    DatasetTombstoneError,
    GrantTokenInvalidError,
    TOKEN_TTL,
)
from hawki_backend.persistence.schema import (
    dataset_grant_tokens,
    dataset_grants,
    datasets,
)

TENANT = "tenant-a"
OTHER_TENANT = "tenant-b"
DATASET = "dataset-a"
OTHER_DATASET = "dataset-b"
USER_ID = "user-1"
NOW = datetime(2026, 11, 10, 12, 0, tzinfo=timezone.utc)


def _user(tenant_id: str = TENANT, subject: str = USER_ID) -> AuthenticatedPrincipal:
    return AuthenticatedPrincipal(
        kind=PrincipalKind.USER,
        subject=subject,
        tenant_id=tenant_id,
        client_id="hawki-console",
        scopes=frozenset({"rag:query", "rag:ingest"}),
        issuer="https://issuer.example",
        expires_at=datetime(2100, 1, 1, tzinfo=timezone.utc),
    )


@pytest.fixture
def database(engine: Engine, migrated: None) -> Engine:
    """Return an engine whose owned dataset tables are empty."""
    with engine.begin() as connection:
        connection.execute(dataset_grant_tokens.delete())
        connection.execute(dataset_grants.delete())
        connection.execute(datasets.delete())
    return engine


def _create(
    store: DatasetStore,
    *,
    tenant_id: str = TENANT,
    dataset_id: str = DATASET,
    collection: str | None = None,
    namespace: str | None = None,
    issue_token: bool = True,
    now: datetime = NOW,
):
    return store.create(
        tenant_id,
        dataset_id=dataset_id,
        name=f"Name of {dataset_id}",
        description="description",
        qdrant_collection=collection or f"hawki_{dataset_id.replace('-', '_')}",
        neo4j_namespace=namespace or f"hawki_{dataset_id.replace('-', '_')}",
        embedding_provider="ollama",
        embedding_model="bge-m3",
        graph_enabled=False,
        requested={},
        issue_token=issue_token,
        now=now,
    )


def test_create_persists_metadata_and_only_the_token_hash(database: Engine) -> None:
    store = DatasetStore(database)

    record, created, token = _create(store)

    assert created is True
    assert token is not None
    assert token.startswith("dgt_")
    assert len(token) == 68
    assert record.dataset_id == DATASET
    assert record.status == "active"
    assert record.name == f"Name of {DATASET}"
    assert record.description == "description"
    assert record.qdrant_collection == "hawki_dataset_a"
    assert record.embedding_provider == "ollama"
    assert record.embedding_model == "bge-m3"
    assert record.graph_enabled is False
    assert record.deleted_at is None
    assert record.created_at == NOW

    with database.connect() as connection:
        stored = connection.execute(
            sa.select(
                dataset_grant_tokens.c.token_hash,
                dataset_grant_tokens.c.expires_at,
                dataset_grant_tokens.c.consumed_at,
            )
        ).one()

    assert stored.token_hash == hashlib.sha256(token.encode()).hexdigest()
    assert stored.token_hash != token
    assert stored.expires_at == NOW + TOKEN_TTL
    assert stored.consumed_at is None


def test_a_compatible_replay_returns_the_row_without_a_second_token(
    database: Engine,
) -> None:
    store = DatasetStore(database)
    first, created, token = _create(store)

    second, replayed, replay_token = _create(store)

    assert created is True
    assert replayed is False
    assert replay_token is None
    assert second == first
    assert token is not None
    with database.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(dataset_grant_tokens)
            ).scalar_one()
            == 1
        )


def test_an_incompatible_replay_is_a_conflict(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)

    with pytest.raises(DatasetConflictError) as error:
        store.create(
            TENANT,
            dataset_id=DATASET,
            name="A different name",
            description=None,
            qdrant_collection="hawki_dataset_a",
            neo4j_namespace="hawki_dataset_a",
            embedding_provider="ollama",
            embedding_model="bge-m3",
            graph_enabled=False,
            requested={"name": "A different name"},
            now=NOW,
        )

    assert error.value.field == "name"


def test_an_archived_or_deleted_identity_is_never_revived(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    store.archive(TENANT, DATASET, now=NOW + timedelta(hours=1))

    with pytest.raises(DatasetInactiveError):
        _create(store)

    store.finalize_deletion(TENANT, DATASET, now=NOW + timedelta(hours=2))

    with pytest.raises(DatasetTombstoneError):
        _create(store)


def test_rows_are_scoped_by_tenant(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, tenant_id=TENANT, collection="hawki_a")
    _create(
        store,
        tenant_id=OTHER_TENANT,
        collection="hawki_a_other",
        namespace="hawki_a_other",
    )

    assert store.get(TENANT, DATASET) is not None
    assert store.get(OTHER_TENANT, DATASET) is not None
    assert store.get("tenant-c", DATASET) is None
    assert [item.tenant_id for item in store.list_active(TENANT, 10)] == [TENANT]
    assert [item.tenant_id for item in store.list_active(OTHER_TENANT, 10)] == [
        OTHER_TENANT
    ]


def test_the_list_is_newest_first_and_bounded(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, dataset_id="older", now=NOW)
    _create(store, dataset_id="newer", now=NOW + timedelta(minutes=5))
    store.archive(TENANT, "older", now=NOW + timedelta(minutes=10))

    assert [item.dataset_id for item in store.list_active(TENANT, 10)] == ["newer"]
    assert [item.dataset_id for item in store.list_active(TENANT, 0)] == ["newer"]


def test_a_physical_scope_cannot_belong_to_two_datasets(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, dataset_id="one", collection="shared", namespace="graph-one")

    with pytest.raises(DatasetConflictError) as error:
        _create(
            store,
            dataset_id="two",
            collection="shared",
            namespace="graph-two",
        )

    assert error.value.field == "physical_scope"


def test_a_graph_namespace_cannot_belong_to_two_datasets(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, dataset_id="one", collection="one", namespace="shared")

    with pytest.raises(DatasetConflictError):
        _create(store, dataset_id="two", collection="two", namespace="shared")


def test_a_scope_is_resolved_only_for_an_active_complete_dataset(
    database: Engine,
) -> None:
    store = DatasetStore(database)
    _create(store, dataset_id="active")
    _create(
        store, dataset_id="archived", collection="archived_c", namespace="archived_n"
    )
    store.archive(TENANT, "archived", now=NOW + timedelta(minutes=1))

    scope = store.resolve_scope(TENANT, "active")

    assert scope is not None
    assert scope.qdrant_collection == "hawki_active"
    assert scope.embedding_provider == "ollama"
    assert scope.embedding_model == "bge-m3"
    assert store.resolve_scope(TENANT, "archived") is None
    assert store.resolve_scope(TENANT, "missing") is None
    assert store.resolve_scope(OTHER_TENANT, "active") is None


def test_grants_are_exact_and_idempotent(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    principal = _user()

    store.upsert_grant(TENANT, DATASET, "user", USER_ID, "query")
    store.upsert_grant(TENANT, DATASET, "user", USER_ID, "query")

    assert store.has_grant(principal, DATASET, AuthorizationAction.QUERY)
    assert not store.has_grant(principal, DATASET, AuthorizationAction.INGEST)
    assert not store.has_grant(
        _user(subject="user-2"), DATASET, AuthorizationAction.QUERY
    )
    assert not store.has_grant(
        _user(tenant_id=OTHER_TENANT), DATASET, AuthorizationAction.QUERY
    )

    service = AuthenticatedPrincipal(
        kind=PrincipalKind.SERVICE,
        subject="hawki-bff",
        tenant_id=TENANT,
        client_id="hawki-bff",
        scopes=frozenset({"rag:query"}),
        issuer="https://issuer.example",
        expires_at=datetime(2100, 1, 1, tzinfo=timezone.utc),
    )
    assert not store.has_grant(service, DATASET, AuthorizationAction.QUERY)


def test_a_grant_is_invisible_once_the_dataset_is_archived(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    store.upsert_grant(TENANT, DATASET, "user", USER_ID, "query")
    store.archive(TENANT, DATASET, now=NOW + timedelta(minutes=1))

    assert not store.has_grant(_user(), DATASET, AuthorizationAction.QUERY)
    assert store.list_query_datasets(_user()) == []


def test_query_datasets_are_ordered_exact_query_grants(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, dataset_id="b", collection="cb", namespace="nb")
    _create(store, dataset_id="a", collection="ca", namespace="na")
    _create(store, dataset_id="ingest-only", collection="ci", namespace="ni")
    _create(
        store,
        tenant_id=OTHER_TENANT,
        dataset_id="a",
        collection="ca-other",
        namespace="na-other",
    )
    for dataset_id in ("a", "b", "ingest-only"):
        store.upsert_grant(TENANT, dataset_id, "user", USER_ID, "query")
    store.upsert_grant(TENANT, "ingest-only", "user", USER_ID, "ingest")
    store.upsert_grant(OTHER_TENANT, "a", "user", USER_ID, "query")

    assert [item.dataset_id for item in store.list_query_datasets(_user())] == [
        "a",
        "b",
        "ingest-only",
    ]


def test_a_query_dataset_requires_a_complete_scope(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    store.upsert_grant(TENANT, DATASET, "user", USER_ID, "query")

    with database.begin() as connection:
        connection.execute(
            datasets.update()
            .where(datasets.c.tenant_id == TENANT)
            .values(embedding_model="")
        )

    assert store.list_query_datasets(_user()) == []


def test_an_unknown_grant_permission_is_refused(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)

    with pytest.raises(ValueError):
        store.upsert_grant(TENANT, DATASET, "user", USER_ID, "delete")


def test_redemption_consumes_the_token_and_creates_only_the_ingest_grant(
    database: Engine,
) -> None:
    store = DatasetStore(database)
    _create(store, issue_token=False)
    token, expires_at = store.issue_grant_token(TENANT, DATASET, now=NOW)

    store.redeem_grant_token(TENANT, DATASET, "user", USER_ID, token, now=NOW)

    assert expires_at == NOW + TOKEN_TTL
    assert store.has_grant(_user(), DATASET, AuthorizationAction.INGEST)
    assert not store.has_grant(_user(), DATASET, AuthorizationAction.QUERY)


def test_a_token_can_only_be_redeemed_once(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, issue_token=False)
    token, _ = store.issue_grant_token(TENANT, DATASET, now=NOW)
    store.redeem_grant_token(
        TENANT, DATASET, "user", USER_ID, token, now=NOW + timedelta(minutes=1)
    )

    with pytest.raises(GrantTokenInvalidError):
        store.redeem_grant_token(
            TENANT,
            DATASET,
            "user",
            "user-2",
            token,
            now=NOW + timedelta(minutes=2),
        )


@pytest.mark.parametrize(
    ("tenant_id", "dataset_id", "moment"),
    [
        (OTHER_TENANT, DATASET, NOW),
        (TENANT, OTHER_DATASET, NOW),
        (TENANT, DATASET, NOW + TOKEN_TTL + timedelta(seconds=1)),
    ],
)
def test_a_token_is_bound_to_tenant_dataset_and_expiry(
    database: Engine,
    tenant_id: str,
    dataset_id: str,
    moment: datetime,
) -> None:
    store = DatasetStore(database)
    _create(store, issue_token=False)
    _create(
        store,
        dataset_id=OTHER_DATASET,
        collection="other_c",
        namespace="other_n",
        issue_token=False,
    )
    token, _ = store.issue_grant_token(TENANT, DATASET, now=NOW)

    with pytest.raises((DatasetNotFoundError, GrantTokenInvalidError)):
        store.redeem_grant_token(
            tenant_id, dataset_id, "user", USER_ID, token, now=moment
        )


def test_an_unknown_token_is_refused(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, issue_token=False)

    with pytest.raises(GrantTokenInvalidError):
        store.redeem_grant_token(
            TENANT, DATASET, "user", USER_ID, "dgt_" + "a" * 64, now=NOW
        )


def test_concurrent_redemption_has_exactly_one_winner(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, issue_token=False)
    token, _ = store.issue_grant_token(TENANT, DATASET, now=NOW)

    def redeem(subject: str) -> str:
        try:
            store.redeem_grant_token(TENANT, DATASET, "user", subject, token, now=NOW)
        except GrantTokenInvalidError:
            return "invalid"
        return "granted"

    with ThreadPoolExecutor(max_workers=2) as pool:
        outcomes = list(pool.map(redeem, ["user-1", "user-2"]))

    assert sorted(outcomes) == ["granted", "invalid"]
    with database.connect() as connection:
        consumed = connection.execute(
            sa.select(dataset_grant_tokens.c.consumed_at)
        ).scalar_one()
        grants = (
            connection.execute(
                sa.select(dataset_grants.c.principal_id).where(
                    dataset_grants.c.permission == "ingest"
                )
            )
            .scalars()
            .all()
        )
    assert consumed is not None
    assert len(grants) == 1


def test_deleting_a_dataset_cascades_to_grants_and_tokens(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)
    store.upsert_grant(TENANT, DATASET, "user", USER_ID, "query")

    with database.begin() as connection:
        connection.execute(
            datasets.delete().where(
                datasets.c.tenant_id == TENANT,
                datasets.c.dataset_id == DATASET,
            )
        )
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(dataset_grants)
            ).scalar_one()
            == 0
        )
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(dataset_grant_tokens)
            ).scalar_one()
            == 0
        )


def test_a_token_hash_must_have_the_documented_length(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store, issue_token=False)

    with pytest.raises(IntegrityError):
        with database.begin() as connection:
            connection.execute(
                dataset_grant_tokens.insert().values(
                    token_hash="short",
                    tenant_id=TENANT,
                    dataset_id=DATASET,
                    expires_at=NOW + TOKEN_TTL,
                    created_at=NOW,
                    updated_at=NOW,
                )
            )


def test_an_empty_dataset_has_no_blocking_sources(database: Engine) -> None:
    store = DatasetStore(database)
    _create(store)

    assert store.owned_source_counts(TENANT, DATASET) == {
        "managed_documents": 0,
        "text_ingestions": 0,
        "url_sources": 0,
    }
