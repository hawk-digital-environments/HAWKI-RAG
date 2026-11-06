import hashlib
import hmac
import json
import time

import pytest
import sqlalchemy as sa

from hawki_backend.url_ingestion.identity import digest
from hawki_backend.workflow_control.schema import operations

from .conftest import create, request
from .test_lifecycle import generation, event, ready


def signed(env, kind, payload):
    raw = json.dumps(payload).encode()
    stamp = str(int(time.time()))
    signature = hmac.new(
        b"url-test-secret", stamp.encode() + b"." + raw, hashlib.sha256
    ).hexdigest()
    # Match the locked httpx 0.27 JSON serialization exactly.
    return request(
        env,
        "/internal/url-ingestion/" + kind,
        payload,
        headers={"X-Hawki-Timestamp": stamp, "X-Hawki-Signature": "v1=" + signature},
    )


def test_explicit_url_owner_requires_both_expected_generation_and_control_row(env):
    row = generation(env, create(env))
    assert signed(env, "ownership", {"workflow_id": row["workflow_id"]}).json() == {
        "owner": "url"
    }
    with env.engine.begin() as connection:
        stored = connection.execute(sa.select(operations)).mappings().one()
        payload = dict(stored["payload"])
        payload["workflow_input"] = payload["workflow_input"] | {
            "url_ingestion_operation_id": "other"
        }
        connection.execute(operations.update().values(payload=payload))
    assert (
        signed(env, "ownership", {"workflow_id": row["workflow_id"]}).status_code == 409
    )


def test_unknown_workflow_fails_closed_and_task_absence_is_explicit(env):
    assert signed(env, "ownership", {"workflow_id": "unknown"}).json() == {
        "owner": "absent"
    }
    assert signed(env, "ownership", {"task_id": "legacy-or-managed-task"}).json() == {
        "owner": "absent"
    }


@pytest.mark.parametrize(
    "wire,owner",
    [
        ({"managed_document_id": "managed"}, "laravel"),
        ({"source_id": "legacy"}, "laravel"),
    ],
)
def test_signed_pre_cutover_and_managed_operations_are_not_adopted_as_urls(
    env, wire, owner
):
    with env.engine.begin() as connection:
        connection.execute(
            operations.insert().values(
                operation_id=digest(wire),
                workflow_id="existing",
                action="source",
                input_digest=digest(wire),
                payload={"workflow_input": wire},
                status="confirmed",
            )
        )
    assert signed(env, "ownership", {"workflow_id": "existing"}).json() == {
        "owner": owner
    }


def test_signed_event_duplicate_and_collision_at_the_http_boundary(env):
    row = generation(env, create(env))
    ready(env, row)
    value = event(row, "http-duplicate").model_dump(mode="json", by_alias=True)
    first = signed(env, "events", value)
    assert first.status_code == 202
    assert signed(env, "events", value).status_code == 200
    value["phase"] = "changed"
    assert signed(env, "events", value).status_code == 409


@pytest.mark.parametrize(
    "action,wire,expected",
    [
        ("text", {"job_id": "existing-job"}, 200),
        ("source", {"job_id": "existing-job", "managed_document_id": "managed"}, 200),
        ("source", {"job_id": "existing-job"}, 200),
    ],
)
def test_mutations_require_explicit_non_url_ownership_to_keep_existing_handlers(
    env, action, wire, expected
):
    with env.engine.begin() as connection:
        connection.execute(
            operations.insert().values(
                operation_id=digest(wire),
                workflow_id="existing",
                action=action,
                input_digest=digest(wire),
                payload={"workflow_input": wire},
                status="confirmed",
            )
        )
    response = signed(env, "ownership", {"job_id": "existing-job", "mutation": True})
    assert response.status_code == expected
    if expected == 200:
        assert response.json() == {"owner": "laravel"}
