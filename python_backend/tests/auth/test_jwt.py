"""Tests for the JWT authenticator."""

import base64
import dataclasses
import hashlib
import hmac
import json

import jwt
import pytest
from cryptography.hazmat.primitives import serialization

from hawki_backend.auth.errors import (
    AuthenticationError,
    AuthenticationUnavailableError,
    AuthFailureReason,
)
from hawki_backend.auth.jwt import JwtAuthenticator
from hawki_backend.auth.jwks import StaticKeyProvider
from hawki_backend.auth.models import PrincipalKind
from hawki_backend.settings import AuthSettings

from .conftest import EC_KID, RSA_KID, ControllableClock

_ISSUER = "https://issuer.example"
_AUDIENCE = "hawki-api"
_USER_CLIENT = "hawki-console"
_SERVICE_CLIENT = "batch-importer"
_TENANT = "tenant-1"


def _settings(auth_settings: AuthSettings) -> AuthSettings:
    """Return settings that know both a user client and a service client."""
    return dataclasses.replace(
        auth_settings,
        clients={
            _USER_CLIENT: PrincipalKind.USER,
            _SERVICE_CLIENT: PrincipalKind.SERVICE,
        },
    )


def _claims(clock: ControllableClock, **overrides: object) -> dict[str, object]:
    """Return a valid claim set, with overrides applied."""
    claims: dict[str, object] = {
        "iss": _ISSUER,
        "aud": _AUDIENCE,
        "sub": "user-42",
        "exp": int(clock()) + 300,
        "azp": _USER_CLIENT,
        "tenant_id": _TENANT,
    }
    claims.update(overrides)
    return claims


def _sign(
    claims: dict[str, object],
    key: object,
    algorithm: str = "RS256",
    headers: dict[str, str] | None = None,
) -> str:
    """Return a signed token."""
    return jwt.encode(claims, key, algorithm=algorithm, headers=headers or {})


def _authenticator(
    settings: AuthSettings,
    keys: dict[str, object],
    clock: ControllableClock,
) -> JwtAuthenticator:
    """Build an authenticator backed by a static key provider."""
    return JwtAuthenticator(
        settings,
        key_provider=StaticKeyProvider(keys),
        clock=clock,
    )


def _reason(error: AuthenticationError) -> AuthFailureReason:
    """Return the reason carried by an authentication error."""
    return error.reason


# ------------------------------------------------------------------- structure


def test_a_non_string_token_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(123)  # type: ignore[arg-type]

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_an_empty_token_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate("")

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_an_oversized_token_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate("a" * 8193)

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


@pytest.mark.parametrize(
    "token",
    ["one-segment", "two.segments", "four.segments.here.extra"],
)
def test_a_token_with_the_wrong_segment_count_is_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    clock: ControllableClock,
    token: str,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_a_token_with_malformed_base64_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate("!!!.@@@.###")

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_a_token_with_a_malformed_header_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    header = base64.urlsafe_b64encode(b"not json").rstrip(b"=").decode()

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(f"{header}.payload.signature")

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_a_token_with_a_malformed_payload_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    from cryptography.hazmat.primitives import hashes
    from cryptography.hazmat.primitives.asymmetric import padding

    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    header = _b64url(json.dumps({"alg": "RS256", "kid": RSA_KID}).encode())
    payload = _b64url(b"not json")
    signing_input = f"{header}.{payload}".encode()
    signature = rsa_signing_key.sign(signing_input, padding.PKCS1v15(), hashes.SHA256())

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(f"{header}.{payload}.{_b64url(signature)}")

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


# ---------------------------------------------------------------------- header


def _b64url(payload: bytes) -> str:
    """Return a base64url segment without padding."""
    return base64.urlsafe_b64encode(payload).rstrip(b"=").decode()


def _header_token(header: dict[str, object]) -> str:
    """Return a token whose header is exactly the given object.

    The payload and signature segments are well formed so that only the
    header is under test.
    """
    return (
        f"{_b64url(json.dumps(header).encode())}."
        f"{_b64url(json.dumps({'sub': 'user-42'}).encode())}."
        f"{_b64url(b'signature')}"
    )


