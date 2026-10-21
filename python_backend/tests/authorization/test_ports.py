"""Tests that the authorization implementations satisfy their ports."""

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.authorization.models import AuthorizationAction, DatasetGrant
from hawki_backend.authorization.policy import LocalGrantPolicy
from hawki_backend.authorization.ports import AuthorizationPort, DatasetGrantReader
from hawki_backend.authorization.reader import InMemoryGrantReader


def test_the_local_grant_policy_is_an_authorization_port() -> None:
    policy = LocalGrantPolicy(InMemoryGrantReader())

    assert isinstance(policy, AuthorizationPort)


def test_the_in_memory_reader_is_a_dataset_grant_reader() -> None:
    reader = InMemoryGrantReader(
        [
            DatasetGrant(
                tenant_id="tenant-a",
                principal_kind=PrincipalKind.USER,
                principal_id="user-1",
                dataset_id="dataset-1",
                action=AuthorizationAction.QUERY,
            )
        ]
    )

    assert isinstance(reader, DatasetGrantReader)
