"""Failure taxonomy for the backend's JWT authentication boundary.

Three separate failure categories exist, and they are siblings rather than
a hierarchy, because they map onto three different HTTP statuses:

* `AuthenticationError` - a credential was refused. Future HTTP 401.
* `AuthenticationUnavailableError` - authentication infrastructure was
  unavailable, so the credential was never evaluated. Future HTTP 503.
* `InsufficientScopeError` - the caller authenticated but holds too few
  coarse scopes. Future HTTP 403.

The reasons below are internal, stable and machine-readable. They describe
*why* a credential was refused or could not be checked. They are never sent
to a client: the HTTP layer maps every failure onto a small set of generic
response bodies so that a caller cannot learn which part of a token was
wrong.
"""

from enum import StrEnum


class AuthFailureReason(StrEnum):
    """Stable reasons a presented credential was refused."""

    MALFORMED_TOKEN = "malformed_token"
    UNSUPPORTED_ALGORITHM = "unsupported_algorithm"
    UNKNOWN_KEY = "unknown_key"
    INVALID_SIGNATURE = "invalid_signature"
    INVALID_ISSUER = "invalid_issuer"
    INVALID_AUDIENCE = "invalid_audience"
    MISSING_CLAIM = "missing_claim"
    INVALID_CLAIM = "invalid_claim"
    EXPIRED_TOKEN = "expired_token"
    NOT_YET_VALID = "not_yet_valid"
    ISSUED_IN_FUTURE = "issued_in_future"
    LIFETIME_TOO_LONG = "lifetime_too_long"
    CLIENT_NOT_ALLOWED = "client_not_allowed"
    DELEGATION_NOT_SUPPORTED = "delegation_not_supported"
    AUTHENTICATION_UNAVAILABLE = "authentication_unavailable"


class AuthenticationError(Exception):
    """Raised when a presented credential is refused.

    The `reason` attribute is the only detail carried out of this exception.
    Callers must not echo it into a response body.
    """

    def __init__(self, reason: AuthFailureReason) -> None:
        if not isinstance(reason, AuthFailureReason):
            raise TypeError("reason must be an AuthFailureReason")
        super().__init__(reason.value)
        self.reason = reason


class AuthenticationUnavailableError(Exception):
    """Raised when authentication infrastructure cannot check a credential.

    The signing keys, the key service or the configuration were unavailable,
    so the presented credential was never actually evaluated. This is a
    separate category from a refused credential and is deliberately *not* a
    subclass of `AuthenticationError`, so the two can never be confused when
    they are mapped onto HTTP statuses.
    """

    def __init__(self) -> None:
        super().__init__(AuthFailureReason.AUTHENTICATION_UNAVAILABLE.value)
        self.reason = AuthFailureReason.AUTHENTICATION_UNAVAILABLE


class InsufficientScopeError(Exception):
    """Raised when an authenticated principal lacks a required coarse scope.

    This is an authorization failure, not an authentication failure, so it is
    deliberately *not* a subclass of `AuthenticationError`: the two map onto
    different HTTP statuses.
    """

    def __init__(self, required: str) -> None:
        super().__init__(required)
        self.required = required

    @property
    def required_scopes(self) -> frozenset[str]:
        """Return the individual scope names the principal was required to hold."""
        return frozenset(self.required.split())


__all__ = [
    "AuthenticationError",
    "AuthenticationUnavailableError",
    "AuthFailureReason",
    "InsufficientScopeError",
]
