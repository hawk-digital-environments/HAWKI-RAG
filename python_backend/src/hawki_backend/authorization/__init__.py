"""Authorization boundary for the backend.

This package answers one question: may an authenticated principal perform
an action on a logical dataset? It is the only place in the backend that
decides dataset access.

    from hawki_backend.authorization import (
        AuthorizationAction,
        AuthorizationRequest,
        LocalGrantPolicy,
    )

The boundary is closed by default. A request is allowed only when the
principal holds the coarse scope the action requires *and* the reader
confirms the exact grant for that principal, tenant, dataset and action.
There is no query-all fallback, no anonymous fallback and no self-grant.
"""

from hawki_backend.authorization.errors import AuthorizationUnavailableError
from hawki_backend.authorization.models import (
    AuthorizationAction,
    AuthorizationRequest,
    DatasetGrant,
)
from hawki_backend.authorization.policy import LocalGrantPolicy
from hawki_backend.authorization.ports import AuthorizationPort, DatasetGrantReader
from hawki_backend.authorization.reader import InMemoryGrantReader

__all__ = [
    "AuthorizationAction",
    "AuthorizationPort",
    "AuthorizationRequest",
    "AuthorizationUnavailableError",
    "DatasetGrant",
    "DatasetGrantReader",
    "InMemoryGrantReader",
    "LocalGrantPolicy",
]
