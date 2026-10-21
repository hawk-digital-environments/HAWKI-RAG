"""Tests of the grant reader against a real PostgreSQL database.

These tests are the only ones that prove the statement and the schema work
on the engine the backend runs against. They are skipped when no database
is configured.
"""

from datetime import datetime, timezone

import pytest
from sqlalchemy.engine import Engine
from sqlalchemy.exc import IntegrityError

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization.models import AuthorizationAction
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.persistence.schema import dataset_grants, datasets

TENANT = "tenant-a"
OTHER_TENANT = "tenant-b"
DATASET = "dataset-1"
OTHER_DATASET = "dataset-2"
PRINCIPAL_ID = "user-1"


def _principal(
    kind: PrincipalKind = PrincipalKind.USER,
    subject: str = PRINCIPAL_ID,
    tenant_id: str = TENANT,
    client_id: str = "hawki-console",
) -> AuthenticatedPrincipal:
    """Return an authenticated principal."""
    return AuthenticatedPrincipal(
        kind=kind,
        subject=subject,
        tenant_id=tenant_id,
        client_id=client_id,
        scopes=frozenset({"rag:query", "rag:ingest"}),
        issuer="https://issuer.example",
        expires_at=datetime(2026, 1, 1, tzinfo=timezone.utc),
    )


@pytest.fixture
def database(engine: Engine, migrated: None) -> Engine:
    """Return an engine whose owned tables are empty."""
    with engine.begin() as connection:
        connection.execute(dataset_grants.delete())
        connection.execute(datasets.delete())
    return engine


def _add_dataset(
    engine: Engine,
    dataset_id: str = DATASET,
    tenant_id: str = TENANT,
    status: str = "active",
) -> None:
    """Insert one dataset."""
    with engine.begin() as connection:
        connection.execute(
            datasets.insert().values(
                tenant_id=tenant_id,
                dataset_id=dataset_id,
                status=status,
            )
        )


def _add_grant(
    engine: Engine,
    dataset_id: str = DATASET,
    tenant_id: str = TENANT,
    principal_type: str = "user",
    principal_id: str = PRINCIPAL_ID,
    permission: str = "query",
) -> None:
    """Insert one grant."""
    with engine.begin() as connection:
        connection.execute(
            dataset_grants.insert().values(
                tenant_id=tenant_id,
                dataset_id=dataset_id,
                principal_type=principal_type,
                principal_id=principal_id,
                permission=permission,
            )
        )


def test_the_matching_grant_is_found(database: Engine) -> None:
    _add_dataset(database)
    _add_grant(database)
    reader = DatabaseGrantReader(database)

    assert reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)


def test_a_grant_of_another_tenant_is_not_found(database: Engine) -> None:
    _add_dataset(database, tenant_id=OTHER_TENANT)
    _add_grant(database, tenant_id=OTHER_TENANT)
    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)


def test_a_grant_of_another_principal_kind_is_not_found(database: Engine) -> None:
    _add_dataset(database)
    _add_grant(database, principal_type="user")
    reader = DatabaseGrantReader(database)

    service = _principal(
        kind=PrincipalKind.SERVICE, subject="ignored", client_id=PRINCIPAL_ID
    )

    assert not reader.has_grant(service, DATASET, AuthorizationAction.QUERY)


def test_a_grant_of_another_principal_is_not_found(database: Engine) -> None:
    _add_dataset(database)
    _add_grant(database)
    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(
        _principal(subject="user-2"), DATASET, AuthorizationAction.QUERY
    )


def test_a_grant_on_another_dataset_is_not_found(database: Engine) -> None:
    _add_dataset(database, dataset_id=OTHER_DATASET)
    _add_grant(database, dataset_id=OTHER_DATASET)
    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)


def test_a_grant_for_another_action_is_not_found(database: Engine) -> None:
    _add_dataset(database)
    _add_grant(database, permission="ingest")
    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)


def test_the_ingest_grant_is_found(database: Engine) -> None:
    _add_dataset(database)
    _add_grant(database, permission="ingest")
    reader = DatabaseGrantReader(database)

    assert reader.has_grant(_principal(), DATASET, AuthorizationAction.INGEST)


def test_an_archived_dataset_is_not_found(database: Engine) -> None:
    _add_dataset(database, status="archived")
    _add_grant(database)
    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)


def test_a_deleted_dataset_is_not_found(database: Engine) -> None:
    _add_dataset(database, status="deleted")
    _add_grant(database)
    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)


def test_an_unknown_dataset_is_not_found(database: Engine) -> None:
    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(
        _principal(), "no-such-dataset", AuthorizationAction.QUERY
    )


def test_a_missing_grant_is_not_found(database: Engine) -> None:
    _add_dataset(database)
    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)


def test_the_same_dataset_id_may_exist_in_two_tenants(database: Engine) -> None:
    _add_dataset(database, tenant_id=TENANT)
    _add_dataset(database, tenant_id=OTHER_TENANT)
    _add_grant(database, tenant_id=TENANT)
    reader = DatabaseGrantReader(database)

    assert reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)
    assert not reader.has_grant(
        _principal(tenant_id=OTHER_TENANT), DATASET, AuthorizationAction.QUERY
    )


def test_a_grant_cannot_cross_the_tenant_of_its_dataset(database: Engine) -> None:
    _add_dataset(database, tenant_id=TENANT)

    with pytest.raises(IntegrityError):
        _add_grant(database, tenant_id=OTHER_TENANT)


def test_a_grant_cannot_name_an_unknown_dataset(database: Engine) -> None:
    with pytest.raises(IntegrityError):
        _add_grant(database, dataset_id="no-such-dataset")


def test_a_dataset_status_outside_the_known_values_is_refused(
    database: Engine,
) -> None:
    with pytest.raises(IntegrityError):
        _add_dataset(database, status="paused")


def test_a_permission_outside_the_known_values_is_refused(database: Engine) -> None:
    _add_dataset(database)

    with pytest.raises(IntegrityError):
        _add_grant(database, permission="delete")


def test_a_principal_kind_outside_the_known_values_is_refused(
    database: Engine,
) -> None:
    _add_dataset(database)

    with pytest.raises(IntegrityError):
        _add_grant(database, principal_type="robot")


def test_the_same_grant_cannot_be_stored_twice(database: Engine) -> None:
    _add_dataset(database)
    _add_grant(database)

    with pytest.raises(IntegrityError):
        _add_grant(database)


def test_removing_a_dataset_removes_its_grants(database: Engine) -> None:
    _add_dataset(database)
    _add_grant(database)

    with database.begin() as connection:
        connection.execute(
            datasets.delete().where(
                datasets.c.tenant_id == TENANT, datasets.c.dataset_id == DATASET
            )
        )

    reader = DatabaseGrantReader(database)

    assert not reader.has_grant(_principal(), DATASET, AuthorizationAction.QUERY)