def test_a_missing_algorithm_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(_header_token({"kid": RSA_KID}))

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_a_non_string_algorithm_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(_header_token({"alg": 7, "kid": RSA_KID}))

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


@pytest.mark.parametrize("algorithm", ["none", "HS256", "HS384", "HS512", "RS512"])
def test_an_unapproved_algorithm_is_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    clock: ControllableClock,
    algorithm: str,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(_header_token({"alg": algorithm, "kid": RSA_KID}))

    assert _reason(failure.value) is AuthFailureReason.UNSUPPORTED_ALGORITHM


def test_a_missing_key_id_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(_header_token({"alg": "RS256"}))

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_an_empty_key_id_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(_header_token({"alg": "RS256", "kid": ""}))

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_a_non_string_key_id_is_refused(
    auth_settings: AuthSettings, rsa_public_key: object, clock: ControllableClock
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(_header_token({"alg": "RS256", "kid": 7}))

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


@pytest.mark.parametrize("header_key", ["jku", "x5u", "jwk", "x5c"])
def test_a_key_location_header_is_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    clock: ControllableClock,
    header_key: str,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(
            _header_token(
                {
                    "alg": "RS256",
                    "kid": RSA_KID,
                    header_key: "https://elsewhere.example/keys",
                }
            )
        )

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


@pytest.mark.parametrize("critical", [["exp"], ["kid"], [], "exp", 7])
def test_a_critical_extension_is_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    clock: ControllableClock,
    critical: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(
            _header_token({"alg": "RS256", "kid": RSA_KID, "crit": critical})
        )

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


def test_an_empty_critical_extension_is_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(
            _header_token({"alg": "RS256", "kid": RSA_KID, "crit": []})
        )

    assert _reason(failure.value) is AuthFailureReason.MALFORMED_TOKEN


# ------------------------------------------------------------------------ keys


def test_a_known_key_id_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).client_id == _USER_CLIENT


def test_an_ec_key_authenticates(
    auth_settings: AuthSettings,
    ec_signing_key: object,
    ec_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {EC_KID: ec_public_key}, clock
    )
    token = _sign(
        _claims(clock), ec_signing_key, algorithm="ES256", headers={"kid": EC_KID}
    )

    assert authenticator.authenticate(token).subject == "user-42"


def test_an_unknown_key_id_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": "rotated"})

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.UNKNOWN_KEY


def test_an_unavailable_key_source_is_passed_through(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    clock: ControllableClock,
) -> None:
    class UnavailableProvider:
        def get_key(self, kid: str, alg: str) -> object:
            raise AuthenticationUnavailableError()

    authenticator = JwtAuthenticator(
        _settings(auth_settings),
        key_provider=UnavailableProvider(),
        clock=clock,
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    with pytest.raises(AuthenticationUnavailableError):
        authenticator.authenticate(token)


# ------------------------------------------------------------------- signature


def test_a_tampered_payload_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})
    header, payload, signature = token.split(".")
    claims = json.loads(base64.urlsafe_b64decode(payload + "=="))
    claims["sub"] = "someone-else"
    forged = base64.urlsafe_b64encode(json.dumps(claims).encode()).rstrip(b"=").decode()

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(f"{header}.{forged}.{signature}")

    assert _reason(failure.value) is AuthFailureReason.INVALID_SIGNATURE


def test_a_wrong_signing_key_with_the_same_key_id_is_refused(
    auth_settings: AuthSettings,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    from cryptography.hazmat.primitives.asymmetric import rsa as rsa_module

    other = rsa_module.generate_private_key(public_exponent=65537, key_size=2048)
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), other, headers={"kid": RSA_KID})

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_SIGNATURE


