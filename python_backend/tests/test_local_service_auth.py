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


@pytest.mark.parametrize("path", ["/authorize", "/login", "/logout", "/session"])
def test_local_issuer_has_no_browser_guard_or_human_login_route(issuer, path):
    response = request("GET", path)
    assert response.status_code == 404
    assert "www-authenticate" not in response.headers
    assert "set-cookie" not in response.headers


def test_browser_operator_cannot_obtain_a_service_token(issuer):
    response = request(
        "POST",
        "/token",
        auth=("local-management", "old-browser-password"),
        data={"grant_type": "client_credentials"},
    )
    assert response.status_code == 401


def test_helper_must_be_explicitly_enabled(issuer, monkeypatch):
    monkeypatch.delenv("RAWKI_LOCAL_AUTH_ENABLED")
    with pytest.raises(RuntimeError, match="explicitly enabled"):
        request("GET", "/health")


def test_retiring_browser_guard_preserves_service_identity_material(
    tmp_path, monkeypatch
):
    provision_spec = importlib.util.spec_from_file_location(
        "rawki_provision_ui", ROOT / "scripts/provision_local_cutover.py"
    )
    provision = importlib.util.module_from_spec(provision_spec)
    provision_spec.loader.exec_module(provision)
    material = tmp_path / "ui-service"
    private = material / "issuer"
    private.mkdir(parents=True)
    service = {
        "issuer": module.ISSUER,
        "clients": {"rag-management": {"sha256": "unchanged", "tenant_id": "default"}},
    }
    (private / "issuer.json").write_text(
        json.dumps(service | {"operator": {"username": "old-browser"}})
    )
    (private / "signing.pem").write_bytes(b"unchanged-service-key")
    (material / "client.secret").write_bytes(b"unchanged-service-secret")
    for name in ("operator.secret", "htpasswd", "curl.conf"):
        (material / name).write_text("old-browser-guard")
    monkeypatch.setattr(provision, "MATERIAL", material)

    provision.remove_browser_guard()

    assert json.loads((private / "issuer.json").read_text()) == service
    assert (private / "signing.pem").read_bytes() == b"unchanged-service-key"
    assert (material / "client.secret").read_bytes() == b"unchanged-service-secret"
    assert all(
        not (material / name).exists()
        for name in ("operator.secret", "htpasswd", "curl.conf")
    )
    nginx = (material / "frontend.nginx.conf").read_text()
    assert "auth_basic" not in nginx and "htpasswd" not in nginx
    assert "server_name localhost 127.0.0.1" in nginx
    assert "return 444" in nginx
    assert "location ^~ /internal/ { return 404; }" in nginx
    assert "proxy_pass http://hawki_backend;" in nginx
