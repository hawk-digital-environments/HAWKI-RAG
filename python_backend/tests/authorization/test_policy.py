"""Tests for the local dataset grant policy."""

import pytest

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization.errors import AuthorizationUnavailableError
from hawki_backend.authorization.models import (
    AuthorizationAction,
    AuthorizationRequest,
    DatasetGrant,
)
from hawki_backend.authorization.policy import LocalGrantPolicy
from hawki_backend.authorization.reader import InMemoryGrantReader


class _CountingReader:
    """Wrap a reader and record every lookup it is asked for."""

    def __init__(self, inner: InMemoryGrantReader) -> None:
        """Wrap the reader that answers the lookups."""
        self._inner = inner
        self.lookups: list[tuple[AuthenticatedPrincipal, str, AuthorizationAction]] = []

    def has_grant(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
        action: AuthorizationAction,
    ) -> bool:
        """Record the lookup and answer it."""
        self.lookups.append((principal, dataset_id, action))
        return self._inner.has_grant(principal, dataset_id, action)


class _UnavailableReader:
    """A reader whose grant source cannot be reached."""

    def has_grant(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
        action: AuthorizationAction,
    ) -> bool:
        """Refuse to answer, because the grant source is down."""
        raise AuthorizationUnavailableError()


def _request(
    principal: AuthenticatedPrincipal,
    action: AuthorizationAction = AuthorizationAction.QUERY,
    dataset_id: str = "dataset-1",
) -> AuthorizationRequest:
    """Return a request for the principal."""
    return AuthorizationRequest(
        principal=principal,
        action=action,
        dataset_id=dataset_id,
    )


def _grant(
    principal: AuthenticatedPrincipal,
    action: AuthorizationAction = AuthorizationAction.QUERY,
    dataset_id: str = "dataset-1",
) -> DatasetGrant:
    """Return the grant the principal holds."""
    return DatasetGrant(
        tenant_id=principal.tenant_id,
        principal_kind=principal.kind,
        principal_id=principal.principal_id,
        dataset_id=dataset_id,
        action=action,
    )


def test_a_matching_scope_and_a_matching_query_grant_are_allowed(
    make_principal,
) -> None:
    principal = make_principal(scopes=frozenset({"rag:query"}))
    policy = LocalGrantPolicy(InMemoryGrantReader([_grant(principal)]))

    decision = policy.decide(_request(principal, AuthorizationAction.QUERY))

    assert decision is True


def test_a_matching_scope_and_a_matching_ingest_grant_are_allowed(
    make_principal,
) -> None:
    principal = make_principal(scopes=frozenset({"rag:ingest"}))
    policy = LocalGrantPolicy(
        InMemoryGrantReader([_grant(principal, AuthorizationAction.INGEST)])
    )

    decision = policy.decide(_request(principal, AuthorizationAction.INGEST))

    assert decision is True


def test_a_grant_without_the_required_scope_is_refused(make_principal) -> None:
    principal = make_principal(scopes=frozenset({"rag:ingest"}))
    reader = _CountingReader(InMemoryGrantReader([_grant(principal)]))
    policy = LocalGrantPolicy(reader)

    decision = policy.decide(_request(principal, AuthorizationAction.QUERY))

    assert decision is False
    assert reader.lookups == []


def test_a_scope_without_a_grant_is_refused(make_principal) -> None:
    principal = make_principal(scopes=frozenset({"rag:query"}))
    policy = LocalGrantPolicy(InMemoryGrantReader())

    decision = policy.decide(_request(principal, AuthorizationAction.QUERY))

    assert decision is False


def test_a_query_grant_does_not_authorize_ingest(make_principal) -> None:
    principal = make_principal()
    policy = LocalGrantPolicy(InMemoryGrantReader([_grant(principal)]))

    decision = policy.decide(_request(principal, AuthorizationAction.INGEST))

    assert decision is False


def test_an_ingest_grant_does_not_authorize_query(make_principal) -> None:
    principal = make_principal()
    policy = LocalGrantPolicy(
        InMemoryGrantReader([_grant(principal, AuthorizationAction.INGEST)])
    )

    decision = policy.decide(_request(principal, AuthorizationAction.QUERY))

    assert decision is False


def test_a_grant_on_one_dataset_does_not_authorize_another(make_principal) -> None:
    principal = make_principal()
    policy = LocalGrantPolicy(InMemoryGrantReader([_grant(principal)]))

    decision = policy.decide(_request(principal, dataset_id="dataset-2"))

    assert decision is False


def test_a_grant_of_one_principal_does_not_authorize_another(
    make_principal,
) -> None:
    holder = make_principal(subject="user-1")
    other = make_principal(subject="user-2")
    policy = LocalGrantPolicy(InMemoryGrantReader([_grant(holder)]))

    decision = policy.decide(_request(other))

    assert decision is False


def test_the_principal_kind_is_part_of_the_grant(make_principal) -> None:
    user = make_principal(kind=PrincipalKind.USER, subject="shared-id")
    service = make_principal(kind=PrincipalKind.SERVICE, client_id="shared-id")
    policy = LocalGrantPolicy(InMemoryGrantReader([_grant(user)]))

    assert user.principal_id == service.principal_id
    decision = policy.decide(_request(service))

    assert decision is False


def test_a_grant_of_one_tenant_does_not_authorize_another(make_principal) -> None:
    holder = make_principal(tenant_id="tenant-a")
    other = make_principal(tenant_id="tenant-b")
    policy = LocalGrantPolicy(InMemoryGrantReader([_grant(holder)]))

    assert holder.principal_id == other.principal_id
    assert holder.tenant_id != other.tenant_id
    decision = policy.decide(_request(other))

    assert decision is False


def test_an_empty_reader_refuses_everything(make_principal) -> None:
    principal = make_principal()
    policy = LocalGrantPolicy(InMemoryGrantReader())

    for action in AuthorizationAction:
        decision = policy.decide(_request(principal, action))
        assert decision is False


def test_the_reader_is_asked_about_the_exact_request(make_principal) -> None:
    principal = make_principal()
    reader = _CountingReader(InMemoryGrantReader([_grant(principal)]))
    policy = LocalGrantPolicy(reader)

    policy.decide(_request(principal, AuthorizationAction.QUERY, "dataset-1"))

    assert reader.lookups == [
        (principal, "dataset-1", AuthorizationAction.QUERY),
    ]


def test_an_unavailable_grant_source_is_not_turned_into_a_refusal(
    make_principal,
) -> None:
    principal = make_principal()
    policy = LocalGrantPolicy(_UnavailableReader())

    with pytest.raises(AuthorizationUnavailableError):
        policy.decide(_request(principal))


def test_a_refusal_does_not_change_the_grant_source(make_principal) -> None:
    principal = make_principal()
    source = InMemoryGrantReader()
    reader = _CountingReader(source)
    policy = LocalGrantPolicy(reader)

    decision = policy.decide(_request(principal))

    assert decision is False
    assert reader.lookups == [
        (principal, "dataset-1", AuthorizationAction.QUERY),
    ]
    assert not source.has_grant(principal, "dataset-1", AuthorizationAction.QUERY)
