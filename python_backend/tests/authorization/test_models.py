"""Tests for the authorization domain model."""

from dataclasses import FrozenInstanceError

import pytest

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.authorization.models import (
    AuthorizationAction,
    AuthorizationRequest,
    DatasetGrant,
)


def test_the_actions_are_exactly_query_and_ingest() -> None:
    assert [action.value for action in AuthorizationAction] == ["query", "ingest"]


def test_there_is_no_delete_action() -> None:
    assert "DELETE" not in AuthorizationAction.__members__
    assert "delete" not in {action.value for action in AuthorizationAction}


def test_the_required_scopes_are_the_coarse_scopes() -> None:
    assert AuthorizationAction.QUERY.required_scope == "rag:query"
    assert AuthorizationAction.INGEST.required_scope == "rag:ingest"


def test_a_valid_request_is_accepted(make_principal) -> None:
    principal = make_principal()

    request = AuthorizationRequest(
        principal=principal,
        action=AuthorizationAction.QUERY,
        dataset_id="dataset-1",
    )

    assert request.principal is principal
    assert request.action is AuthorizationAction.QUERY
    assert request.dataset_id == "dataset-1"


def test_the_request_takes_the_tenant_from_the_principal(make_principal) -> None:
    principal = make_principal(tenant_id="tenant-b")

    request = AuthorizationRequest(
        principal=principal,
        action=AuthorizationAction.QUERY,
        dataset_id="dataset-1",
    )

    assert request.principal.tenant_id == "tenant-b"


def test_an_empty_dataset_is_rejected(make_principal) -> None:
    with pytest.raises(ValueError, match="dataset_id"):
        AuthorizationRequest(
            principal=make_principal(),
            action=AuthorizationAction.QUERY,
            dataset_id="",
        )


def test_a_dataset_that_is_not_a_string_is_rejected(make_principal) -> None:
    with pytest.raises(ValueError, match="dataset_id"):
        AuthorizationRequest(
            principal=make_principal(),
            action=AuthorizationAction.QUERY,
            dataset_id=17,  # type: ignore[arg-type]
        )


@pytest.mark.parametrize(
    "dataset_id",
    [" dataset-1", "dataset-1 ", "\tdataset-1", "dataset-1\n"],
)
def test_a_padded_dataset_is_rejected(make_principal, dataset_id: str) -> None:
    with pytest.raises(ValueError, match="whitespace"):
        AuthorizationRequest(
            principal=make_principal(),
            action=AuthorizationAction.QUERY,
            dataset_id=dataset_id,
        )


def test_a_dataset_of_191_characters_is_accepted(make_principal) -> None:
    request = AuthorizationRequest(
        principal=make_principal(),
        action=AuthorizationAction.QUERY,
        dataset_id="d" * 191,
    )

    assert len(request.dataset_id) == 191


def test_a_dataset_longer_than_191_characters_is_rejected(make_principal) -> None:
    with pytest.raises(ValueError, match="191"):
        AuthorizationRequest(
            principal=make_principal(),
            action=AuthorizationAction.QUERY,
            dataset_id="d" * 192,
        )


@pytest.mark.parametrize(
    "dataset_id",
    [
        "a\x00b",
        "a\nb",
        "a\r b",
        "a\tb",
        "a\u200bb",
        "\u200b",
        "a\ufeffb",
        "a\ud800b",
    ],
)
def test_a_dataset_with_a_control_or_format_character_is_rejected(
    make_principal, dataset_id: str
) -> None:
    with pytest.raises(ValueError, match="control or format"):
        AuthorizationRequest(
            principal=make_principal(),
            action=AuthorizationAction.QUERY,
            dataset_id=dataset_id,
        )


@pytest.mark.parametrize(
    "dataset_id",
    ["dataset-1", "datäset-1", "数据集-1", "dataset 1", "d" * 191],
)
def test_an_ordinary_dataset_is_accepted(make_principal, dataset_id: str) -> None:
    request = AuthorizationRequest(
        principal=make_principal(),
        action=AuthorizationAction.QUERY,
        dataset_id=dataset_id,
    )

    assert request.dataset_id == dataset_id


def test_a_principal_that_is_not_a_principal_is_rejected() -> None:
    with pytest.raises(ValueError, match="principal"):
        AuthorizationRequest(
            principal="hawki-console",  # type: ignore[arg-type]
            action=AuthorizationAction.QUERY,
            dataset_id="dataset-1",
        )


def test_an_action_that_is_not_an_action_is_rejected(make_principal) -> None:
    with pytest.raises(ValueError, match="action"):
        AuthorizationRequest(
            principal=make_principal(),
            action="query",  # type: ignore[arg-type]
            dataset_id="dataset-1",
        )


def test_the_request_is_immutable(make_principal) -> None:
    request = AuthorizationRequest(
        principal=make_principal(),
        action=AuthorizationAction.QUERY,
        dataset_id="dataset-1",
    )

    with pytest.raises(FrozenInstanceError):
        request.dataset_id = "dataset-2"  # type: ignore[misc]


def test_a_grant_names_its_tenant_principal_dataset_and_action() -> None:
    grant = DatasetGrant(
        tenant_id="tenant-a",
        principal_kind=PrincipalKind.USER,
        principal_id="user-1",
        dataset_id="dataset-1",
        action=AuthorizationAction.QUERY,
    )

    assert grant.tenant_id == "tenant-a"
    assert grant.principal_kind is PrincipalKind.USER
    assert grant.principal_id == "user-1"
    assert grant.dataset_id == "dataset-1"
    assert grant.action is AuthorizationAction.QUERY


def test_a_grant_without_a_tenant_is_rejected() -> None:
    with pytest.raises(ValueError, match="tenant_id"):
        DatasetGrant(
            tenant_id="",
            principal_kind=PrincipalKind.USER,
            principal_id="user-1",
            dataset_id="dataset-1",
            action=AuthorizationAction.QUERY,
        )


def test_a_grant_with_a_principal_kind_that_is_not_one_is_rejected() -> None:
    with pytest.raises(ValueError, match="principal_kind"):
        DatasetGrant(
            tenant_id="tenant-a",
            principal_kind="user",  # type: ignore[arg-type]
            principal_id="user-1",
            dataset_id="dataset-1",
            action=AuthorizationAction.QUERY,
        )


def test_a_grant_with_a_padded_dataset_is_rejected() -> None:
    with pytest.raises(ValueError, match="whitespace"):
        DatasetGrant(
            tenant_id="tenant-a",
            principal_kind=PrincipalKind.USER,
            principal_id="user-1",
            dataset_id=" dataset-1",
            action=AuthorizationAction.QUERY,
        )


@pytest.mark.parametrize("dataset_id", ["a\x00b", "a\nb", "a\tb", "a\u200bb"])
def test_a_grant_with_a_control_or_format_character_is_rejected(
    dataset_id: str,
) -> None:
    with pytest.raises(ValueError, match="control or format"):
        DatasetGrant(
            tenant_id="tenant-a",
            principal_kind=PrincipalKind.USER,
            principal_id="user-1",
            dataset_id=dataset_id,
            action=AuthorizationAction.QUERY,
        )
