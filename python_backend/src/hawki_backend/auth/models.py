"""The immutable principal produced by a successful authentication.

A principal is the *only* thing the backend is allowed to learn from a
token. It is deliberately narrow: it carries who the caller is, which tenant
they act for, and which coarse scopes they hold. It never carries dataset,
collection or other storage identifiers, so those cannot leak into routing
or authorization decisions by accident.
"""

import re
from collections.abc import Iterable
from dataclasses import dataclass
from datetime import datetime, timezone
from enum import StrEnum


class PrincipalKind(StrEnum):
    """The kind of caller a token authenticates."""

    SERVICE = "service"
    USER = "user"


_TENANT_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$")
_MAX_SUBJECT_LENGTH = 255


def _require_text(name: str, value: object) -> None:
    """Reject a text attribute that is absent, empty or not a string."""
    if not isinstance(value, str) or not value:
        raise ValueError(f"{name} must be a non-empty string")


def _normalise_scopes(scopes: Iterable[str]) -> frozenset[str]:
    """Return the scope names as a frozen, duplicate-free set."""
    if isinstance(scopes, str):
        raise ValueError("scopes must be a collection of scope names, not one string")
    try:
        names = frozenset(scopes)
    except TypeError as error:
        raise ValueError(
            "scopes must be a collection of hashable scope names"
        ) from error
    for name in names:
        _require_text("scope", name)
    return names


def _normalise_expiry(value: datetime) -> datetime:
    """Return the expiry as a timezone-aware UTC timestamp."""
    if not isinstance(value, datetime):
        raise ValueError("expires_at must be a datetime")
    if value.tzinfo is None or value.utcoffset() is None:
        raise ValueError("expires_at must be timezone-aware")
    return value.astimezone(timezone.utc)


@dataclass(frozen=True, slots=True)
class AuthenticatedPrincipal:
    """An authenticated caller, reduced to the attributes the backend trusts.

    Instances are immutable and hashable. Every attribute is validated on
    construction, so a principal that exists is always a well-formed one.

    Raises:
        ValueError: when an attribute falls outside the trusted shape. The
            authenticator translates this into a refused credential.
    """

    kind: PrincipalKind
    subject: str
    tenant_id: str
    client_id: str
    scopes: frozenset[str]
    issuer: str
    expires_at: datetime
    token_id: str | None = None

    def __post_init__(self) -> None:
        """Normalise the scopes and expiry, and reject untrusted shapes."""
        if not isinstance(self.kind, PrincipalKind):
            raise ValueError("kind must be a PrincipalKind")
        _require_text("subject", self.subject)
        if len(self.subject) > _MAX_SUBJECT_LENGTH:
            raise ValueError(
                f"subject must be at most {_MAX_SUBJECT_LENGTH} characters"
            )
        _require_text("client_id", self.client_id)
        _require_text("issuer", self.issuer)
        if not isinstance(self.tenant_id, str) or not _TENANT_PATTERN.fullmatch(
            self.tenant_id
        ):
            raise ValueError("tenant_id must be a valid tenant identifier")
        if self.token_id is not None:
            _require_text("token_id", self.token_id)
        object.__setattr__(self, "scopes", _normalise_scopes(self.scopes))
        object.__setattr__(self, "expires_at", _normalise_expiry(self.expires_at))

    @property
    def principal_id(self) -> str:
        """Return the stable identifier this principal is attributed by.

        A service is identified by the client it authenticates as; a user by
        the subject of their token.
        """
        if self.kind is PrincipalKind.SERVICE:
            return self.client_id
        return self.subject

    def has_scopes(self, *required: str) -> bool:
        """Return whether the principal holds every required coarse scope."""
        return set(required).issubset(self.scopes)


__all__ = ["AuthenticatedPrincipal", "PrincipalKind"]
