"""The explicit local helper issues only bounded service credentials."""

import asyncio
import hashlib
import importlib.util
import json
from pathlib import Path

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa
import httpx
import jwt
import pytest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "rawki_development_issuer", ROOT / "docker/local_auth/server.py"
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


@pytest.fixture
def issuer(tmp_path, monkeypatch):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    (tmp_path / "signing.pem").write_bytes(
        key.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        )
    )
    public = jwt.algorithms.RSAAlgorithm.to_jwk(key.public_key(), as_dict=True)
    public.update(kid="test-key", alg="RS256", use="sig")
    (tmp_path / "jwks.json").write_text(json.dumps({"keys": [public]}))
    (tmp_path / "issuer.json").write_text(
        json.dumps(
            {
                "issuer": module.ISSUER,
                "audience": "hawki-backend",
                "kid": "test-key",
                "operator": {
                    "username": "local-management",
                    "sha256": hashlib.sha256(b"unit-operator").hexdigest(),
                },
                "clients": {
                    "rag-management": {
                        "sha256": hashlib.sha256(b"unit-service").hexdigest(),
                        "tenant_id": "default",
                        "scopes": ["rag:query"],
                    }
                },
            }
        )
    )
    monkeypatch.setenv("RAWKI_LOCAL_AUTH_ENABLED", "true")
    monkeypatch.setenv("RAWKI_LOCAL_AUTH_DIRECTORY", str(tmp_path))
    return key


def request(method, path, **kwargs):
    async def call():
        async with module.app.router.lifespan_context(module.app):
            async with httpx.AsyncClient(
                transport=httpx.ASGITransport(app=module.app), base_url=module.ISSUER
            ) as client:
                return await client.request(method, path, **kwargs)

    return asyncio.run(call())


def test_service_token_has_exact_issuer_audience_client_tenant_and_lifetime(issuer):
    response = request(
        "POST",
        "/token",
        auth=("rag-management", "unit-service"),
        data={"grant_type": "client_credentials", "scope": "rag:query"},
    )
    assert response.status_code == 200
    claims = jwt.decode(
        response.json()["access_token"],
        issuer.public_key(),
        algorithms=["RS256"],
        issuer=module.ISSUER,
        audience="hawki-backend",
    )
    assert claims["tenant_id"] == "default"
    assert claims["azp"] == claims["sub"] == "rag-management"
    assert claims["scope"] == "rag:query"
    assert claims["exp"] - claims["iat"] == 300
    assert response.headers["cache-control"] == "no-store"


@pytest.mark.parametrize(
    "auth,data,status",
    [
        (None, {"grant_type": "client_credentials"}, 401),
        (("rag-management", "wrong"), {"grant_type": "client_credentials"}, 401),
        (
            ("rag-management", "unit-service"),
            {"grant_type": "client_credentials", "scope": "rag:datasets:manage"},
            403,
        ),
        (("rag-management", "unit-service"), {"grant_type": "password"}, 400),
        (
            ("rag-management", "unit-service"),
            {"grant_type": "client_credentials", "tenant_id": "other"},
            400,
        ),
    ],
)
def test_token_client_scope_and_grant_constraints(issuer, auth, data, status):
    assert request("POST", "/token", auth=auth, data=data).status_code == status


def test_management_guard_requires_a_separate_development_access_credential(issuer):
    headers = {"X-Original-URI": "/ui-api/v1/ops/stats"}
    assert request("GET", "/authorize", headers=headers).status_code == 401
    assert (
        request(
            "GET",
            "/authorize",
            headers=headers,
            auth=("rag-management", "unit-service"),
        ).status_code
        == 401
    )
    result = request(
        "GET", "/authorize", headers=headers, auth=("local-management", "unit-operator")
    )
    assert result.status_code == 204
    assert "set-cookie" not in result.headers
    assert (
        request(
            "GET", "/authorize", auth=("local-management", "unit-operator")
        ).status_code
        == 403
    )


def test_helper_must_be_explicitly_enabled(issuer, monkeypatch):
    monkeypatch.delenv("RAWKI_LOCAL_AUTH_ENABLED")
    with pytest.raises(RuntimeError, match="explicitly enabled"):
        request("GET", "/health")
