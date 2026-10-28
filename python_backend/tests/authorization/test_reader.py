"""Tests for the in-memory dataset grant reader."""

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.authorization.models import AuthorizationAction, DatasetGrant
from hawki_backend.authorization.reader import InMemoryGrantReader

TENANT = "tenant-a"
OTHER_TENANT = "tenant-b"
PRINCIPAL_ID = "user-1"
OTHER_PRINCIPAL_ID = "user-2"
DATASET = "dataset-1"
OTHER_DATASET = "dataset-2"


def _grant(
    tenant_id: str = TENANT,
    principal_kind: PrincipalKind = PrincipalKind.USER,
    principal_id: str = PRINCIPAL_ID,
    dataset_id: str = DATASET,
    action: AuthorizationAction = AuthorizationAction.QUERY,
) -> DatasetGrant:
    """Return a grant with the given identity."""
    return DatasetGrant(
        tenant_id=tenant_id,
        principal_kind=principal_kind,
        principal_id=principal_id,
        dataset_id=dataset_id,
        action=action,
    )


def test_the_exact_grant_is_found(make_principal) -> None:
    principal = make_principal(tenant_id=TENANT, subject=PRINCIPAL_ID)
    reader = InMemoryGrantReader([_grant()])

    assert reader.has_grant(principal, DATASET, AuthorizationAction.QUERY)


def test_a_grant_of_another_tenant_is_not_found(make_principal) -> None:
    principal = make_principal(tenant_id=OTHER_TENANT, subject=PRINCIPAL_ID)
    reader = InMemoryGrantReader([_grant(tenant_id=TENANT)])

    assert not reader.has_grant(principal, DATASET, AuthorizationAction.QUERY)


def test_a_grant_of_another_principal_kind_is_not_found(make_principal) -> None:
    principal = make_principal(
        kind=PrincipalKind.SERVICE,
        subject="ignored",
        client_id=PRINCIPAL_ID,
    )
    reader = InMemoryGrantReader([_grant(principal_kind=PrincipalKind.USER)])

    assert not reader.has_grant(principal, DATASET, AuthorizationAction.QUERY)


def test_a_grant_of_another_principal_is_not_found(make_principal) -> None:
    principal = make_principal(subject=OTHER_PRINCIPAL_ID)
    reader = InMemoryGrantReader([_grant(principal_id=PRINCIPAL_ID)])

    assert not reader.has_grant(principal, DATASET, AuthorizationAction.QUERY)


def test_a_grant_on_another_dataset_is_not_found(make_principal) -> None:
    principal = make_principal(subject=PRINCIPAL_ID)
    reader = InMemoryGrantReader([_grant(dataset_id=OTHER_DATASET)])

    assert not reader.has_grant(principal, DATASET, AuthorizationAction.QUERY)


def test_a_grant_for_another_action_is_not_found(make_principal) -> None:
    principal = make_principal(subject=PRINCIPAL_ID)
    reader = InMemoryGrantReader([_grant(action=AuthorizationAction.INGEST)])

    assert not reader.has_grant(principal, DATASET, AuthorizationAction.QUERY)


def test_an_empty_reader_finds_nothing(make_principal) -> None:
    principal = make_principal(subject=PRINCIPAL_ID)
    reader = InMemoryGrantReader()

    assert not reader.has_grant(principal, DATASET, AuthorizationAction.QUERY)


def test_the_reader_has_no_wildcard(make_principal) -> None:
    principal = make_principal(subject=PRINCIPAL_ID)
    reader = InMemoryGrantReader([_grant(dataset_id=OTHER_DATASET)])

    for dataset_id in (DATASET, "dataset-3", "*", " "):
        assert not reader.has_grant(principal, dataset_id, AuthorizationAction.QUERY)


def test_the_reader_answers_every_lookup(make_principal) -> None:
    principal = make_principal(subject=PRINCIPAL_ID)
    reader = InMemoryGrantReader([_grant()])

    assert not reader.has_grant(principal, "", AuthorizationAction.QUERY)
    assert not reader.has_grant(principal, " dataset-1", AuthorizationAction.QUERY)
    assert not reader.has_grant(principal, "data\x00set", AuthorizationAction.QUERY)
    assert not reader.has_grant(principal, DATASET, "query")  # type: ignore[arg-type]


def test_the_reader_cannot_grant_anything() -> None:
    reader = InMemoryGrantReader()

    for name in ("grant", "revoke", "add", "remove", "set_grants"):
        assert not hasattr(reader, name)
