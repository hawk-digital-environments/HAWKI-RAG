"""Tests for the signing key sources."""

import dataclasses
import threading
import time

import httpx
import pytest
from cryptography.hazmat.primitives.asymmetric import rsa

from hawki_backend.auth.errors import AuthenticationUnavailableError
from hawki_backend.auth.jwks import (
    JwksKeyProvider,
    KeyProvider,
    StaticKeyProvider,
)
from hawki_backend.settings import AuthSettings

from .conftest import (
    EC_KID,
    RSA_KID,
    ControllableClock,
    JwksTransport,
    base64url_int,
    key_set,
)


def _provider(
    settings: AuthSettings,
    transport: JwksTransport,
    clock: ControllableClock | None = None,
) -> JwksKeyProvider:
    """Build a provider backed by a mock transport."""
    return JwksKeyProvider(
        settings,
        client=transport.client(),
        clock=clock if clock is not None else ControllableClock(),
    )


# ---------------------------------------------------------------- static keys


def test_static_provider_serves_a_known_rsa_key(
    rsa_public_key: object,
) -> None:
    provider = StaticKeyProvider({RSA_KID: rsa_public_key})

    assert provider.get_key(RSA_KID, "RS256") is rsa_public_key


def test_static_provider_serves_a_known_ec_key(ec_public_key: object) -> None:
    provider = StaticKeyProvider({EC_KID: ec_public_key})

    assert provider.get_key(EC_KID, "ES256") is ec_public_key


def test_static_provider_returns_none_for_an_unknown_kid(
    rsa_public_key: object,
) -> None:
    provider = StaticKeyProvider({RSA_KID: rsa_public_key})

    assert provider.get_key("unknown", "RS256") is None


def test_static_provider_returns_none_for_an_algorithm_mismatch(
    rsa_public_key: object,
) -> None:
    provider = StaticKeyProvider({RSA_KID: rsa_public_key})

    assert provider.get_key(RSA_KID, "ES256") is None


def test_static_provider_rejects_a_key_that_cannot_sign(
    ec_public_key: object,
) -> None:
    with pytest.raises(ValueError):
        StaticKeyProvider({EC_KID: ec_public_key}, algorithms=("RS256",))


def test_static_provider_rejects_an_empty_key_identifier(
    rsa_public_key: object,
) -> None:
    with pytest.raises(ValueError):
        StaticKeyProvider({"": rsa_public_key})


def test_static_provider_never_raises_an_unavailable_error(
    rsa_public_key: object,
) -> None:
    provider = StaticKeyProvider({RSA_KID: rsa_public_key})

    assert provider.get_key("unknown", "RS256") is None


# ------------------------------------------------------------------- jwks keys


