"""Verify real PHP signer output using the unchanged Python JWT/JWKS boundary."""

import asyncio
import base64
import json
import shutil
import subprocess
from pathlib import Path

import httpx
import pytest
from cryptography.hazmat.primitives import serialization

from hawki_backend.auth.errors import AuthenticationError, AuthFailureReason
from hawki_backend.auth.jwks import JwksKeyProvider
from hawki_backend.auth.jwt import JwtAuthenticator
from hawki_backend.auth.models import PrincipalKind
from hawki_backend.authorization import InMemoryGrantReader, LocalGrantPolicy
from hawki_backend.main import create_app
from hawki_backend.settings import AuthSettings

from .conftest import JwksTransport, key_set

_ROOT = Path(__file__).resolve().parents[3]
_PHP = shutil.which("php")
_TIME = 1_793_620_800

pytestmark = pytest.mark.skipif(
    _PHP is None or not (_ROOT / "vendor/firebase/php-jwt").is_dir(),
    reason="PHP and the root Composer dependencies are required for interoperability",
)


def _mint(key: object, variant: str) -> str:
    """Send only a generated test key to the PHP subprocess through stdin."""
    pem = key.private_bytes(
        serialization.Encoding.PEM,
        serialization.PrivateFormat.PKCS8,
        serialization.NoEncryption(),
    ).decode()
    result = subprocess.run(
        [_PHP, str(_ROOT / "tests/Support/mint_query_bff_token.php")],
        input=json.dumps({"private_pem": pem, "variant": variant, "time": _TIME}),
        capture_output=True,
        text=True,
        check=True,
        timeout=15,
    )
    return result.stdout


def _verifier(jwk: dict[str, str]) -> JwtAuthenticator:
    """Resolve the public test JWK through the real HTTPS JWKS parser."""
    settings = AuthSettings(
        issuer="https://bff.example.test",
        audience="hawki-backend",
        jwks_url="https://bff.example.test/.well-known/jwks.json",
        clients={"hawki-bff": PrincipalKind.USER},
        algorithms=("RS256",),
        max_token_ttl_seconds=60,
    )
    transport = JwksTransport(
        key_set({**jwk, "kid": "test-bff-key", "alg": "RS256", "key_ops": ["verify"]})
    )
    return JwtAuthenticator(
        settings,
        JwksKeyProvider(settings, client=transport.client()),
        clock=lambda: _TIME,
    )


def test_php_signer_token_is_accepted(rsa_signing_key, rsa_public_jwk) -> None:
    """Issuer, audience, user, tenant, client, scope, lifetime and key agree."""
    principal = _verifier(rsa_public_jwk).authenticate(_mint(rsa_signing_key, "valid"))
    assert principal.kind is PrincipalKind.USER
    assert principal.principal_id == "42"
    assert principal.tenant_id == "default"
    assert principal.client_id == "hawki-bff"
    assert principal.scopes == frozenset({"rag:query"})
    assert principal.token_id
    assert principal.expires_at.timestamp() == _TIME + 60


@pytest.mark.parametrize(
    ("variant", "reason"),
    [
        ("issuer", AuthFailureReason.INVALID_ISSUER),
        ("audience", AuthFailureReason.INVALID_AUDIENCE),
        ("client", AuthFailureReason.CLIENT_NOT_ALLOWED),
        ("expired", AuthFailureReason.EXPIRED_TOKEN),
        ("kid", AuthFailureReason.UNKNOWN_KEY),
    ],
)
def test_php_token_mismatches_fail_closed(
    rsa_signing_key, rsa_public_jwk, variant, reason
) -> None:
    """A valid PHP signature cannot bypass another required JWT condition."""
    with pytest.raises(AuthenticationError) as error:
        _verifier(rsa_public_jwk).authenticate(_mint(rsa_signing_key, variant))
    assert error.value.reason is reason


def test_altered_php_signature_is_rejected(rsa_signing_key, rsa_public_jwk) -> None:
    """Changing signature bytes invalidates the token."""
    parts = _mint(rsa_signing_key, "valid").split(".")
    signature = bytearray(base64.urlsafe_b64decode(parts[2] + "=="))
    signature[0] ^= 1
    parts[2] = base64.urlsafe_b64encode(signature).rstrip(b"=").decode()
    with pytest.raises(AuthenticationError) as error:
        _verifier(rsa_public_jwk).authenticate(".".join(parts))
    assert error.value.reason is AuthFailureReason.INVALID_SIGNATURE


def test_missing_php_scope_is_rejected_at_query_boundary(
    rsa_signing_key, rsa_public_jwk
) -> None:
    """An authenticated PHP token still needs rag:query before any grant lookup."""
    app = create_app(
        authenticator=_verifier(rsa_public_jwk),
        policy=LocalGrantPolicy(InMemoryGrantReader([])),
    )
    token = _mint(rsa_signing_key, "scope")

    async def request() -> httpx.Response:
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=app), base_url="http://testserver"
        ) as client:
            return await client.post(
                "/v1/query",
                json={"dataset_id": "dataset-a", "query": "Question"},
                headers={"Authorization": f"Bearer {token}"},
            )

    response = asyncio.run(request())
    assert response.status_code == 403
    assert response.json() == {"detail": "insufficient_scope"}
