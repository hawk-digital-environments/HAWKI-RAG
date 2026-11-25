"""Signing key sources for the backend's JWT authentication boundary.

The authenticator asks a `KeyProvider` for a signing key by key id and
algorithm. It never learns whether the key came from static configuration or
from a remote JWKS endpoint, and it never learns a URL it could fetch.

The remote provider, `PyJwkClientKeyProvider`, is a thin adapter around
PyJWT's `PyJWKClient`. The library owns the transport, the JWK parsing and
the key set cache, including hardening the backend used to carry itself:
only http and https URLs are fetched, redirects are refused, and a failed
fetch never wipes a usable cache.

The adapter exists because the library cannot express three promises the
authentication boundary makes elsewhere:

* a key the authoritative key set does not hold is a refused token, while
  a key set that could not be obtained leaves the answer unknown, and the
  two are never confused;
* a key service outage shorter than `jwks_max_stale_seconds` does not
  lock every caller out, because the last usable key set is still served;
* an unknown key id drives at most one refresh attempt per
  `jwks_min_refresh_seconds`, so an outage is not contacted per request.

Moving onto the library consciously gave up the response size cap, the
`key_ops` filter and the duplicate key id check. The algorithm allowlist,
the header hardening in `jwt.py` and the key type checks below carry the
verification guarantees that remain.
"""

import threading
import time
from collections.abc import Callable, Container, Iterable, Mapping
from typing import Protocol, runtime_checkable

from cryptography.hazmat.primitives.asymmetric import ec, rsa
from jwt import PyJWKClient, PyJWKSet
from jwt.exceptions import PyJWTError

from hawki_backend.auth.errors import AuthenticationUnavailableError
from hawki_backend.settings import AuthSettings

SigningKey = rsa.RSAPublicKey | ec.EllipticCurvePublicKey

_RSA_ALGORITHMS = frozenset({"RS256", "PS256"})
_EC_ALGORITHMS = frozenset({"ES256"})
_SUPPORTED_ALGORITHMS = _RSA_ALGORITHMS | _EC_ALGORITHMS

_MIN_RSA_KEY_SIZE = 2048


@runtime_checkable
class KeyProvider(Protocol):
    """A source of the signing key used to verify a token."""

    def get_key(self, kid: str, alg: str) -> SigningKey | None:
        """Return the signing key for a key id and algorithm.

        Returns:
            The key, or ``None`` when no key matches the request.

        Raises:
            AuthenticationUnavailableError: When the key source could not be
                reached and no usable key set is cached.
        """
        ...


class StaticKeyProvider:
    """Serve a fixed set of signing keys.

    The provider is deterministic and never touches the network. Keys are
    supplied by the caller, so a key that cannot sign with an approved
    algorithm is rejected when the provider is built rather than silently
    ignored later.
    """

    def __init__(
        self,
        keys: Mapping[str, SigningKey],
        algorithms: Iterable[str] = _SUPPORTED_ALGORITHMS,
    ) -> None:
        """Map key identifiers to the public keys that sign with them.

        Args:
            keys: The public keys to serve, keyed by their key identifier.
            algorithms: The algorithms this provider may serve. The set is
                intersected with the approved algorithms, never widened.
        """
        allowed = frozenset(algorithms) & _SUPPORTED_ALGORITHMS
        index: dict[str, dict[str, SigningKey]] = {}
        for kid, key in keys.items():
            if not isinstance(kid, str) or not kid:
                raise ValueError("a key identifier must be a non-empty string")
            compatible = _algorithms_for_key(key) & allowed
            if not compatible:
                raise ValueError(f"key {kid!r} cannot sign with an approved algorithm")
            index[kid] = {algorithm: key for algorithm in compatible}
        self._keys = index

    def get_key(self, kid: str, alg: str) -> SigningKey | None:
        """Return the signing key for a key id and algorithm, or None."""
        return self._keys.get(kid, {}).get(alg)


