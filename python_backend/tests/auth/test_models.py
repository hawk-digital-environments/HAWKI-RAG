"""Tests for the immutable authenticated principal."""

from dataclasses import FrozenInstanceError, replace
from datetime import datetime, timedelta, timezone

import pytest

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind

_EXPIRES_AT = datetime(2026, 1, 1, 12, 0, tzinfo=timezone.utc)


def _principal(
    kind: PrincipalKind = PrincipalKind.USER,
    scopes: frozenset[str] = frozenset({"dataset:read"}),
) -> AuthenticatedPrincipal:
    """Build a valid principal that individual tests then vary."""
    return AuthenticatedPrincipal(
        kind=kind,
        subject="user-42",
        tenant_id="tenant-1",
        client_id="hawki-console",
        scopes=scopes,
        issuer="https://issuer.example",
        expires_at=_EXPIRES_AT,
        token_id="token-1",
    )


def test_principal_kind_values() -> None:
    assert PrincipalKind.SERVICE == "service"
    assert PrincipalKind.USER == "user"


def test_service_principal_is_identified_by_its_client() -> None:
    principal = _principal(kind=PrincipalKind.SERVICE)

    assert principal.principal_id == "hawki-console"


def test_user_principal_is_identified_by_its_subject() -> None:
    principal = _principal(kind=PrincipalKind.USER)

    assert principal.principal_id == "user-42"


def test_principal_is_immutable() -> None:
    principal = _principal()

    with pytest.raises(FrozenInstanceError):
        principal.subject = "someone-else"  # type: ignore[misc]


def test_principal_is_hashable() -> None:
    principal = _principal()

    assert hash(principal) == hash(_principal())


def test_scopes_are_stored_as_a_frozen_set() -> None:
    principal = _principal(scopes=frozenset({"dataset:read", "dataset:write"}))

    assert isinstance(principal.scopes, frozenset)
    assert principal.scopes == frozenset({"dataset:read", "dataset:write"})


def test_scopes_accept_a_mutable_collection_and_freeze_it() -> None:
    principal = _principal(scopes={"dataset:read"})  # type: ignore[arg-type]

    assert principal.scopes == frozenset({"dataset:read"})


def test_scopes_reject_a_single_scope_string() -> None:
    with pytest.raises(ValueError):
        _principal(scopes="dataset:read")  # type: ignore[arg-type]


def test_scopes_reject_empty_scope_names() -> None:
    with pytest.raises(ValueError):
        _principal(scopes=frozenset({""}))


def test_has_scopes_is_true_when_every_scope_is_held() -> None:
    principal = _principal(scopes=frozenset({"dataset:read", "dataset:write"}))

    assert principal.has_scopes("dataset:read", "dataset:write")


def test_has_scopes_is_false_when_a_scope_is_missing() -> None:
    principal = _principal(scopes=frozenset({"dataset:read"}))

    assert not principal.has_scopes("dataset:read", "dataset:write")


def test_has_scopes_is_true_without_a_requirement() -> None:
    principal = _principal()

    assert principal.has_scopes()


def test_has_scopes_ignores_duplicate_requirements() -> None:
    principal = _principal(scopes=frozenset({"dataset:read"}))

    assert principal.has_scopes("dataset:read", "dataset:read")


def test_expires_at_is_normalised_to_utc() -> None:
    principal = AuthenticatedPrincipal(
        kind=PrincipalKind.USER,
        subject="user-42",
        tenant_id="tenant-1",
        client_id="hawki-console",
        scopes=frozenset(),
        issuer="https://issuer.example",
        expires_at=datetime(2026, 1, 1, 13, 0, tzinfo=timezone(timedelta(hours=1))),
    )

    assert principal.expires_at == _EXPIRES_AT
    assert principal.expires_at.tzinfo is timezone.utc