def test_an_algorithm_confusion_token_is_refused_before_any_key_lookup(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    class RecordingProvider:
        def __init__(self) -> None:
            self.calls: list[tuple[str, str]] = []

        def get_key(self, kid: str, alg: str) -> object:
            self.calls.append((kid, alg))
            return rsa_public_key

    provider = RecordingProvider()
    authenticator = JwtAuthenticator(
        _settings(auth_settings), key_provider=provider, clock=clock
    )
    public_pem = rsa_signing_key.public_key().public_bytes(
        serialization.Encoding.PEM,
        serialization.PublicFormat.SubjectPublicKeyInfo,
    )
    header = (
        base64.urlsafe_b64encode(json.dumps({"alg": "HS256", "kid": RSA_KID}).encode())
        .rstrip(b"=")
        .decode()
    )
    payload = (
        base64.urlsafe_b64encode(json.dumps(_claims(clock)).encode())
        .rstrip(b"=")
        .decode()
    )
    signature = (
        base64.urlsafe_b64encode(
            hmac.new(
                public_pem, f"{header}.{payload}".encode(), hashlib.sha256
            ).digest()
        )
        .rstrip(b"=")
        .decode()
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(f"{header}.{payload}.{signature}")

    assert _reason(failure.value) is AuthFailureReason.UNSUPPORTED_ALGORITHM
    assert provider.calls == []


# ------------------------------------------------------------ issuer/audience


def test_a_correct_issuer_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).issuer == _ISSUER


def test_a_wrong_issuer_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, iss="https://other.example"),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_ISSUER


def test_a_trailing_slash_issuer_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, iss=f"{_ISSUER}/"),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_ISSUER


def test_a_correct_audience_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).client_id == _USER_CLIENT


def test_an_audience_list_containing_the_expected_value_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, aud=["other", _AUDIENCE]),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).client_id == _USER_CLIENT


def test_a_wrong_audience_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, aud="other-api"), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_AUDIENCE


# ------------------------------------------------------------------------ time


def test_a_valid_token_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).expires_at.tzinfo is not None


def test_an_expired_token_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, exp=int(clock()) - 600),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.EXPIRED_TOKEN


def test_a_token_inside_the_leeway_is_accepted(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, exp=int(clock()) - 29),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).subject == "user-42"


def test_a_token_exactly_on_the_leeway_boundary_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, exp=int(clock()) - 30),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.EXPIRED_TOKEN


def test_a_token_beyond_the_leeway_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, exp=int(clock()) - 31),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.EXPIRED_TOKEN


@pytest.mark.parametrize("expiry", [True, False, "123", None, [1]])
def test_a_non_numeric_expiry_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    expiry: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock, exp=expiry), rsa_signing_key, headers={"kid": RSA_KID})

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) in {
        AuthFailureReason.INVALID_CLAIM,
        AuthFailureReason.MISSING_CLAIM,
    }


def test_a_not_before_in_the_future_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, nbf=int(clock()) + 600),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.NOT_YET_VALID


def test_a_not_before_inside_the_leeway_is_accepted(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, nbf=int(clock()) + 30),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).subject == "user-42"


@pytest.mark.parametrize("not_before", [True, "123", None, [1]])
def test_a_non_numeric_not_before_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    not_before: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, nbf=not_before), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_CLAIM


def test_an_issued_at_in_the_future_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, iat=int(clock()) + 600),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.ISSUED_IN_FUTURE


def test_an_issued_at_inside_the_leeway_is_accepted(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, iat=int(clock()) + 30),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).subject == "user-42"


@pytest.mark.parametrize("issued_at", [True, "123", None, [1]])
def test_a_non_numeric_issued_at_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    issued_at: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, iat=issued_at), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_CLAIM


def test_a_lifetime_of_exactly_the_maximum_is_accepted(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=0
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, exp=int(clock()) + 3600, iat=int(clock())),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).subject == "user-42"


def test_a_lifetime_above_the_maximum_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=0
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, exp=int(clock()) + 3601, iat=int(clock())),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.LIFETIME_TOO_LONG


def test_a_lifetime_above_the_maximum_is_refused_without_an_issue_time(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=0
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, exp=int(clock()) + 3601),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.LIFETIME_TOO_LONG


def test_a_token_without_an_issue_time_is_accepted(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).subject == "user-42"


def test_a_lifetime_at_the_cap_with_an_issue_time_is_accepted(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=30
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, iat=int(clock()), exp=int(clock()) + 3600),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).subject == "user-42"


def test_a_lifetime_one_second_past_the_cap_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=30
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, iat=int(clock()), exp=int(clock()) + 3601),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.LIFETIME_TOO_LONG


def test_an_issue_time_inside_the_leeway_does_not_extend_the_cap(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=30
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, iat=int(clock()) + 5, exp=int(clock()) + 5 + 3600),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).subject == "user-42"


