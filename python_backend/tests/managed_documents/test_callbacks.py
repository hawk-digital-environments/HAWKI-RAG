"""Signed callbacks prove ownership through durable control and revision input."""

import asyncio
import hashlib
import hmac
import json
import time

import httpx
import sqlalchemy as sa

from hawki_backend.managed_documents.schema import revisions
from hawki_backend.workflow_control.schema import operations

from .test_lifecycle import create, revision, writer


def signed(env, kind, payload, *, valid=True):
    raw = json.dumps(payload).encode()
    stamp = str(int(time.time()))
    signature = hmac.new(
        b"callback-secret", stamp.encode() + b"." + raw, hashlib.sha256
    ).hexdigest()

    async def call():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=env.app), base_url="http://test"
        ) as client:
            return await client.post(
                "/internal/managed-documents/" + kind,
                content=raw,
                headers={
                    "X-Hawki-Timestamp": stamp,
                    "X-Hawki-Signature": "v1=" + (signature if valid else "0" * 64),
                },
            )

    return asyncio.run(call())


def test_ownership_requires_control_input_and_expected_managed_revision(env):
    row = revision(env, create(env))
    assert signed(env, "ownership", {"workflow_id": row["workflow_id"]}).json() == {
        "owner": "python"
    }
    assert (
        signed(
            env, "ownership", {"workflow_id": row["workflow_id"]}, valid=False
        ).status_code
        == 422
    )
    assert (
        signed(env, "ownership", {"workflow_id": "ingest-source-unknown"}).status_code
        == 409
    )
    with env.engine.begin() as connection:
        connection.execute(
            revisions.update().values(
                workflow_input=row["workflow_input"]
                | {"managed_document_id": "adoc_wrong"}
            )
        )
    assert (
        signed(env, "ownership", {"workflow_id": row["workflow_id"]}).status_code == 409
    )


def test_crawler_input_without_managed_id_remains_laravel_but_old_managed_is_not_adopted(
    env,
):
    row = revision(env, create(env))
    with env.engine.begin() as connection:
        operation = connection.execute(sa.select(operations)).mappings().one()
        wire = operation["payload"]["workflow_input"].copy()
        wire.pop("managed_document_id")
        connection.execute(
            operations.update().values(
                payload=operation["payload"] | {"workflow_input": wire}
            )
        )
    assert signed(env, "ownership", {"workflow_id": row["workflow_id"]}).json() == {
        "owner": "laravel"
    }
    with env.engine.begin() as connection:
        wire["managed_document_id"] = "adoc_old_laravel_owner"
        connection.execute(
            operations.update().values(
                payload=operation["payload"] | {"workflow_input": wire}
            )
        )
    assert (
        signed(env, "ownership", {"workflow_id": row["workflow_id"]}).status_code == 409
    )


def test_signed_writer_api_records_exact_protocol_and_rejects_payload_collision(env):
    row = revision(env, create(env))
    event = writer(row).model_dump(mode="json")
    assert signed(env, "writers", event).json()["status"] == "active"
    assert signed(env, "writers", event).json()["status"] == "active"
    assert signed(env, "writers", event | {"action": "uncertain"}).status_code == 409
    assert (
        signed(
            env, "writers", event | {"event_id": "release", "action": "release"}
        ).json()["status"]
        == "released"
    )