def test_jwks_provider_serves_a_valid_rsa_key(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    key = provider.get_key(RSA_KID, "RS256")

    assert key is not None
    assert key.public_numbers().n == _rsa_modulus(rsa_public_jwk)


def _rsa_modulus(jwk: dict[str, str]) -> int:
    import base64

    padded = jwk["n"] + "=" * (-len(jwk["n"]) % 4)
    return int.from_bytes(base64.b64decode(padded, altchars=b"-_"), "big")


def test_jwks_provider_serves_a_valid_ec_key(
    auth_settings: AuthSettings,
    ec_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(ec_public_jwk))
    provider = _provider(auth_settings, transport)

    key = provider.get_key(EC_KID, "ES256")

    assert key is not None
    assert key.public_numbers().x == _ec_coordinate(ec_public_jwk)


def _ec_coordinate(jwk: dict[str, str]) -> int:
    import base64

    padded = jwk["x"] + "=" * (-len(jwk["x"]) % 4)
    return int.from_bytes(base64.b64decode(padded, altchars=b"-_"), "big")


def test_jwks_provider_serves_an_rsa_key_for_ps256(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    settings = dataclasses.replace(auth_settings, algorithms=("RS256", "PS256"))
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport)

    assert provider.get_key(RSA_KID, "PS256") is not None


def test_jwks_provider_rejects_malformed_json(auth_settings: AuthSettings) -> None:
    transport = JwksTransport(body=b"not json at all")
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_rejects_a_document_that_is_not_an_object(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(body=b"[]")
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_rejects_a_document_without_keys(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(body=b"{}")
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_rejects_keys_that_are_not_a_list(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(body=b'{"keys": {}}')
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_rejects_an_empty_key_set(auth_settings: AuthSettings) -> None:
    transport = JwksTransport(body=key_set())
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


# -------------------------------------------------------------- key filtering


@pytest.mark.parametrize(
    "mutation",
    [
        {"kid": None},
        {"kid": ""},
        {"kid": 7},
    ],
)
def test_jwks_provider_ignores_a_key_without_a_usable_kid(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    mutation: dict[str, object],
) -> None:
    entry = {**rsa_public_jwk, **mutation}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_ignores_an_encryption_key(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "use": "enc"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_ignores_a_key_with_encryption_operations(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "key_ops": ["encrypt"]}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_accepts_a_key_marked_for_signing(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "use": "sig"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_jwks_provider_accepts_a_key_without_a_use(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {key: value for key, value in rsa_public_jwk.items() if key != "use"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_jwks_provider_ignores_an_unsupported_key_type(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "kty": "OKP"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_ignores_a_key_of_the_wrong_type_for_the_algorithm(
    auth_settings: AuthSettings,
    ec_public_jwk: dict[str, str],
) -> None:
    settings = dataclasses.replace(auth_settings, algorithms=("RS256",))
    transport = JwksTransport(body=key_set(ec_public_jwk))
    provider = _provider(settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(EC_KID, "RS256")


def test_jwks_provider_ignores_an_unsupported_curve(
    auth_settings: AuthSettings,
    ec_public_jwk: dict[str, str],
) -> None:
    entry = {**ec_public_jwk, "crv": "P-384"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(EC_KID, "ES256")


def test_jwks_provider_ignores_malformed_rsa_material(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "n": "not base64 at all!!"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_ignores_malformed_ec_material(
    auth_settings: AuthSettings,
    ec_public_jwk: dict[str, str],
) -> None:
    entry = {**ec_public_jwk, "x": "AQ"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(EC_KID, "ES256")


def test_jwks_provider_ignores_an_ec_point_that_is_not_on_the_curve(
    auth_settings: AuthSettings,
    ec_public_jwk: dict[str, str],
) -> None:
    entry = {**ec_public_jwk, "x": "AQ", "y": "AQ"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(EC_KID, "ES256")


# ------------------------------------------------------------------- networking


def test_jwks_provider_reports_a_connection_error_as_unavailable(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(error=httpx.ConnectError("connection refused"))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_reports_a_timeout_as_unavailable(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(error=httpx.TimeoutException("timed out"))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_reports_an_error_response_as_unavailable(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(body=b"boom", status_code=500)
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_rejects_a_response_over_the_size_limit(
    auth_settings: AuthSettings,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_max_bytes=64)
    transport = JwksTransport(body=b"x" * 512)
    provider = _provider(settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_caps_a_streamed_response_without_a_content_length(
    auth_settings: AuthSettings,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_max_bytes=64)
    transport = JwksTransport(body=b"x" * 512, omit_content_length=True)
    provider = _provider(settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_caps_a_response_that_lies_about_its_length(
    auth_settings: AuthSettings,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_max_bytes=64)
    transport = JwksTransport(body=b"x" * 512, content_length=8)
    provider = _provider(settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_rejects_a_compressed_response(
    auth_settings: AuthSettings,
) -> None:
    import gzip

    settings = dataclasses.replace(auth_settings, jwks_max_bytes=64)
    transport = JwksTransport(
        body=gzip.compress(b"x" * 512), headers={"content-encoding": "gzip"}
    )
    provider = _provider(settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_jwks_provider_asks_for_an_uncompressed_response(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    provider.get_key(RSA_KID, "RS256")

    assert transport.requests[0].headers["accept-encoding"] == "identity"


@pytest.mark.parametrize(
    "body",
    [
        b"[" * 100000 + b"]" * 100000,
        b'{"keys":[],"n":' + b"1" * 5000 + b"}",
    ],
)
def test_jwks_provider_rejects_a_hostile_json_document(
    auth_settings: AuthSettings,
    body: bytes,
) -> None:
    transport = JwksTransport(body=body)
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_previous_good_cache_survives_a_hostile_json_document(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings,
        jwks_cache_ttl_seconds=10,
        jwks_min_refresh_seconds=60,
        jwks_max_stale_seconds=600,
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")

    clock.advance(61)
    transport.body = b"[" * 100000 + b"]" * 100000

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_jwks_provider_does_not_follow_a_redirect(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(
        body=b"",
        status_code=302,
        headers={"location": "https://elsewhere.example/keys"},
    )
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 1


def test_jwks_provider_only_fetches_the_configured_url(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    provider.get_key(RSA_KID, "RS256")

    assert [str(request.url) for request in transport.requests] == [
        auth_settings.jwks_url
    ]


# ----------------------------------------------------------------------- cache


def test_the_first_request_fetches_the_key_set(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 1


def test_a_fresh_cache_is_served_without_a_request(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    provider.get_key(RSA_KID, "RS256")
    provider.get_key(RSA_KID, "RS256")
    provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 1


def test_an_expired_cache_triggers_a_refresh(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings, jwks_cache_ttl_seconds=10, jwks_min_refresh_seconds=1
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")
    clock.advance(11)
    provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 2


def test_a_refresh_waits_for_the_minimum_interval_after_the_last_attempt(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings, jwks_cache_ttl_seconds=10, jwks_min_refresh_seconds=60
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")
    clock.advance(11)
    provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 1

    clock.advance(60)
    provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 2


def test_an_unknown_kid_triggers_a_refresh(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    provider.get_key("rotated", "RS256")

    assert transport.request_count == 2


def test_an_unknown_kid_is_still_unknown_after_a_refresh(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)

    assert provider.get_key("rotated", "RS256") is None


def test_a_successful_attempt_blocks_an_unknown_kid_lookup(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")

    assert provider.get_key("rotated", "RS256") is None
    assert transport.request_count == 1


def test_unknown_kid_requests_inside_the_cooldown_do_not_flood(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    provider.get_key("rotated", "RS256")
    for _ in range(5):
        clock.advance(1)
        provider.get_key("rotated", "RS256")

    assert transport.request_count == 2


def test_a_cold_start_outage_makes_a_bounded_number_of_requests(
    auth_settings: AuthSettings,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(error=httpx.ConnectError("connection refused"))
    provider = _provider(settings, transport, clock)

    for _ in range(10):
        with pytest.raises(AuthenticationUnavailableError):
            provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 1


def test_an_expired_cache_outage_makes_a_bounded_number_of_requests(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings,
        jwks_cache_ttl_seconds=10,
        jwks_min_refresh_seconds=60,
        jwks_max_stale_seconds=600,
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")

    clock.advance(61)
    transport.error = httpx.ConnectError("connection refused")
    for _ in range(10):
        clock.advance(1)
        assert provider.get_key(RSA_KID, "RS256") is not None

    assert transport.request_count == 2


def test_an_unknown_kid_outage_makes_a_bounded_number_of_requests(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings,
        jwks_min_refresh_seconds=60,
        jwks_max_stale_seconds=600,
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")

    clock.advance(61)
    transport.error = httpx.ConnectError("connection refused")
    for _ in range(10):
        clock.advance(1)
        with pytest.raises(AuthenticationUnavailableError):
            provider.get_key("rotated", "RS256")

    assert transport.request_count == 2


def test_a_failed_attempt_blocks_a_retry(
    auth_settings: AuthSettings,
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(error=httpx.ConnectError("connection refused"))
    provider = _provider(settings, transport, clock)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 1

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")

    assert transport.request_count == 1


def test_a_successful_refresh_clears_an_earlier_unavailable_state(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings,
        jwks_cache_ttl_seconds=100,
        jwks_min_refresh_seconds=300,
        jwks_max_stale_seconds=3600,
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")

    clock.advance(301)
    transport.error = httpx.ConnectError("connection refused")
    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("rotated", "RS256")

    clock.advance(300)
    transport.error = None
    provider.get_key(RSA_KID, "RS256")

    clock.advance(1)
    assert provider.get_key("another", "RS256") is None


def test_a_stale_usable_cache_survives_a_temporary_failure(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings,
        jwks_cache_ttl_seconds=10,
        jwks_min_refresh_seconds=60,
        jwks_max_stale_seconds=600,
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    transport.error = httpx.ConnectError("connection refused")

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_a_cache_older_than_the_stale_window_fails_unavailable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings,
        jwks_cache_ttl_seconds=10,
        jwks_min_refresh_seconds=60,
        jwks_max_stale_seconds=20,
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    transport.error = httpx.ConnectError("connection refused")

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_an_unusable_refresh_does_not_replace_a_good_cache(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings,
        jwks_cache_ttl_seconds=10,
        jwks_min_refresh_seconds=60,
        jwks_max_stale_seconds=600,
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    transport.body = key_set({"kty": "RSA", "kid": "other"})

    assert provider.get_key(RSA_KID, "RS256") is not None


# ----------------------------------------------------------------- concurrency


def test_a_lock_timeout_leaves_the_key_state_unresolved(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings,
        jwks_timeout_seconds=0.2,
        jwks_min_refresh_seconds=60,
        jwks_cache_ttl_seconds=10,
        jwks_max_stale_seconds=3600,
    )
    in_flight = threading.Event()
    release = threading.Event()
    holder_finished = threading.Event()

    class BlockingTransport(JwksTransport):
        block = False

        def __call__(self, request: httpx.Request) -> httpx.Response:
            if self.block:
                in_flight.set()
                release.wait(timeout=30)
            return super().__call__(request)

    transport = BlockingTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)

    # A previous refresh succeeded, so the last attempt's outcome is a
    # success. The key set in flight below is a different, unknown one.
    provider.get_key(RSA_KID, "RS256")
    assert transport.request_count == 1

    # Past the cache lifetime and past the minimum refresh interval, so the
    # cached key set is eligible but stale.
    clock.advance(61)
    transport.block = True
    holder_result: list[object] = []

    def holder() -> None:
        try:
            holder_result.append(provider.get_key("rotated", "RS256"))
        finally:
            holder_finished.set()

    thread = threading.Thread(target=holder)
    thread.start()
    assert in_flight.wait(timeout=10)
    assert thread.is_alive()

    # The waiter cannot get the refresh lock, so the authoritative key set is
    # unknown and a key that is not cached must not be reported as unknown.
    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("another", "RS256")

    # A key that is already cached is still served from the stale key set.
    assert provider.get_key(RSA_KID, "RS256") is not None

    release.set()
    assert holder_finished.wait(timeout=10)
    thread.join(timeout=10)
    assert not thread.is_alive()
    assert holder_result == [None]


def test_two_simultaneous_refreshes_result_in_one_request(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    started = threading.Barrier(2)
    release = threading.Event()

    class SlowTransport(JwksTransport):
        def __call__(self, request: httpx.Request) -> httpx.Response:
            release.set()
            time.sleep(0.05)
            return super().__call__(request)

    transport = SlowTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)
    results: list[object] = []

    def worker() -> None:
        started.wait(timeout=5)
        results.append(provider.get_key(RSA_KID, "RS256"))

    threads = [threading.Thread(target=worker) for _ in range(2)]
    for thread in threads:
        thread.start()
    release.wait(timeout=5)
    for thread in threads:
        thread.join(timeout=5)

    assert len(results) == 2
    assert all(result is not None for result in results)
    assert transport.request_count == 1


def test_two_simultaneous_unknown_kid_requests_result_in_one_refresh(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    started = threading.Barrier(2)

    class SlowTransport(JwksTransport):
        def __call__(self, request: httpx.Request) -> httpx.Response:
            time.sleep(0.05)
            return super().__call__(request)

    transport = SlowTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    results: list[object] = []

    def worker() -> None:
        started.wait(timeout=5)
        results.append(provider.get_key("rotated", "RS256"))

    threads = [threading.Thread(target=worker) for _ in range(2)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join(timeout=5)

    assert len(results) == 2
    assert all(result is None for result in results)
    assert transport.request_count == 2


# ------------------------------------------- unknown kid against a failed refresh


def test_an_unknown_kid_with_a_network_failure_is_unavailable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    transport.error = httpx.ConnectError("connection refused")

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("other", "RS256")


def test_an_unknown_kid_with_a_malformed_key_set_is_unavailable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    transport.body = b"not json at all"

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("other", "RS256")


def test_an_unknown_kid_with_zero_usable_remote_keys_is_unavailable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    transport.body = key_set({"kty": "RSA", "kid": "unusable"})

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("other", "RS256")


def test_an_unknown_kid_after_a_successful_refresh_is_none(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    transport.body = key_set(rsa_public_jwk)

    assert provider.get_key("other", "RS256") is None


def test_a_cached_kid_is_served_during_a_remote_outage(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)
    provider.get_key(RSA_KID, "RS256")
    transport.error = httpx.ConnectError("connection refused")

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_a_cooldown_from_a_failed_refresh_does_not_confirm_an_unknown_kid(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")
    clock.advance(61)
    transport.error = httpx.ConnectError("connection refused")

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("other", "RS256")

    clock.advance(1)
    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("other", "RS256")

    assert transport.request_count == 2


def test_a_cooldown_from_a_successful_refresh_still_confirms_an_unknown_kid(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(auth_settings, jwks_min_refresh_seconds=60)
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")

    clock.advance(1)

    assert provider.get_key("other", "RS256") is None
    assert transport.request_count == 1


# ------------------------------------------------------------ key_ops semantics


def test_a_key_that_permits_verification_is_usable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "key_ops": ["verify"]}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None


@pytest.mark.parametrize(
    "operations",
    [
        ["sign"],
        ["encrypt"],
        ["verify", "encrypt"],
        ["verify", "sign"],
        ["verify", "verify"],
        ["verify", "unknown-operation"],
        "verify",
        7,
        [7],
        None,
    ],
)
def test_a_key_that_cannot_verify_is_unusable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    operations: object,
) -> None:
    entry = {**rsa_public_jwk, "key_ops": operations}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_key_without_key_ops_is_usable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {key: value for key, value in rsa_public_jwk.items() if key != "key_ops"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_a_malformed_key_does_not_poison_the_rest_of_the_set(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    unusable = {**rsa_public_jwk, "kid": "unusable", "key_ops": ["sign"]}
    transport = JwksTransport(body=key_set(unusable, rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None


# --------------------------------------------------------------- jwk alg semantics


def test_an_rsa_key_without_a_declared_alg_serves_both_rsa_algorithms(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    settings = dataclasses.replace(auth_settings, algorithms=("RS256", "PS256"))
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None
    assert provider.get_key(RSA_KID, "PS256") is not None


def test_an_rsa_key_declaring_rs256_does_not_serve_ps256(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    settings = dataclasses.replace(auth_settings, algorithms=("RS256", "PS256"))
    entry = {**rsa_public_jwk, "alg": "RS256"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None
    assert provider.get_key(RSA_KID, "PS256") is None


def test_an_rsa_key_declaring_ps256_does_not_serve_rs256(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    settings = dataclasses.replace(auth_settings, algorithms=("RS256", "PS256"))
    entry = {**rsa_public_jwk, "alg": "PS256"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(settings, transport)

    assert provider.get_key(RSA_KID, "PS256") is not None
    assert provider.get_key(RSA_KID, "RS256") is None


def test_an_ec_key_declaring_es256_serves_es256(
    auth_settings: AuthSettings,
    ec_public_jwk: dict[str, str],
) -> None:
    entry = {**ec_public_jwk, "alg": "ES256"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(EC_KID, "ES256") is not None


def test_a_declared_alg_outside_the_allowlist_is_unusable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "alg": "RS512"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_malformed_declared_alg_is_unusable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "alg": 7}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_declared_alg_that_contradicts_the_key_type_is_unusable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = {**rsa_public_jwk, "alg": "ES256"}
    transport = JwksTransport(body=key_set(entry))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


# ------------------------------------------------------------ duplicate kid rules


def _other_rsa_jwk(rsa_public_jwk: dict[str, str]) -> dict[str, str]:
    """Return a second RSA key that shares the key id of the first."""
    other = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    numbers = other.public_key().public_numbers()
    return {
        **rsa_public_jwk,
        "n": base64url_int(numbers.n),
        "e": base64url_int(numbers.e),
    }


def test_a_duplicate_kid_with_no_prior_cache_is_unavailable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(
        body=key_set(rsa_public_jwk, _other_rsa_jwk(rsa_public_jwk))
    )
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_duplicate_kid_preserves_a_prior_good_cache(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings, jwks_cache_ttl_seconds=10, jwks_max_stale_seconds=600
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")

    clock.advance(11)
    transport.body = key_set(rsa_public_jwk, _other_rsa_jwk(rsa_public_jwk))

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_duplicate_kid_entries_with_different_material_are_unusable(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(
        body=key_set(rsa_public_jwk, _other_rsa_jwk(rsa_public_jwk))
    )
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_duplicate_kid_where_one_entry_is_unusable_is_accepted(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    unusable = {**rsa_public_jwk, "use": "enc"}
    transport = JwksTransport(body=key_set(unusable, rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None


# ------------------------------------------------------------- rsa strength rule


def _rsa_jwk(key_size: int, kid: str = RSA_KID) -> dict[str, str]:
    """Return a public JWK for a freshly generated RSA key of a given size."""
    key = rsa.generate_private_key(public_exponent=65537, key_size=key_size)
    numbers = key.public_key().public_numbers()
    return {
        "kty": "RSA",
        "kid": kid,
        "n": base64url_int(numbers.n),
        "e": base64url_int(numbers.e),
    }


def test_a_2048_bit_rsa_key_is_accepted(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_a_1024_bit_rsa_key_is_rejected_from_a_jwks(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(body=key_set(_rsa_jwk(1024)))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_1024_bit_rsa_key_is_rejected_by_the_static_provider() -> None:
    weak = rsa.generate_private_key(public_exponent=65537, key_size=1024)

    with pytest.raises(ValueError):
        StaticKeyProvider({RSA_KID: weak.public_key()})


def test_a_weak_rsa_key_does_not_poison_a_valid_key(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    weak = _rsa_jwk(1024, kid="weak")
    transport = JwksTransport(body=key_set(weak, rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    assert provider.get_key(RSA_KID, "RS256") is not None
    assert provider.get_key("weak", "RS256") is None


def test_a_jwks_of_only_weak_rsa_keys_counts_as_zero_usable_keys(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(body=key_set(_rsa_jwk(1024, kid="weak-a")))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("weak-a", "RS256")


def test_a_previous_good_cache_survives_a_refresh_of_only_weak_keys(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
    clock: ControllableClock,
) -> None:
    settings = dataclasses.replace(
        auth_settings, jwks_cache_ttl_seconds=10, jwks_max_stale_seconds=600
    )
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(settings, transport, clock)
    provider.get_key(RSA_KID, "RS256")

    clock.advance(11)
    transport.body = key_set(_rsa_jwk(1024, kid="weak"))

    assert provider.get_key(RSA_KID, "RS256") is not None


# --------------------------------------------------------- failure classification


def test_an_unknown_kid_is_not_an_unavailable_error(
    auth_settings: AuthSettings,
    rsa_public_jwk: dict[str, str],
) -> None:
    transport = JwksTransport(body=key_set(rsa_public_jwk))
    provider = _provider(auth_settings, transport)

    provider.get_key(RSA_KID, "RS256")

    assert provider.get_key("unknown", "RS256") is None


def test_a_missing_cache_with_a_network_failure_is_unavailable(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(error=httpx.ConnectError("connection refused"))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_missing_cache_with_an_invalid_key_set_is_unavailable(
    auth_settings: AuthSettings,
) -> None:
    transport = JwksTransport(body=key_set({"kty": "RSA", "kid": "no-material"}))
    provider = _provider(auth_settings, transport)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_both_providers_satisfy_the_key_provider_protocol(
    rsa_public_key: object,
) -> None:
    provider = StaticKeyProvider({RSA_KID: rsa_public_key})

    assert isinstance(provider, KeyProvider)
