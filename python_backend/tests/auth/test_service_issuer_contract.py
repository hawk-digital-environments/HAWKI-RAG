"""Verify real service issuer output using the unchanged Python JWT/JWKS boundary."""

import asyncio
import base64

import httpx
import pytest
import jwt

from hawki_backend.auth.errors import AuthenticationError, AuthFailureReason
from hawki_backend.auth.jwks import JwksKeyProvider
from hawki_backend.auth.jwt import JwtAuthenticator
from hawki_backend.auth.models import PrincipalKind
from hawki_backend.authorization import InMemoryGrantReader, LocalGrantPolicy
from hawki_backend.main import create_app
from hawki_backend.settings import AuthSettings

from .conftest import JwksTransport, key_set

_TIME = 1_793_620_800


def _mint(key: object, variant: str) -> str:
    """Sign with an ephemeral test key; no service/user identity or stored credential."""
    issued = _TIME - (120 if variant == "expired" else 0)
    claims = {
        "iss": "https://wrong.example.test"
        if variant == "issuer"
        else "https://issuer.example.test",
        "aud": "wrong-audience" if variant == "audience" else "hawki-backend",
        "sub": "service:hawki-service",
        "client_id": "unknown-client" if variant == "client" else "hawki-service",
        "tenant_id": "default",
        "iat": issued,
        "nbf": issued,
        "exp": issued + 60,
        "jti": "ephemeral-test",
        "scope": "rag:query",
    }
    if variant == "scope":
        claims.pop("scope")
    return jwt.encode(
        claims,
        key,
        algorithm="RS256",
        headers={"kid": "unknown-key" if variant == "kid" else "service-test-key"},
    )


def _verifier(jwk: dict[str, str]) -> JwtAuthenticator:
    """Resolve the public test JWK through the real HTTPS JWKS parser."""
    settings = AuthSettings(
        issuer="https://issuer.example.test",
        audience="hawki-backend",
        jwks_url="https://issuer.example.test/.well-known/jwks.json",
        clients={"hawki-service": PrincipalKind.SERVICE},
        algorithms=("RS256",),
        max_token_ttl_seconds=60,
    )
    transport = JwksTransport(
        key_set(
            {**jwk, "kid": "service-test-key", "alg": "RS256", "key_ops": ["verify"]}
        )
    )
    return JwtAuthenticator(
        settings,
        JwksKeyProvider(settings, client=transport.client()),
        clock=lambda: _TIME,
    )


def test_service_signer_token_is_accepted(rsa_signing_key, rsa_public_jwk) -> None:
    """Issuer, audience, service, tenant, client, scope, lifetime and key agree."""
    principal = _verifier(rsa_public_jwk).authenticate(_mint(rsa_signing_key, "valid"))
    assert principal.kind is PrincipalKind.SERVICE
    assert principal.principal_id == "hawki-service"
    assert principal.tenant_id == "default"
    assert principal.client_id == "hawki-service"
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
def test_service_token_mismatches_fail_closed(
    rsa_signing_key, rsa_public_jwk, variant, reason
) -> None:
    """A valid service signature cannot bypass another required JWT condition."""
    with pytest.raises(AuthenticationError) as error:
        _verifier(rsa_public_jwk).authenticate(_mint(rsa_signing_key, variant))
    assert error.value.reason is reason


def test_altered_service_signature_is_rejected(rsa_signing_key, rsa_public_jwk) -> None:
    """Changing signature bytes invalidates the token."""
    parts = _mint(rsa_signing_key, "valid").split(".")
    signature = bytearray(base64.urlsafe_b64decode(parts[2] + "=="))
    signature[0] ^= 1
    parts[2] = base64.urlsafe_b64encode(signature).rstrip(b"=").decode()
    with pytest.raises(AuthenticationError) as error:
        _verifier(rsa_public_jwk).authenticate(".".join(parts))
    assert error.value.reason is AuthFailureReason.INVALID_SIGNATURE


def test_missing_service_scope_is_rejected_at_query_boundary(
    rsa_signing_key, rsa_public_jwk
) -> None:
    """An authenticated service token still needs rag:query before any grant lookup."""
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