def test_an_issue_time_beyond_the_leeway_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=30
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, iat=int(clock()) + 31, exp=int(clock()) + 31 + 3600),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.ISSUED_IN_FUTURE


def test_a_remaining_lifetime_at_the_cap_plus_leeway_is_accepted(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=30
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, exp=int(clock()) + 3600 + 30),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).subject == "user-42"


def test_a_remaining_lifetime_past_the_cap_plus_leeway_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=30
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, exp=int(clock()) + 3600 + 31),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.LIFETIME_TOO_LONG


def test_a_long_lifetime_issued_in_the_past_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        _settings(auth_settings), max_token_ttl_seconds=3600, leeway_seconds=0
    )
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(
        _claims(clock, exp=int(clock()) + 60, iat=int(clock()) - 100000),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.LIFETIME_TOO_LONG


# ---------------------------------------------------------------------- client


def test_an_authorized_party_only_token_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).client_id == _USER_CLIENT


def test_a_client_id_only_token_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    claims = _claims(clock)
    del claims["azp"]
    claims["client_id"] = _USER_CLIENT
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(claims, rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).client_id == _USER_CLIENT


def test_matching_client_claims_authenticate(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, client_id=_USER_CLIENT),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).client_id == _USER_CLIENT


def test_mismatching_client_claims_are_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, client_id=_SERVICE_CLIENT),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.CLIENT_NOT_ALLOWED


def test_a_token_without_any_client_claim_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    claims = _claims(clock)
    del claims["azp"]
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(claims, rsa_signing_key, headers={"kid": RSA_KID})

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.CLIENT_NOT_ALLOWED


def test_an_unknown_client_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, azp="someone-else"), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.CLIENT_NOT_ALLOWED


@pytest.mark.parametrize("client", ["", 7, ["a"]])
def test_a_blank_or_mistyped_client_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    client: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock, azp=client), rsa_signing_key, headers={"kid": RSA_KID})

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.CLIENT_NOT_ALLOWED


# --------------------------------------------------------------------- subject


def test_a_valid_subject_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).subject == "user-42"


def test_a_missing_subject_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    claims = _claims(clock)
    del claims["sub"]
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(claims, rsa_signing_key, headers={"kid": RSA_KID})

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.MISSING_CLAIM


@pytest.mark.parametrize("subject", ["", 7, ["a"]])
def test_an_empty_or_mistyped_subject_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    subject: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, sub=subject), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_CLAIM


def test_an_overlong_subject_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, sub="u" * 256), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_CLAIM


# ---------------------------------------------------------------------- tenant


def test_a_valid_tenant_authenticates(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).tenant_id == _TENANT


def test_a_missing_tenant_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    claims = _claims(clock)
    del claims["tenant_id"]
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(claims, rsa_signing_key, headers={"kid": RSA_KID})

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.MISSING_CLAIM


@pytest.mark.parametrize("tenant", ["", None, 7, ["a"], "tenant 1"])
def test_an_empty_or_malformed_tenant_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    tenant: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, tenant_id=tenant), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_CLAIM


def test_a_tenant_claim_name_is_honoured(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(_settings(auth_settings), tenant_claim="org.id")
    claims = _claims(clock)
    del claims["tenant_id"]
    claims["org.id"] = _TENANT
    authenticator = _authenticator(settings, {RSA_KID: rsa_public_key}, clock)
    token = _sign(claims, rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).tenant_id == _TENANT


# ---------------------------------------------------------------------- scopes


def test_a_token_without_scopes_has_no_scopes(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).scopes == frozenset()


def test_one_scope_is_parsed(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, scope="rag:query"), rsa_signing_key, headers={"kid": RSA_KID}
    )

    assert authenticator.authenticate(token).scopes == frozenset({"rag:query"})


def test_many_scopes_are_parsed(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, scope="rag:query rag:ingest"),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).scopes == frozenset(
        {"rag:query", "rag:ingest"}
    )


def test_repeated_spaces_are_normalised(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, scope="  rag:query   rag:ingest  "),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).scopes == frozenset(
        {"rag:query", "rag:ingest"}
    )


