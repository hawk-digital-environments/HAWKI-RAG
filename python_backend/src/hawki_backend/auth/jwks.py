"""Signing key sources for the backend's JWT authentication boundary.

The authenticator asks a `KeyProvider` for a signing key by key id and
algorithm. It never learns whether the key came from static configuration or
from a remote JWKS endpoint, and it never learns a URL it could fetch.

`JwksKeyProvider` only ever contacts the URL held in `AuthSettings`. Key
locations carried inside a token are never followed, because a token is not
a trusted source of URLs.
"""

import base64
import binascii
import json
import threading
import time
from collections.abc import Callable, Iterable, Mapping
from dataclasses import dataclass
from typing import Protocol, runtime_checkable

import httpx
from cryptography.hazmat.primitives.asymmetric import ec, rsa

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


@dataclass(frozen=True, slots=True)
class _UsableKey:
    """A signing key that survived filtering, and the algorithms it serves."""

    kid: str
    key: SigningKey
    algorithms: frozenset[str]


class _RefreshFailed(Exception):
    """Internal signal that a refresh did not produce a usable key set."""


class JwksKeyProvider:
    """Serve signing keys from a remote JWKS endpoint.

    The last usable key set is kept in memory. It is refreshed when it
    expires and when a request names a key id the provider does not know.
    A refresh that fails leaves the cached keys untouched, so a temporary
    outage of the key service keeps the backend working until the stale
    window closes.
    """

    def __init__(
        self,
        settings: AuthSettings,
        *,
        client: httpx.Client | None = None,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        """Bind the provider to a validated configuration.

        Args:
            settings: The validated authentication configuration. Only
                `settings.jwks_url` is ever fetched.
            client: An HTTP client to use. When it is not supplied the
                provider creates one and owns it.
            clock: A monotonic clock used to age the cache. It must be
                monotonic and must not be the wall clock used for token
                timestamps.
        """
        self._settings = settings
        self._algorithms = frozenset(settings.algorithms)
        self._clock = clock
        self._owns_client = client is None
        self._client = (
            client
            if client is not None
            else httpx.Client(timeout=settings.jwks_timeout_seconds)
        )
        self._lock = threading.Lock()
        self._refresh_lock = threading.Lock()
        self._keys: dict[str, dict[str, SigningKey]] = {}
        self._fetched_at: float | None = None
        self._generation = 0
        self._last_attempt_at: float | None = None
        self._last_attempt_succeeded = False

    def get_key(self, kid: str, alg: str) -> SigningKey | None:
        """Return the signing key for a key id and algorithm.

        Returns:
            The key, or ``None`` when the current authoritative key set was
            obtained and does not hold the key.

        Raises:
            AuthenticationUnavailableError: When the key set could not be
                obtained, so whether the key exists is unknown. A key that is
                already cached is still served while the key service is down.
        """
        with self._lock:
            now = self._clock()
            key = self._lookup(kid, alg)
            if key is not None and self._is_fresh(now):
                return key
            if self._is_fresh(now) and self._has_kid(kid):
                return None
            observed = self._generation
            throttled = self._recent_attempt(now)
            throttled_outcome = self._last_attempt_succeeded

        if throttled:
            # A refresh was attempted recently. Its outcome decides what may
            # be said about a key the cached set does not hold: a successful
            # attempt knows the authoritative set, a failed one does not.
            refreshed = throttled_outcome
        else:
            refreshed = self._refresh_keys(observed)

        with self._lock:
            now = self._clock()
            if not self._cache_is_usable(now):
                raise AuthenticationUnavailableError()
            key = self._lookup(kid, alg)
            if key is not None:
                return key
            if refreshed:
                return None
            raise AuthenticationUnavailableError()

    def close(self) -> None:
        """Release the HTTP client, when this provider created it."""
        if self._owns_client:
            self._client.close()

    def _lookup(self, kid: str, alg: str) -> SigningKey | None:
        """Return a cached key for a key id and algorithm."""
        return self._keys.get(kid, {}).get(alg)

    def _has_kid(self, kid: str) -> bool:
        """Return whether the key set holds the key id under any algorithm."""
        return kid in self._keys

    def _is_fresh(self, now: float) -> bool:
        """Return whether the cached key set is younger than its TTL."""
        if self._fetched_at is None:
            return False
        return (now - self._fetched_at) < self._settings.jwks_cache_ttl_seconds

    def _cache_is_usable(self, now: float) -> bool:
        """Return whether the cached key set may still be served."""
        if self._fetched_at is None or not self._keys:
            return False
        age = now - self._fetched_at
        return (
            age < self._settings.jwks_cache_ttl_seconds
            or age < self._settings.jwks_max_stale_seconds
        )

    def _recent_attempt(self, now: float) -> bool:
        """Return whether a refresh was attempted inside the minimum interval.

        Every refresh attempt is throttled this way, whether it was driven by
        an unknown key id or by the cache expiring. A key service that is
        down is therefore contacted at most once per interval, instead of
        once per request.
        """
        if self._last_attempt_at is None:
            return False
        return (now - self._last_attempt_at) < self._settings.jwks_min_refresh_seconds

    def _refresh_keys(self, observed: int) -> bool:
        """Refresh the cached key set. At most one refresh runs at a time.

        Returns:
            True when the cached key set is the current authoritative set.
        """
        if not self._refresh_lock.acquire(timeout=self._settings.jwks_timeout_seconds):
            # Another thread is still refreshing and its outcome is not known
            # yet, so the authoritative key set cannot be established. The
            # previous attempt's outcome says nothing about the key set that is
            # being fetched right now, and reusing it would report a missing
            # key as unknown while the key service is merely slow. Report the
            # state as unresolved instead.
            return False
        try:
            with self._lock:
                now = self._clock()
                if self._generation != observed:
                    return True
                if self._recent_attempt(now):
                    return self._last_attempt_succeeded
            return self._fetch_and_store()
        finally:
            self._refresh_lock.release()

    def _fetch_and_store(self) -> bool:
        """Fetch the remote key set and replace the cache when it is usable.

        The attempt is recorded whether it succeeded or failed, so a later
        request knows whether the authoritative key set is known.

        Returns:
            True when the remote key set was obtained and stored.
        """
        now = self._clock()
        keys: dict[str, dict[str, SigningKey]] | None = None
        try:
            keys = self._fetch_keys()
        except _RefreshFailed:
            keys = None
        succeeded = bool(keys)
        with self._lock:
            self._last_attempt_at = now
            self._last_attempt_succeeded = succeeded
            if keys:
                self._keys = keys
                self._fetched_at = now
                self._generation += 1
        return succeeded

    def _fetch_keys(self) -> dict[str, dict[str, SigningKey]]:
        """Fetch the remote key set and keep only the usable signing keys."""
        try:
            with self._client.stream(
                "GET",
                self._settings.jwks_url,
                timeout=self._settings.jwks_timeout_seconds,
                follow_redirects=False,
                headers={"Accept-Encoding": "identity"},
            ) as response:
                if response.status_code != 200:
                    raise _RefreshFailed(
                        f"key service returned status {response.status_code}"
                    )
                body = self._read_body(response)
        except httpx.InvalidURL as error:
            raise _RefreshFailed("key service URL is not usable") from error
        except (httpx.HTTPError, httpx.StreamError) as error:
            # StreamError covers a response whose body cannot be read, which
            # is a failed refresh rather than a server error.
            raise _RefreshFailed("key service could not be reached") from error
        return self._parse_key_set(body)

    def _read_body(self, response: httpx.Response) -> bytes:
        """Read the response body, refusing anything over the size limit.

        The bytes are read raw, so a compressed body cannot be expanded past
        the configured limit before the limit is applied.
        """
        limit = self._settings.jwks_max_bytes
        encoding = response.headers.get("content-encoding")
        if encoding is not None and encoding.strip().lower() not in ("", "identity"):
            raise _RefreshFailed("key service returned an encoded key set")
        declared = response.headers.get("content-length")
        if declared is not None:
            try:
                if int(declared) > limit:
                    raise _RefreshFailed("key set is larger than the configured limit")
            except ValueError as error:
                raise _RefreshFailed(
                    "key service sent an invalid content length"
                ) from (error)
        chunks: list[bytes] = []
        total = 0
        for chunk in response.iter_raw():
            total += len(chunk)
            if total > limit:
                raise _RefreshFailed("key set is larger than the configured limit")
            chunks.append(chunk)
        return b"".join(chunks)

    def _parse_key_set(self, body: bytes) -> dict[str, dict[str, SigningKey]]:
        """Parse a JWKS document and keep only the usable signing keys."""
        try:
            document = json.loads(body)
        except (ValueError, RecursionError) as error:
            # JSONDecodeError and UnicodeDecodeError are ValueError
            # subclasses. RecursionError comes from deeply nested input, and a
            # very large integer literal raises a plain ValueError.
            raise _RefreshFailed("key set is not valid JSON") from error
        if not isinstance(document, dict):
            raise _RefreshFailed("key set is not a JSON object")
        entries = document.get("keys")
        if not isinstance(entries, list):
            raise _RefreshFailed("key set does not hold a keys list")
        index: dict[str, dict[str, SigningKey]] = {}
        for entry in entries:
            usable = self._parse_key(entry)
            if usable is None:
                continue
            if usable.kid in index:
                raise _RefreshFailed("key set defines a duplicate key identifier")
            algorithms: dict[str, SigningKey] = {}
            for algorithm in usable.algorithms:
                algorithms[algorithm] = usable.key
            index[usable.kid] = algorithms
        return index

    def _parse_key(self, entry: object) -> _UsableKey | None:
        """Return a usable key, or None when the entry must be ignored."""
        if not isinstance(entry, dict):
            return None
        kid = entry.get("kid")
        if not isinstance(kid, str) or not kid:
            return None
        use = entry.get("use")
        if use is not None and use != "sig":
            return None
        if not self._permits_verification(entry):
            return None
        key_type = entry.get("kty")
        if key_type == "RSA":
            compatible = _RSA_ALGORITHMS
        elif key_type == "EC":
            if entry.get("crv") != "P-256":
                return None
            compatible = _EC_ALGORITHMS
        else:
            return None
        algorithms = self._declared_algorithms(entry, compatible)
        if not algorithms:
            return None
        try:
            key = _build_key(key_type, entry)
        except (ValueError, TypeError, KeyError, binascii.Error):
            return None
        if not _algorithms_for_key(key):
            return None
        return _UsableKey(kid=kid, key=key, algorithms=algorithms)

    def _permits_verification(self, entry: dict[str, object]) -> bool:
        """Return whether the key may be used to verify a signature.

        A key without `key_ops` is unconstrained. A key that declares them
        must list exactly `verify`: a public verification key performs no
        other operation, and repeated values are malformed.
        """
        if "key_ops" not in entry:
            return True
        operations = entry["key_ops"]
        if not isinstance(operations, list):
            return False
        if not all(isinstance(operation, str) for operation in operations):
            return False
        if len(set(operations)) != len(operations):
            return False
        return set(operations) == {"verify"}

    def _declared_algorithms(
        self, entry: dict[str, object], compatible: frozenset[str]
    ) -> frozenset[str]:
        """Return the algorithms a key may serve.

        A key that declares an `alg` is restricted to exactly that algorithm.
        A key without one may serve any algorithm its type supports.
        """
        if "alg" not in entry:
            return compatible & self._algorithms
        declared = entry["alg"]
        if not isinstance(declared, str):
            return frozenset()
        if declared not in self._algorithms or declared not in compatible:
            return frozenset()
        return frozenset({declared})


def _algorithms_for_key(key: SigningKey) -> frozenset[str]:
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


def _build_key(key_type: str, entry: Mapping[str, object]) -> SigningKey:
    """Build a public key from a JWK, raising ValueError for bad material."""
    if key_type == "RSA":
        return rsa.RSAPublicNumbers(
            e=_base64url_int(entry.get("e")),
            n=_base64url_int(entry.get("n")),
        ).public_key()
    return ec.EllipticCurvePublicNumbers(
        x=_base64url_int(entry.get("x")),
        y=_base64url_int(entry.get("y")),
        curve=ec.SECP256R1(),
    ).public_key()


def _base64url_int(value: object) -> int:
    """Decode a base64url encoded big endian integer."""
    if not isinstance(value, str):
        raise ValueError("expected a base64url encoded string")
    padded = value + "=" * (-len(value) % 4)
    return int.from_bytes(
        base64.b64decode(padded, altchars=b"-_", validate=True), "big"
    )


__all__ = [
    "JwksKeyProvider",
    "KeyProvider",
    "SigningKey",
    "StaticKeyProvider",
]