class PyJwkClientKeyProvider:
    """Serve signing keys from a remote JWKS endpoint through PyJWKClient.

    The library fetches, parses and caches the key set. This provider
    surrounds it with the failure semantics the boundary promises: an
    authoritative set that does not hold a key is a refusal, a set that
    could not be obtained or understood is an outage, and an outage
    serves the last usable set until the stale window closes.
    """

    def __init__(
        self,
        settings: AuthSettings,
        *,
        client: PyJWKClient | None = None,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        """Bind the provider to a validated configuration.

        Args:
            settings: The validated authentication configuration. Only
                `settings.jwks_url` is ever fetched.
            client: The client to fetch key sets with. When it is not
                supplied one is built from the settings, and the provider
                owns it for its lifetime.
            clock: A monotonic clock used to age the fallback key set and
                to throttle refreshes. It must be monotonic and must not
                be the wall clock used for token timestamps.
        """
        self._settings = settings
        self._clock = clock
        self._client = (
            client
            if client is not None
            else PyJWKClient(
                settings.jwks_url,
                cache_jwk_set=True,
                lifespan=settings.jwks_cache_ttl_seconds,
                timeout=settings.jwks_timeout_seconds,
            )
        )
        self._lock = threading.Lock()
        self._usable_set: PyJWKSet | None = None
        self._usable_at: float | None = None
        self._forced_at: float | None = None

    def get_key(self, kid: str, alg: str) -> SigningKey | None:
        """Return the signing key for a key id and algorithm.

        Lookups are serialized, like the library's own signing key
        lookup. A fetch is rare, so the lock is held across one.

        Returns:
            The key, or ``None`` when the authoritative key set was
            obtained and does not hold the key.

        Raises:
            AuthenticationUnavailableError: When the key set could not be
                obtained or understood and no usable key set is cached.
        """
        with self._lock:
            now = self._clock()
            try:
                jwk_set = self._client.get_jwk_set()
            except PyJWTError:
                # The library folds every fetch failure into PyJWTError:
                # an unreachable host, a refused redirect, a document that
                # is not a key set. None of them says anything about the
                # keys, so none of them may refuse a token.
                return self._serve_usable(kid, alg, now)
            self._remember(jwk_set, now)
            key = _select(jwk_set, kid, alg, self._settings.algorithms)
            if key is not None:
                return key
            refreshed = self._force_refresh(now)
            if refreshed is None:
                raise AuthenticationUnavailableError() from None
            return _select(refreshed, kid, alg, self._settings.algorithms)

    def close(self) -> None:
        """Release the resources the provider owns.

        The client fetches with urllib and owns nothing to release, so
        closing the provider is deliberately a no-op. It keeps the
        ownership contract the application lifespan relies on.
        """

    def _force_refresh(self, now: float) -> PyJWKSet | None:
        """Refresh the key set for an unknown key id, throttled.

        Returns:
            The authoritative key set, or ``None`` when it could not be
            established. Inside the throttle window the newest set a
            fetch produced is returned again, so a key it does not hold
            is a refusal rather than an unknown; a window in which every
            fetch failed returns ``None``, because nothing is known.
        """
        if (
            self._forced_at is not None
            and now - self._forced_at < self._settings.jwks_min_refresh_seconds
        ):
            if self._usable_at is not None and self._usable_at >= self._forced_at:
                return self._usable_set
            return None
        self._forced_at = now
        try:
            jwk_set = self._client.get_jwk_set(refresh=True)
        except PyJWTError:
            return None
        self._remember(jwk_set, now)
        return jwk_set

    def _serve_usable(self, kid: str, alg: str, now: float) -> SigningKey | None:
        """Serve a key from the last usable set while the service is down.

        Returns:
            A key the last usable set holds. A key it does not hold is
            not a refusal: the authoritative set could not be obtained,
            so the answer for that key is unknown rather than no.
        """
        jwk_set = self._usable_set
        if jwk_set is not None and self._usable_at is not None:
            age = now - self._usable_at
            if age < self._settings.jwks_cache_ttl_seconds or (
                age < self._settings.jwks_max_stale_seconds
            ):
                key = _select(jwk_set, kid, alg, self._settings.algorithms)
                if key is not None:
                    return key
        raise AuthenticationUnavailableError() from None

    def _remember(self, jwk_set: PyJWKSet, now: float) -> None:
        """Keep the newest key set the client actually fetched.

        The library serves a cached set as the same object until the next
        fetch, so object identity tells a fetched set from a served one.
        """
        if jwk_set is not self._usable_set:
            self._usable_set = jwk_set
            self._usable_at = now


def _algorithms_for_key(key: object) -> frozenset[str]:
    """Return the approved algorithms a public key can sign with.

    A key that is too weak to be trusted is not offered for any algorithm.
    """
    if isinstance(key, rsa.RSAPublicKey):
        if key.key_size < _MIN_RSA_KEY_SIZE:
            return frozenset()
        return _RSA_ALGORITHMS
    if isinstance(key, ec.EllipticCurvePublicKey) and isinstance(
        key.curve, ec.SECP256R1
    ):
        return _EC_ALGORITHMS
    return frozenset()


def _select(
    jwk_set: PyJWKSet,
    kid: str,
    alg: str,
    allowed: Container[str],
) -> SigningKey | None:
    """Return the public key a key set offers for a key id and algorithm.

    The first signing entry that names the key id decides. A symmetric
    key, a weak RSA key, a curve other than P-256 and an algorithm the
    key cannot sign with all mean no key, and so does an algorithm the
    configuration does not allow.
    """
    if alg not in allowed:
        return None
    for jwk in jwk_set.keys:
        if jwk.key_id != kid:
            continue
        if jwk.public_key_use not in (None, "sig"):
            continue
        if alg in _algorithms_for_key(jwk.key):
            return jwk.key
        return None
    return None


__all__ = [
    "KeyProvider",
    "PyJwkClientKeyProvider",
    "SigningKey",
    "StaticKeyProvider",
]