def test_duplicate_scope_names_collapse(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, scope="rag:query rag:query"),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).scopes == frozenset({"rag:query"})


def test_an_empty_scope_string_yields_no_scopes(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock, scope=""), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).scopes == frozenset()


@pytest.mark.parametrize("scope", [["rag:query"], {"a": "b"}, 7, True, None])
def test_a_mistyped_scope_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    scope: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, scope=scope), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_CLAIM


# ------------------------------------------------------------------ delegation


@pytest.mark.parametrize("claim", ["act", "may_act"])
def test_a_delegation_claim_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    claim: str,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, **{claim: {"sub": "other"}}),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.DELEGATION_NOT_SUPPORTED


# --------------------------------------------------------------- untrusted claims


def test_untrusted_claims_never_reach_the_principal(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(
            clock,
            dataset_id="dataset-7",
            qdrant_collection="collection-7",
            neo4j_namespace="namespace-7",
            authorized_scope="dataset:write",
            access_scope_id="scope-7",
            access_scope_ids=["scope-7"],
            embedding_model="model-7",
            embedding_provider="provider-7",
            roles=["admin"],
            groups=["group-7"],
        ),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    principal = authenticator.authenticate(token)

    for forbidden in (
        "dataset_id",
        "qdrant_collection",
        "neo4j_namespace",
        "authorized_scope",
        "access_scope_id",
        "access_scope_ids",
        "embedding_model",
        "embedding_provider",
        "roles",
        "groups",
    ):
        assert not hasattr(principal, forbidden)


def test_untrusted_claims_do_not_change_the_outcome(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    plain = authenticator.authenticate(
        _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})
    )
    loaded = authenticator.authenticate(
        _sign(
            _claims(clock, roles=["admin"], scp=["dataset:write"]),
            rsa_signing_key,
            headers={"kid": RSA_KID},
        )
    )

    assert plain == loaded


# ------------------------------------------------------------------ token id


def test_a_token_without_an_identifier_has_no_token_id(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})

    assert authenticator.authenticate(token).token_id is None


def test_a_token_identifier_is_carried(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, jti="token-1"), rsa_signing_key, headers={"kid": RSA_KID}
    )

    assert authenticator.authenticate(token).token_id == "token-1"


@pytest.mark.parametrize("token_id", ["", 7, ["a"]])
def test_a_malformed_token_identifier_is_refused(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    token_id: object,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, jti=token_id), rsa_signing_key, headers={"kid": RSA_KID}
    )

    with pytest.raises(AuthenticationError) as failure:
        authenticator.authenticate(token)

    assert _reason(failure.value) is AuthFailureReason.INVALID_CLAIM


# -------------------------------------------------------------- principal kind


def test_a_user_client_produces_a_user_principal(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock), rsa_signing_key, headers={"kid": RSA_KID})
    principal = authenticator.authenticate(token)

    assert principal.kind is PrincipalKind.USER
    assert principal.principal_id == "user-42"


def test_a_service_client_produces_a_service_principal(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, azp=_SERVICE_CLIENT), rsa_signing_key, headers={"kid": RSA_KID}
    )
    principal = authenticator.authenticate(token)

    assert principal.kind is PrincipalKind.SERVICE
    assert principal.principal_id == _SERVICE_CLIENT


def test_a_token_cannot_choose_its_own_principal_kind(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(
        _claims(clock, kind="service", principal_type="service"),
        rsa_signing_key,
        headers={"kid": RSA_KID},
    )

    assert authenticator.authenticate(token).kind is PrincipalKind.USER


# --------------------------------------------------------------------- logging


def test_no_failure_logs_the_token(
    auth_settings: AuthSettings,
    rsa_signing_key: object,
    rsa_public_key: object,
    clock: ControllableClock,
    caplog: pytest.LogCaptureFixture,
) -> None:
    authenticator = _authenticator(
        _settings(auth_settings), {RSA_KID: rsa_public_key}, clock
    )
    token = _sign(_claims(clock, exp=1), rsa_signing_key, headers={"kid": RSA_KID})

    with pytest.raises(AuthenticationError):
        authenticator.authenticate(token)

    assert caplog.records == []
    assert token not in caplog.text