def test_expires_at_rejects_a_naive_datetime() -> None:
    with pytest.raises(ValueError):
        AuthenticatedPrincipal(
            kind=PrincipalKind.USER,
            subject="user-42",
            tenant_id="tenant-1",
            client_id="hawki-console",
            scopes=frozenset(),
            issuer="https://issuer.example",
            expires_at=datetime(2026, 1, 1, 12, 0),
        )


def test_token_id_is_optional() -> None:
    principal = AuthenticatedPrincipal(
        kind=PrincipalKind.USER,
        subject="user-42",
        tenant_id="tenant-1",
        client_id="hawki-console",
        scopes=frozenset(),
        issuer="https://issuer.example",
        expires_at=_EXPIRES_AT,
    )

    assert principal.token_id is None


def test_token_id_is_carried_when_present() -> None:
    assert _principal().token_id == "token-1"


def test_empty_token_id_is_rejected() -> None:
    with pytest.raises(ValueError):
        AuthenticatedPrincipal(
            kind=PrincipalKind.USER,
            subject="user-42",
            tenant_id="tenant-1",
            client_id="hawki-console",
            scopes=frozenset(),
            issuer="https://issuer.example",
            expires_at=_EXPIRES_AT,
            token_id="",
        )


def test_empty_subject_is_rejected() -> None:
    with pytest.raises(ValueError):
        replace(_principal(), subject="")


def test_overlong_subject_is_rejected() -> None:
    with pytest.raises(ValueError):
        AuthenticatedPrincipal(
            kind=PrincipalKind.USER,
            subject="u" * 256,
            tenant_id="tenant-1",
            client_id="hawki-console",
            scopes=frozenset(),
            issuer="https://issuer.example",
            expires_at=_EXPIRES_AT,
        )


def test_empty_issuer_is_rejected() -> None:
    with pytest.raises(ValueError):
        AuthenticatedPrincipal(
            kind=PrincipalKind.USER,
            subject="user-42",
            tenant_id="tenant-1",
            client_id="hawki-console",
            scopes=frozenset(),
            issuer="",
            expires_at=_EXPIRES_AT,
        )


def test_empty_client_id_is_rejected() -> None:
    with pytest.raises(ValueError):
        AuthenticatedPrincipal(
            kind=PrincipalKind.USER,
            subject="user-42",
            tenant_id="tenant-1",
            client_id="",
            scopes=frozenset(),
            issuer="https://issuer.example",
            expires_at=_EXPIRES_AT,
        )


def test_empty_tenant_id_is_rejected() -> None:
    with pytest.raises(ValueError):
        AuthenticatedPrincipal(
            kind=PrincipalKind.USER,
            subject="user-42",
            tenant_id="",
            client_id="hawki-console",
            scopes=frozenset(),
            issuer="https://issuer.example",
            expires_at=_EXPIRES_AT,
        )


def test_tenant_id_with_invalid_characters_is_rejected() -> None:
    with pytest.raises(ValueError):
        AuthenticatedPrincipal(
            kind=PrincipalKind.USER,
            subject="user-42",
            tenant_id="tenant 1",
            client_id="hawki-console",
            scopes=frozenset(),
            issuer="https://issuer.example",
            expires_at=_EXPIRES_AT,
        )


def test_non_principal_kind_is_rejected() -> None:
    with pytest.raises(ValueError):
        AuthenticatedPrincipal(
            kind="user",  # type: ignore[arg-type]
            subject="user-42",
            tenant_id="tenant-1",
            client_id="hawki-console",
            scopes=frozenset(),
            issuer="https://issuer.example",
            expires_at=_EXPIRES_AT,
        )


def test_principal_carries_no_dataset_or_storage_identifiers() -> None:
    principal = _principal()

    for forbidden in (
        "dataset_id",
        "dataset_ids",
        "qdrant_collection",
        "neo4j_namespace",
        "access_scope_id",
        "access_scope_ids",
        "embedding_model",
    ):
        assert not hasattr(principal, forbidden)
