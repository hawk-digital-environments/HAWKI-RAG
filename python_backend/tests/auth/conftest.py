"""Shared fixtures for the authentication tests.

Signing keys are generated at runtime. No private key material is ever
written to the repository.
"""

import base64
import json
from collections.abc import Mapping

import httpx
import pytest
from cryptography.hazmat.primitives.asymmetric import ec, rsa

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.settings import AuthSettings

RSA_KID = "rsa-1"
EC_KID = "ec-1"


def base64url_int(value: int) -> str:
    """Encode an integer as a base64url string, the way a JWK does."""
    length = (value.bit_length() + 7) // 8
    return base64.urlsafe_b64encode(value.to_bytes(length, "big")).rstrip(b"=").decode()


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


class JwksTransport:
    """Serve a canned key set response and record every request."""

    def __init__(
        self,
        body: bytes = b"",
        status_code: int = 200,
        headers: Mapping[str, str] | None = None,
        error: Exception | None = None,
        content_length: int | None = None,
        omit_content_length: bool = False,
    ) -> None:
        self.body = body
        self.status_code = status_code
        self.headers = dict(headers or {})
        self.error = error
        self.content_length = content_length
        self.omit_content_length = omit_content_length
        self.requests: list[httpx.Request] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        """Return the canned response, or raise the configured error."""
        self.requests.append(request)
        if self.error is not None:
            raise self.error
        headers = dict(self.headers)
        if not self.omit_content_length:
            length = (
                self.content_length
                if self.content_length is not None
                else len(self.body)
            )
            headers["content-length"] = str(length)
        # A streamed response keeps its raw bytes readable, which is what a
        # real key service connection looks like.
        return httpx.Response(
            self.status_code,
            stream=httpx.ByteStream(self.body),
            headers=headers,
            request=request,
        )

    @property
    def request_count(self) -> int:
        """Return how often the endpoint was contacted."""
        return len(self.requests)

    def client(self) -> httpx.Client:
        """Return an HTTP client that talks to this transport."""
        return httpx.Client(transport=httpx.MockTransport(self))


def key_set(*keys: Mapping[str, object]) -> bytes:
    """Return a JWKS document holding the given keys."""
    return json.dumps({"keys": list(keys)}).encode()


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
        "x": base64url_int(numbers.x),
        "y": base64url_int(numbers.y),
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
