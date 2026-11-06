"""Shared fixtures for the authentication tests.

Signing keys are generated at runtime. No private key material is ever
written to the repository.
"""

import base64

import pytest
from cryptography.hazmat.primitives.asymmetric import ec, rsa
from jwt import PyJWKClient, PyJWKSet
from jwt.exceptions import PyJWKClientConnectionError, PyJWKClientError

from hawki_backend.auth.jwks import PyJwkClientKeyProvider
from hawki_backend.auth.models import PrincipalKind
from hawki_backend.settings import AuthSettings

RSA_KID = "rsa-1"
EC_KID = "ec-1"


def base64url_int(value: int) -> str:
    """Encode an integer as a base64url string, the way a JWK does."""
    length = (value.bit_length() + 7) // 8
    return base64.urlsafe_b64encode(value.to_bytes(length, "big")).rstrip(b"=").decode()


def base64url_fixed(value: int, size: int) -> str:
    """Encode an integer as exactly ``size`` base64url bytes.

    RFC 7518 requires an elliptic curve coordinate to use the full length
    of the curve's field element, so a coordinate with a leading zero byte
    is padded rather than shortened. Real providers publish them this way,
    and the library rejects anything shorter.
    """
    return base64.urlsafe_b64encode(value.to_bytes(size, "big")).rstrip(b"=").decode()


class ControllableClock:
    """A monotonic clock the tests can move forward."""

    def __init__(self, start: float = 1_000.0) -> None:
        self._now = start

    def __call__(self) -> float:
        """Return the current time."""
        return self._now

    def advance(self, seconds: float) -> None:
        """Move the clock forward."""
        self._now += seconds


class FakeJwksClient(PyJWKClient):
    """A PyJWKClient whose endpoint and cache obey the test clock.

    The endpoint serves the newest document it was given and fails while
    it is unreachable. The cache mirrors the upstream contract, a fetched
    set is served until the clock moves past its lifespan and a forced
    refresh always contacts the endpoint, because the upstream cache ages
    on the real monotonic clock, which a test cannot move. The provider
    must not be able to tell this client from the real one.
    """

    def __init__(self, *, lifespan: float, clock: ControllableClock) -> None:
        """Serve a key endpoint whose cache ages on the given clock."""
        super().__init__(
            "https://issuer.example/.well-known/jwks.json",
            lifespan=lifespan,
        )
        self.cache_lifespan = lifespan
        self.clock = clock
        self.documents: list[object] = []
        self.unreachable = False
        self.fetches = 0
        self._cached: PyJWKSet | None = None
        self._cached_at: float | None = None

    def serve(self, document: object) -> None:
        """Publish a new key set document at the endpoint."""
        self.documents.append(document)

    def fetch_data(self) -> object:
        """Return the served document, or fail like an unreachable host."""
        self.fetches += 1
        if self.unreachable:
            raise PyJWKClientConnectionError("key service could not be reached")
        if not self.documents:
            raise PyJWKClientError("the endpoint has nothing to serve")
        return self._as_jwk_set_payload(self.documents[-1])

    def get_jwk_set(self, refresh: bool = False) -> PyJWKSet:
        """Serve the cached set until it ages past its lifespan."""
        now = self.clock()
        expired = self._cached is None or (
            self._cached_at is not None and now - self._cached_at > self.cache_lifespan
        )
        if refresh or expired:
            payload = self.fetch_data()
            jwk_set = (
                payload
                if isinstance(payload, PyJWKSet)
                else PyJWKSet.from_dict(payload)
            )
            self._cached = jwk_set
            self._cached_at = now
        assert self._cached is not None
        return self._cached


def key_document(*keys: dict[str, object]) -> dict[str, object]:
    """Return a JWKS document holding the given keys."""
    return {"keys": list(keys)}


@pytest.fixture(scope="session")
def rsa_signing_key() -> rsa.RSAPrivateKey:
    """Return an RSA signing key generated at runtime."""
    return rsa.generate_private_key(public_exponent=65537, key_size=2048)


@pytest.fixture(scope="session")
def rsa_public_key(rsa_signing_key: rsa.RSAPrivateKey) -> rsa.RSAPublicKey:
    """Return the public half of the RSA signing key."""
    return rsa_signing_key.public_key()


@pytest.fixture(scope="session")
def rsa_public_jwk(rsa_public_key: rsa.RSAPublicKey) -> dict[str, str]:
    """Return the RSA public key as a JWK."""
    numbers = rsa_public_key.public_numbers()
    return {
        "kty": "RSA",
        "kid": RSA_KID,
        "n": base64url_int(numbers.n),
        "e": base64url_int(numbers.e),
    }


@pytest.fixture(scope="session")
def ec_signing_key() -> ec.EllipticCurvePrivateKey:
    """Return an EC P-256 signing key generated at runtime."""
    return ec.generate_private_key(ec.SECP256R1())


@pytest.fixture(scope="session")
def ec_public_key(
    ec_signing_key: ec.EllipticCurvePrivateKey,
) -> ec.EllipticCurvePublicKey:
    """Return the public half of the EC signing key."""
    return ec_signing_key.public_key()


@pytest.fixture(scope="session")
def ec_public_jwk(ec_public_key: ec.EllipticCurvePublicKey) -> dict[str, str]:
    """Return the EC public key as a JWK."""
    numbers = ec_public_key.public_numbers()
    return {
        "kty": "EC",
        "kid": EC_KID,
        "crv": "P-256",
        "x": base64url_fixed(numbers.x, 32),
        "y": base64url_fixed(numbers.y, 32),
    }


@pytest.fixture
def clock() -> ControllableClock:
    """Return a monotonic clock the tests can advance."""
    return ControllableClock()


@pytest.fixture
def auth_settings() -> AuthSettings:
    """Return a valid authentication configuration for the key provider."""
    return AuthSettings(
        issuer="https://issuer.example",
        audience="hawki-api",
        jwks_url="https://issuer.example/.well-known/jwks.json",
        clients={"hawki-console": PrincipalKind.USER},
    )


@pytest.fixture
def jwks_client(
    auth_settings: AuthSettings, clock: ControllableClock
) -> FakeJwksClient:
    """Return a key endpoint client whose cache ages with the test clock."""
    return FakeJwksClient(
        lifespan=auth_settings.jwks_cache_ttl_seconds,
        clock=clock,
    )


@pytest.fixture
def provider(
    auth_settings: AuthSettings,
    jwks_client: FakeJwksClient,
    clock: ControllableClock,
) -> PyJwkClientKeyProvider:
    """Return a provider bound to the fake key endpoint."""
    return PyJwkClientKeyProvider(
        auth_settings,
        client=jwks_client,
        clock=clock,
    )
