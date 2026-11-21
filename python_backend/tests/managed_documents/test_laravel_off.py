"""Managed service requests preserve download and callback authorization."""

from dataclasses import replace
from pathlib import Path
import asyncio
import hashlib
import hmac
import json
import time

import httpx

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.persistence.schema import dataset_grants, metadata
from .conftest import send
from .test_lifecycle import create, revision, writer


def service_principal(env, monkeypatch):
    monkeypatch.setenv("HAWKI_BACKEND_SERVICE_ONLY", "true")
    principal = replace(
        env.principal,
        kind=PrincipalKind.SERVICE,
        client_id="rag-management",
        scopes=frozenset({"rag:ingest", "rag:query"}),
    )
    env.principal = principal
    env.authenticator.authenticate.return_value = principal
    with env.engine.begin() as connection:
        for action in ("query", "ingest"):
            connection.execute(
                dataset_grants.insert().values(
                    tenant_id="default",
                    dataset_id="files",
                    principal_type="service",
                    principal_id="rag-management",
                    permission=action,
                )
            )


def download(env, params):
    async def call():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=env.app), base_url="http://test"
        ) as client:
            return await client.get(
                "/v1/documents/uploads/download",
                params=params,
                headers={"Authorization": "Bearer service"},
            )

    return asyncio.run(call())


def test_service_document_download_is_authorized_and_integrity_checked(
    env, monkeypatch
):
    service_principal(env, monkeypatch)
    body = create(env)
    row = revision(env, body)
    assert (
        env.repository.document("default", body["document"]["id"])["principal_type"]
        == "service"
    )
    params = {"document_id": body["document"]["id"]}
    response = download(env, params)
    assert response.status_code == 200
    assert response.content == Path(row["upload_path"]).read_bytes()
    assert response.headers["cache-control"] == "no-store"
    assert download(env, params | {"content_hash": "0" * 64}).status_code == 404
    assert (
        download(env, params | {"source_url": "upload://../other.pdf"}).status_code
        == 404
    )

    Path(row["upload_path"]).write_bytes(b"changed")
    assert download(env, params).status_code == 409
    env.authenticator.authenticate.return_value = replace(
        env.principal, tenant_id="other"
    )
    assert download(env, params).status_code == 404


def test_managed_writer_uses_python_dispatch_and_keeps_collision_checks(
    env, monkeypatch
):
    service_principal(env, monkeypatch)
    metadata.create_all(env.engine)  # Family classifier requires every owner table.
    env.app.state.workflow_control = env.control
    row = revision(env, create(env))
    payload = writer(row).model_dump(mode="json")

    def submit(value, *, signature_valid=True):
        raw = json.dumps(value).encode()
        stamp = str(int(time.time()))
        signature = hmac.new(
            b"callback-secret", stamp.encode() + b"." + raw, hashlib.sha256
        ).hexdigest()

        async def call():
            async with httpx.AsyncClient(
                transport=httpx.ASGITransport(app=env.app), base_url="http://test"
            ) as client:
                return await client.post(
                    "/internal/pipeline/managed-writers",
                    content=raw,
                    headers={
                        "X-Hawki-Timestamp": stamp,
                        "X-Hawki-Signature": "v1="
                        + (signature if signature_valid else "0" * 64),
                    },
                )

        return asyncio.run(call())

    assert submit(payload).status_code == 200
    assert submit(payload).status_code == 200
    assert submit(payload | {"activity_id": "different"}).status_code == 409
    assert submit(payload, signature_valid=False).status_code == 422


def test_service_only_api_refuses_legacy_user_tokens_and_browser_cookies(
    env, monkeypatch
):
    monkeypatch.setenv("HAWKI_BACKEND_SERVICE_ONLY", "true")
    assert send(env).status_code == 401
    assert send(env, auth=False).status_code == 401
