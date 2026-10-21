"""The domain model of the backend's authorization boundary.

An authorization request names three things: the authenticated principal, the
action the principal wants to perform, and the logical dataset the action
targets. A policy answers that request with a single boolean: allowed or
denied.

The model is deliberately narrow. It carries no collection, no namespace, no
embedding configuration and no other storage identifier, because those are
derived server-side from the dataset record and are never taken from a
caller. There is no result object and no denial reason either. Every refusal
is the same `False`, so a caller cannot learn whether a dataset exists, is
inactive, belongs to another tenant, or simply has no grant.
"""

import unicodedata
from dataclasses import dataclass
from enum import StrEnum

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind

_MAX_DATASET_ID_LENGTH = 191

# Control, format and surrogate characters are refused in a dataset
# identifier. They are invisible or ambiguous, and a driver or a log line
# that receives one may break, truncate it or render it as something else.
# The identifier stays free to be any other Unicode text.
_FORBIDDEN_DATASET_ID_CATEGORIES = frozenset({"Cc", "Cf", "Cs"})


class AuthorizationAction(StrEnum):
    """The actions a principal may be authorized to perform on a dataset.

    The values are the permissions the current Laravel authorization model
    records for a dataset grant, so a grant means the same thing on both
    sides. There is deliberately no delete action: text-ingestion deletion
    is authorized with the ingest grant, so a separate action would have no
    counterpart to be granted from.
    """

    QUERY = "query"
    INGEST = "ingest"

    @property
    def required_scope(self) -> str:
        """Return the coarse scope a principal must hold for this action.

        This is the single source of truth for the coarse-scope vocabulary of
        this backend. The scope is a property of the action, not of the
        caller, so it cannot be widened by a token.
        """
        return _REQUIRED_SCOPES[self]


_REQUIRED_SCOPES: dict[AuthorizationAction, str] = {
    AuthorizationAction.QUERY: "rag:query",
    AuthorizationAction.INGEST: "rag:ingest",
}


@dataclass(frozen=True, slots=True)
class AuthorizationRequest:
    """A request to perform an action on a logical dataset.

    The tenant is not a field of the request. It is read from
    `principal.tenant_id`, so there is exactly one validated copy of it and
    the two can never disagree.

    Raises:
        ValueError: when the request is not well formed. A request that does
            not exist is never decided, so it is never allowed either.
    """

    principal: AuthenticatedPrincipal
    action: AuthorizationAction
    dataset_id: str

    def __post_init__(self) -> None:
        """Reject a request that names something that is not a request."""
        if not isinstance(self.principal, AuthenticatedPrincipal):
            raise ValueError("principal must be an AuthenticatedPrincipal")
        if not isinstance(self.action, AuthorizationAction):
            raise ValueError("action must be an AuthorizationAction")
        _require_dataset_id(self.dataset_id)


@dataclass(frozen=True, slots=True)
class DatasetGrant:
    """The identity of one dataset grant.

    A grant is held by exactly one principal, on exactly one dataset, for
    exactly one action, inside exactly one tenant. All five parts are part
    of the identity: a grant of one tenant is not a grant of another, and a
    grant held by a user is not a grant held by a service.

    The tenant is part of the identity even though the current Laravel
    authorization model has no tenant at all. The Python boundary already
    requires a validated tenant, and fixing the identity now keeps a later
    persistence branch from having to change it.

    Raises:
        ValueError: when the grant does not name a principal, a dataset and
            an action.
    """

    tenant_id: str
    principal_kind: PrincipalKind
    principal_id: str
    dataset_id: str
    action: AuthorizationAction

    def __post_init__(self) -> None:
        """Reject a grant that does not name everything it has to name."""
        if not isinstance(self.tenant_id, str) or not self.tenant_id:
            raise ValueError("tenant_id must be a non-empty string")
        if not isinstance(self.principal_kind, PrincipalKind):
            raise ValueError("principal_kind must be a PrincipalKind")
        if not isinstance(self.principal_id, str) or not self.principal_id:
            raise ValueError("principal_id must be a non-empty string")
        _require_dataset_id(self.dataset_id)
        if not isinstance(self.action, AuthorizationAction):
            raise ValueError("action must be an AuthorizationAction")


def _require_dataset_id(dataset_id: object) -> None:
    """Reject a dataset identifier that is not a usable identifier.

    A dataset identifier is compared exactly. It is never trimmed, because
    two spellings of one identifier must not reach two different lookups
    and must not both resolve to the same dataset.

    Control, format and surrogate characters are refused as well. They are
    invisible or ambiguous, so an identifier that carries one can be
    truncated, split or rendered as a different identifier by whatever
    receives it, and it can never name a dataset that was stored without
    it. The identifier is otherwise free to be any Unicode text.
    """
    if not isinstance(dataset_id, str) or not dataset_id:
        raise ValueError("dataset_id must be a non-empty string")
    if len(dataset_id) > _MAX_DATASET_ID_LENGTH:
        raise ValueError(
            f"dataset_id must be at most {_MAX_DATASET_ID_LENGTH} characters"
        )
    if dataset_id != dataset_id.strip():
        raise ValueError("dataset_id must not carry leading or trailing whitespace")
    for character in dataset_id:
        if unicodedata.category(character) in _FORBIDDEN_DATASET_ID_CATEGORIES:
            raise ValueError("dataset_id must not carry control or format characters")


__all__ = [
    "AuthorizationAction",
    "AuthorizationRequest",
    "DatasetGrant",
]
