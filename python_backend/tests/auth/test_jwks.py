"""Tests for the signing key sources."""

import base64
import dataclasses
import threading

import pytest
from cryptography.hazmat.primitives.asymmetric import rsa
from jwt import PyJWKClient

from hawki_backend.auth.errors import AuthenticationUnavailableError
from hawki_backend.auth.jwks import (
    KeyProvider,
    PyJwkClientKeyProvider,
    StaticKeyProvider,
)
from hawki_backend.settings import AuthSettings

from .conftest import (
    EC_KID,
    RSA_KID,
    ControllableClock,
    FakeJwksClient,
    base64url_int,
    key_document,
)


def _rsa_modulus(jwk: dict[str, str]) -> int:
    """Return the modulus a JWK carries, for comparing key material."""
    padded = jwk["n"] + "=" * (-len(jwk["n"]) % 4)
    return int.from_bytes(base64.b64decode(padded, altchars=b"-_"), "big")


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


# ------------------------------------------------------------------- serving


def test_the_provider_serves_a_valid_rsa_key(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))

    key = provider.get_key(RSA_KID, "RS256")

    assert key is not None
    assert key.public_numbers().n == _rsa_modulus(rsa_public_jwk)


def test_the_provider_serves_a_valid_ec_key(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    ec_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(ec_public_jwk))

    key = provider.get_key(EC_KID, "ES256")

    assert key is not None
    assert key.public_numbers().x == _jwk_coordinate(ec_public_jwk, "x")


def _jwk_coordinate(jwk: dict[str, str], name: str) -> int:
    padded = jwk[name] + "=" * (-len(jwk[name]) % 4)
    return int.from_bytes(base64.b64decode(padded, altchars=b"-_"), "big")


def test_a_fetched_key_set_is_served_from_the_cache(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))

    assert provider.get_key(RSA_KID, "RS256") is not None
    assert provider.get_key(RSA_KID, "RS256") is not None

    assert jwks_client.fetches == 1


def test_the_key_set_is_fetched_again_when_its_lifespan_expires(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None

    clock.advance(601)

    assert provider.get_key(RSA_KID, "RS256") is not None
    assert jwks_client.fetches == 2


# --------------------------------------------------------------- unknown keys


def test_an_unknown_kid_is_a_refusal_after_one_forced_refresh(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))

    assert provider.get_key("unknown", "RS256") is None
    assert jwks_client.fetches == 2


