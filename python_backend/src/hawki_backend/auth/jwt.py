"""JWT validation for the backend's authentication boundary.

The authenticator turns a bearer token into an `AuthenticatedPrincipal`. It
is deliberately narrow: it checks the token, resolves a signing key through
a `KeyProvider`, and reports exactly why a token was refused.

Nothing here logs, and nothing here turns a failure into an anonymous
caller. A token that is not understood is refused.
"""

import math
import time
from collections.abc import Callable
from datetime import datetime, timezone

import jwt
from jwt.exceptions import (
    InvalidAlgorithmError,
    InvalidAudienceError,
    InvalidIssuerError,
    InvalidSignatureError,
    InvalidTokenError,
    MissingRequiredClaimError,
)

from hawki_backend.auth.errors import AuthenticationError, AuthFailureReason
from hawki_backend.auth.jwks import KeyProvider, SigningKey
from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.settings import AuthSettings

_MAX_TOKEN_BYTES = 8192
_MAX_SUBJECT_LENGTH = 255
_REQUIRED_CLAIMS = ("exp", "sub", "iss", "aud")


class JwtAuthenticator:
    """Validate a bearer token and return the principal it authenticates."""

    def __init__(
        self,
        settings: AuthSettings,
        key_provider: KeyProvider,
        clock: Callable[[], float] = time.time,
    ) -> None:
        """Bind the authenticator to a validated configuration.

        Args:
            settings: The validated authentication configuration.
            key_provider: The source of signing keys.
            clock: A wall clock used for the token timestamps. It is not the
                monotonic clock the key provider uses to age its cache.
        """
        self._settings = settings
        self._key_provider = key_provider
        self._clock = clock

    def authenticate(self, token: str) -> AuthenticatedPrincipal:
        """Validate a token and return the principal it authenticates.

        Raises:
            AuthenticationError: When the token is refused. The reason is
                the only detail carried out of the exception.
            AuthenticationUnavailableError: When the signing keys could not
                be obtained. The failure is passed through unchanged.
        """
        alg, kid = self._read_header(token)
        key = self._key_provider.get_key(kid, alg)
        if key is None:
            raise AuthenticationError(AuthFailureReason.UNKNOWN_KEY)
        claims = self._verify(token, key, alg)
        expires_at, issued_at = self._check_time(claims)
        client_id = self._resolve_client(claims)
        kind = self._settings.clients.get(client_id)
        if kind is None:
            raise AuthenticationError(AuthFailureReason.CLIENT_NOT_ALLOWED)
        subject = self._read_subject(claims)
        tenant_id = self._read_tenant(claims)
        scopes = self._read_scopes(claims)
        self._reject_delegation(claims)
        token_id = self._read_token_id(claims)
        try:
            return AuthenticatedPrincipal(
                kind=kind,
                subject=subject,
                tenant_id=tenant_id,
                client_id=client_id,
                scopes=scopes,
                issuer=self._settings.issuer,
                expires_at=expires_at,
                token_id=token_id,
            )
        except ValueError as error:
            raise AuthenticationError(AuthFailureReason.INVALID_CLAIM) from error

    def _read_header(self, token: str) -> tuple[str, str]:
        """Return the algorithm and key id from the unverified header."""
        if not isinstance(token, str) or not token:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        if len(token) > _MAX_TOKEN_BYTES:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        if len(token.encode("utf-8")) > _MAX_TOKEN_BYTES:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        if token.count(".") != 2:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        try:
            header = jwt.get_unverified_header(token)
        except InvalidTokenError as error:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN) from error
        return self._read_algorithm(header), self._read_key_id(header)

    def _read_algorithm(self, header: dict[str, object]) -> str:
        """Return the signing algorithm, refusing anything not allowed."""
        algorithm = header.get("alg")
        if not isinstance(algorithm, str) or not algorithm:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        if algorithm not in self._settings.algorithms:
            raise AuthenticationError(AuthFailureReason.UNSUPPORTED_ALGORITHM)
        return algorithm

    def _read_key_id(self, header: dict[str, object]) -> str:
        """Return the key id, refusing a header that points somewhere else."""
        for forbidden in ("jku", "x5u", "jwk", "x5c"):
            if forbidden in header:
                raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        if "crit" in header:
            # Critical extensions ask the verifier to understand something this
            # module does not. No extension is supported, so any `crit` header
            # is refused, empty or not.
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        key_id = header.get("kid")
        if not isinstance(key_id, str) or not key_id:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        return key_id

    def _verify(self, token: str, key: SigningKey, algorithm: str) -> dict[str, object]:
        """Verify the signature and the standard claims with PyJWT."""
        try:
            return jwt.decode(
                token,
                key=key,
                algorithms=[algorithm],
                issuer=self._settings.issuer,
                audience=self._settings.audience,
                options={
                    "require": list(_REQUIRED_CLAIMS),
                    "verify_exp": False,
                    "verify_nbf": False,
                    "verify_iat": False,
                    "verify_sub": False,
                    "verify_jti": False,
                },
            )
        except MissingRequiredClaimError as error:
            raise AuthenticationError(AuthFailureReason.MISSING_CLAIM) from error
        except InvalidSignatureError as error:
            raise AuthenticationError(AuthFailureReason.INVALID_SIGNATURE) from error
        except InvalidIssuerError as error:
            raise AuthenticationError(AuthFailureReason.INVALID_ISSUER) from error
        except InvalidAudienceError as error:
            raise AuthenticationError(AuthFailureReason.INVALID_AUDIENCE) from error
        except InvalidAlgorithmError as error:
            raise AuthenticationError(
                AuthFailureReason.UNSUPPORTED_ALGORITHM
            ) from error
        except InvalidTokenError as error:
            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN) from error

    def _check_time(self, claims: dict[str, object]) -> tuple[datetime, float | None]:
        """Return the expiry and the issue time after checking the clock."""
        now = self._clock()
        leeway = self._settings.leeway_seconds
        expires_at = _numeric_date(claims, "exp", required=True)
        if expires_at is None:
            raise AuthenticationError(AuthFailureReason.MISSING_CLAIM)
        if now >= expires_at + leeway:
            raise AuthenticationError(AuthFailureReason.EXPIRED_TOKEN)
        not_before = _numeric_date(claims, "nbf", required=False)
        if not_before is not None and now < not_before - leeway:
            raise AuthenticationError(AuthFailureReason.NOT_YET_VALID)
        issued_at = _numeric_date(claims, "iat", required=False)
        if issued_at is not None and issued_at > now + leeway:
            raise AuthenticationError(AuthFailureReason.ISSUED_IN_FUTURE)
        self._check_lifetime(expires_at, issued_at, now)
        return (
            datetime.fromtimestamp(expires_at, tz=timezone.utc),
            issued_at,
        )

    def _check_lifetime(
        self, expires_at: float, issued_at: float | None, now: float
    ) -> None:
        """Refuse a token that stays valid for longer than the configured cap.

        When the token carries an issue time the cap applies to the lifetime
        the issuer actually gave it, `exp - iat`. The issue time may sit up to
        one leeway in the future, so the remaining validity is allowed to
        exceed the cap by that much.

        Without an issue time the best available bound is the remaining
        validity, `exp - now`, which is also what the leeway applies to.
        """
        maximum = self._settings.max_token_ttl_seconds
        if issued_at is not None:
            if expires_at - issued_at > maximum:
                raise AuthenticationError(AuthFailureReason.LIFETIME_TOO_LONG)
            return
        if expires_at - now > maximum + self._settings.leeway_seconds:
            raise AuthenticationError(AuthFailureReason.LIFETIME_TOO_LONG)

    def _resolve_client(self, claims: dict[str, object]) -> str:
        """Return the client identifier the token was issued to."""
        authorized_party = claims.get("azp")
        client_id = claims.get("client_id")
        if authorized_party is None and client_id is None:
            raise AuthenticationError(AuthFailureReason.CLIENT_NOT_ALLOWED)
        for value in (authorized_party, client_id):
            if value is not None and (not isinstance(value, str) or not value):
                raise AuthenticationError(AuthFailureReason.CLIENT_NOT_ALLOWED)
        if (
            authorized_party is not None
            and client_id is not None
            and authorized_party != client_id
        ):
            raise AuthenticationError(AuthFailureReason.CLIENT_NOT_ALLOWED)
        return authorized_party if authorized_party is not None else client_id

    def _read_subject(self, claims: dict[str, object]) -> str:
        """Return the subject of the token."""
        subject = claims.get("sub")
        if not isinstance(subject, str) or not subject:
            raise AuthenticationError(AuthFailureReason.INVALID_CLAIM)
        if len(subject) > _MAX_SUBJECT_LENGTH:
            raise AuthenticationError(AuthFailureReason.INVALID_CLAIM)
        return subject

    def _read_tenant(self, claims: dict[str, object]) -> str:
        """Return the tenant the token was issued for."""
        claim = self._settings.tenant_claim
        if claim not in claims:
            raise AuthenticationError(AuthFailureReason.MISSING_CLAIM)
        tenant_id = claims[claim]
        if not isinstance(tenant_id, str) or not tenant_id:
            raise AuthenticationError(AuthFailureReason.INVALID_CLAIM)
        return tenant_id

    def _read_scopes(self, claims: dict[str, object]) -> frozenset[str]:
        """Return the coarse scopes the token carries."""
        if "scope" not in claims:
            return frozenset()
        raw = claims["scope"]
        if not isinstance(raw, str):
            raise AuthenticationError(AuthFailureReason.INVALID_CLAIM)
        return frozenset(part for part in raw.split(" ") if part)

    def _reject_delegation(self, claims: dict[str, object]) -> None:
        """Refuse a token that carries a delegation."""
        if "act" in claims or "may_act" in claims:
            raise AuthenticationError(AuthFailureReason.DELEGATION_NOT_SUPPORTED)

    def _read_token_id(self, claims: dict[str, object]) -> str | None:
        """Return the token identifier, when the token carries one."""
        if "jti" not in claims:
            return None
        token_id = claims["jti"]
        if not isinstance(token_id, str) or not token_id:
            raise AuthenticationError(AuthFailureReason.INVALID_CLAIM)
        return token_id


def _numeric_date(
    claims: dict[str, object], name: str, *, required: bool
) -> float | None:
    """Return a timestamp claim, refusing anything that is not a number."""
    if name not in claims:
        if required:
            raise AuthenticationError(AuthFailureReason.MISSING_CLAIM)
        return None
    value = claims[name]
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise AuthenticationError(AuthFailureReason.INVALID_CLAIM)
    try:
        number = float(value)
    except (OverflowError, ValueError) as error:
        raise AuthenticationError(AuthFailureReason.INVALID_CLAIM) from error
    if not math.isfinite(number):
        raise AuthenticationError(AuthFailureReason.INVALID_CLAIM)
    return number


__all__ = ["JwtAuthenticator"]
