"""Real asymmetric HAWKI-style service JWTs at the active API boundary."""

import asyncio
from dataclasses import replace

import httpx
import jwt
import pytest

from hawki_backend.main import create_app
from hawki_backend.auth.models import PrincipalKind
from hawki_backend.auth.jwks import StaticKeyProvider
from hawki_backend.auth.jwt import JwtAuthenticator
from .conftest import RSA_KID


@pytest.mark.parametrize(
    "change,expected",
    [
        ({}, 200),
        ({"aud": "wrong"}, 401),
        ({"tenant_id": ""}, 401),
        ({"scope": "rag:query"}, 403),
        ({"azp": "legacy-user"}, 401),
    ],
)
def test_hawki_service_jwt_preserves_crypto_tenant_and_scope_checks(
    monkeypatch, auth_settings, rsa_signing_key, rsa_public_key, clock, change, expected
):
    monkeypatch.setenv("HAWKI_BACKEND_SERVICE_ONLY", "true")
    settings = replace(
        auth_settings,
        clients={
            "hawki-service": PrincipalKind.SERVICE,
            "legacy-user": PrincipalKind.USER,
        },
    )
    authenticator = JwtAuthenticator(
        settings, StaticKeyProvider({RSA_KID: rsa_public_key}), clock=clock
    )
    application = create_app(authenticator=authenticator)
    claims = {
        "iss": settings.issuer,
        "aud": settings.audience,
        "sub": "hawki-instance",
        "azp": "hawki-service",
        "tenant_id": "tenant-1",
        "scope": "rag:datasets:manage",
        "iat": int(clock()),
        "exp": int(clock()) + 300,
        **change,
    }
    token = jwt.encode(
        claims, rsa_signing_key, algorithm="RS256", headers={"kid": RSA_KID}
    )

    async def call():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=application), base_url="http://test"
        ) as client:
            response = await client.get(
                "/v1/readiness/identity", headers={"Authorization": "Bearer " + token}
            )
            assert response.status_code == expected
            assert token not in response.text
            assert (
                await client.post(
                    "/v1/query", json={"dataset_id": "example", "query": "hello"}
                )
            ).status_code == 401

    asyncio.run(call())