def test_an_unknown_kid_is_not_fetched_again_inside_the_throttle_window(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key("unknown", "RS256") is None

    assert provider.get_key("unknown", "RS256") is None
    assert jwks_client.fetches == 2


def test_an_unknown_kid_is_fetched_again_after_the_throttle_window(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key("unknown", "RS256") is None

    clock.advance(61)

    assert provider.get_key("unknown", "RS256") is None
    assert jwks_client.fetches == 3


def test_an_unknown_kid_during_an_outage_is_unknown_not_refused(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None
    jwks_client.unreachable = True
    clock.advance(601)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key("unknown", "RS256")


# ------------------------------------------------------------------ rotation


def test_a_rotated_key_is_served_without_waiting_for_the_cache(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
    ec_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None

    jwks_client.serve(key_document(rsa_public_jwk, ec_public_jwk))

    assert provider.get_key(EC_KID, "ES256") is not None
    assert provider.get_key(RSA_KID, "RS256") is not None
    assert jwks_client.fetches == 2


def test_a_removed_kid_is_refused_after_the_cache_turns_over(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
    ec_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None

    jwks_client.serve(key_document(ec_public_jwk))
    clock.advance(601)

    assert provider.get_key(RSA_KID, "RS256") is None
    assert provider.get_key(EC_KID, "ES256") is not None


# --------------------------------------------------------------- key policy


def test_an_algorithm_the_key_cannot_sign_is_refused(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))

    assert provider.get_key(RSA_KID, "ES256") is None


def test_an_algorithm_outside_the_configured_allowlist_is_refused(
    auth_settings: AuthSettings,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
) -> None:
    settings = dataclasses.replace(auth_settings, algorithms=("RS256",))
    provider = PyJwkClientKeyProvider(settings, client=jwks_client, clock=clock)
    jwks_client.serve(key_document(rsa_public_jwk))

    assert provider.get_key(RSA_KID, "PS256") is None


def test_a_symmetric_key_is_never_served(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    octet = {
        "kty": "oct",
        "kid": "oct-1",
        "k": base64.urlsafe_b64encode(b"k" * 32).rstrip(b"=").decode(),
    }
    jwks_client.serve(key_document(octet, rsa_public_jwk))

    assert provider.get_key("oct-1", "RS256") is None
    assert provider.get_key(RSA_KID, "RS256") is not None


def test_an_encryption_only_key_is_ignored(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    entry = dict(rsa_public_jwk)
    entry["use"] = "enc"
    jwks_client.serve(key_document(entry))

    assert provider.get_key(RSA_KID, "RS256") is None


def test_an_entry_without_a_kid_is_ignored(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
    ec_public_jwk: dict[str, str],
) -> None:
    nameless = dict(rsa_public_jwk)
    del nameless["kid"]
    jwks_client.serve(key_document(nameless, ec_public_jwk))

    assert provider.get_key(EC_KID, "ES256") is not None


def test_a_weak_rsa_key_is_refused(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
) -> None:
    weak = rsa.generate_private_key(public_exponent=65537, key_size=1024)
    numbers = weak.public_key().public_numbers()
    jwk = {
        "kty": "RSA",
        "kid": "weak-1",
        "n": base64url_int(numbers.n),
        "e": base64url_int(numbers.e),
    }
    jwks_client.serve(key_document(jwk))

    assert provider.get_key("weak-1", "RS256") is None


# --------------------------------------------------------- endpoint failures


def test_a_document_that_is_not_a_key_set_leaves_the_boundary_unavailable(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
) -> None:
    jwks_client.serve(["not", "an", "object"])

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_a_document_that_is_not_a_key_set_still_serves_the_stale_set(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None

    jwks_client.serve(["not", "an", "object"])
    clock.advance(601)

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_an_empty_key_set_leaves_the_boundary_unavailable(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
) -> None:
    jwks_client.serve(key_document())

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_an_unreachable_key_service_without_a_cache_leaves_the_boundary_unavailable(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    jwks_client.unreachable = True

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


# -------------------------------------------------------------- stale window


def test_stale_keys_are_served_through_an_outage(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None

    jwks_client.unreachable = True
    clock.advance(601)

    assert provider.get_key(RSA_KID, "RS256") is not None
    assert jwks_client.fetches == 2


def test_the_stale_window_closes(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None

    jwks_client.unreachable = True
    clock.advance(3601)

    with pytest.raises(AuthenticationUnavailableError):
        provider.get_key(RSA_KID, "RS256")


def test_the_boundary_recovers_after_an_outage(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None

    jwks_client.unreachable = True
    clock.advance(601)
    assert provider.get_key(RSA_KID, "RS256") is not None

    jwks_client.unreachable = False
    clock.advance(601)

    assert provider.get_key(RSA_KID, "RS256") is not None
    assert jwks_client.fetches == 3


# ------------------------------------------------------------------- wiring


def test_the_provider_satisfies_the_key_provider_protocol(
    auth_settings: AuthSettings,
) -> None:
    provider = PyJwkClientKeyProvider(auth_settings)

    assert isinstance(provider, KeyProvider)


def test_the_client_is_configured_from_the_settings(
    auth_settings: AuthSettings,
) -> None:
    provider = PyJwkClientKeyProvider(auth_settings)

    client = provider._client

    assert isinstance(client, PyJWKClient)
    assert client.uri == auth_settings.jwks_url
    assert client.timeout == auth_settings.jwks_timeout_seconds
    assert client.jwk_set_cache is not None
    assert client.jwk_set_cache.lifespan == auth_settings.jwks_cache_ttl_seconds


def test_close_owns_nothing_to_release(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    assert provider.get_key(RSA_KID, "RS256") is not None

    provider.close()

    assert provider.get_key(RSA_KID, "RS256") is not None


def test_concurrent_lookups_share_one_fetch(
    provider: PyJwkClientKeyProvider,
    jwks_client: FakeJwksClient,
    rsa_public_jwk: dict[str, str],
) -> None:
    jwks_client.serve(key_document(rsa_public_jwk))
    results: list[object] = []

    def lookup() -> None:
        results.append(provider.get_key(RSA_KID, "RS256"))

    threads = [threading.Thread(target=lookup) for _ in range(8)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()

    assert jwks_client.fetches == 1
    assert all(result is results[0] and result is not None for result in results)
