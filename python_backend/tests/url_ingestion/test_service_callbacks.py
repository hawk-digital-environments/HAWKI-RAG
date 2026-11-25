"""Service-owned URL ingestion and signed callback ownership."""

from dataclasses import replace
import hashlib
import hmac
import json
import time

import pytest

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.persistence.schema import dataset_grants
from .conftest import create, request
from .test_lifecycle import generation, event, ready


def callback(env, payload, *, stamp=None, valid=True, route="worker-events"):
    raw = json.dumps(payload).encode()
    stamp = stamp or str(int(time.time()))
    signature = hmac.new(
        b"url-test-secret", stamp.encode() + b"." + raw, hashlib.sha256
    ).hexdigest()
    return request(
        env,
        f"/internal/pipeline/{route}",
        payload,
        headers={
            "X-Hawki-Timestamp": stamp,
            "X-Hawki-Signature": "v1=" + (signature if valid else "0" * 64),
        },
    )


@pytest.mark.parametrize(
    "route", ["managed-deletion-events", "managed-deletion-writer-proof"]
)
def test_historical_deletion_receipts_refuse_acknowledgement(env, route):
    payload = {"workflow_id": "managed-historical"}
    assert callback(env, payload, route=route, valid=False).status_code == 422
    response = callback(env, payload, route=route)
    assert response.status_code == 409
    assert response.json() == {"detail": "legacy_deletion_requires_drain"}


def test_service_url_ingestion_preserves_grants_ownership_and_signed_callbacks(
    env, monkeypatch
):
    monkeypatch.setenv("RAG_BACKEND_SERVICE_ONLY", "true")
    principal = replace(
        env.principal, kind=PrincipalKind.SERVICE, client_id="hawki-service"
    )
    env.principal = principal
    env.app.state.authenticator.authenticate.return_value = principal
    env.app.state.workflow_control = env.control
    with env.engine.begin() as connection:
        connection.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="web",
                principal_type="service",
                principal_id="hawki-service",
                permission="ingest",
            )
        )
    row = generation(env, create(env))
    source = env.repository.source("default", row["source_id"])
    assert source["principal_type"] == "service"
    assert source["principal_id"] == "hawki-service"
    ready(env, row)
    payload = event(row, "direct-python").model_dump(mode="json", by_alias=True)
    assert callback(env, payload).status_code == 202
    assert callback(env, payload).status_code == 200
    payload["phase"] = "changed"
    assert callback(env, payload).status_code == 409
    assert callback(env, payload, valid=False).status_code == 422
    assert callback(env, payload, stamp=str(int(time.time()) - 1000)).status_code == 422
    payload["workflow_id"] = "ingest-source-unknown"
    assert callback(env, payload).status_code == 409
    with env.engine.begin() as connection:
        connection.execute(
            dataset_grants.delete().where(dataset_grants.c.principal_type == "service")
        )
    assert (
        request(
            env,
            "/v1/pipeline/tasks/start",
            {"dataset_id": "web", "urls": ["https://example.test/denied"]},
        ).status_code
        == 404
    )
