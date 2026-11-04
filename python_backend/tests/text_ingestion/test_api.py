"""Public contract, auth before side effects, replay and signed completion."""

import hashlib
import hmac
import json
import time
from dataclasses import replace

import pytest
import sqlalchemy as sa

from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.text_ingestion.schema import ingest_text_operations
from hawki_backend.workflow_control.schema import operations
from .conftest import payload, send


def no_effects(env):
    with env.engine.connect() as conn:
        assert (
            conn.execute(
                sa.select(sa.func.count()).select_from(ingest_text_operations)
            ).scalar()
            == 0
        )
        assert (
            conn.execute(sa.select(sa.func.count()).select_from(operations)).scalar()
            == 0
        )
    assert not (env.root / "sources").exists()
    env.port.start_text_ingest_workflow.assert_not_awaited()


@pytest.mark.parametrize(
    "case,status",
    [
        ("unauthenticated", 401),
        ("scope", 403),
        ("tenant", 404),
        ("grant", 404),
        ("unknown", 404),
        ("archived", 404),
        ("deleted", 404),
    ],
)
def test_authorization_precedes_all_side_effects(env, case, status):
    body = payload()
    if case == "scope":
        env.authenticator.authenticate.return_value = replace(
            env.principal, scopes=frozenset({"rag:query"})
        )
    if case == "tenant":
        env.authenticator.authenticate.return_value = replace(
            env.principal, tenant_id="other"
        )
    if case == "unknown":
        body["dataset_id"] = "unknown"
    with env.engine.begin() as conn:
        if case == "grant":
            conn.execute(dataset_grants.delete())
        if case in {"archived", "deleted"}:
            conn.execute(datasets.update().values(status=case))
    response = send(env, body=body, auth=case != "unauthenticated")
    assert response.status_code == status, response.text
    no_effects(env)


@pytest.mark.parametrize(
    "field",
    [
        "unknown",
        "graph",
        "embedding_model",
        "embedding_provider",
        "embedding_custom",
        "qdrant_collection",
        "neo4j_namespace",
        "task_queues",
        "workflow_name",
    ],
)
def test_forbidden_fields(env, field):
    assert send(env, body={**payload(), field: "untrusted"}).status_code == 422
    no_effects(env)


@pytest.mark.parametrize("key", [None, "x" * 192, "-bad", "bad/key", "bad key", "é"])
def test_invalid_keys(env, key):
    assert send(env, key=key).status_code == 422
    no_effects(env)


@pytest.mark.parametrize("size,status", [(4_300_000, 202), (4_300_001, 413)])
def test_exact_raw_byte_limit(env, size, status):
    body = json.dumps(payload()).encode()
    response = send(env, content=body + b" " * (size - len(body)))
    assert response.status_code == status, response.text


@pytest.mark.parametrize(
    "text,status",
    [
        ("x" * 1_048_576, 202),
        ("x" * 1_048_577, 422),
        ("🌍" * 1_048_576, 202),
        ("🌍" * 1_048_577, 422),
    ],
)
def test_unicode_character_limit(env, text, status):
    body = json.dumps({**payload(), "text": text}, ensure_ascii=False).encode()
    assert send(env, content=body).status_code == status


@pytest.mark.parametrize("size,status", [(65_536, 202), (65_537, 422)])
def test_metadata_limit(env, size, status):
    assert (
        send(env, body={**payload(), "metadata": {"a": "x" * (size - 8)}}).status_code
        == status
    )


def test_initial_replay_changed_request_and_single_preparation(env):
    first = send(env)
    assert first.status_code == 202, first.text
    second = send(env)
    assert second.status_code == 200
    assert second.json() == {**first.json(), "replayed": True}
    assert send(env, body={**payload(), "text": "changed"}).status_code == 409
    env.port.start_text_ingest_workflow.assert_awaited_once()
    value = env.port.start_text_ingest_workflow.call_args.kwargs
    assert value["workflow_input"]["ingestion"]["graph"] is False
    assert value["workflow_input"]["ingestion"]["collection"] == "owned_collection"
    assert value["workflow_id"] == first.json()["workflow_id"]
    with env.engine.connect() as conn:
        assert (
            conn.execute(sa.select(sa.func.count()).select_from(operations)).scalar()
            == 1
        )


def test_uncertain_dispatch_never_starts_a_second_workflow(env):
    env.port.start_text_ingest_workflow.side_effect = TimeoutError("private secret")
    response = send(env)
    assert response.status_code == 502
    assert "private" not in response.text
    assert send(env).status_code == 502
    row = env.repository.find("default", "key-1")
    env.port.reconcile_start.return_value = {
        "workflow_id": row["workflow_id"],
        "run_id": "run-1",
    }
    assert send(env).status_code == 200
    env.port.start_text_ingest_workflow.assert_awaited_once()
    assert (
        env.port.reconcile_start.call_args.kwargs["workflow_id"] == row["workflow_id"]
    )


def event_wire(
    row, status="completed", event_id="event-1", activity="mark_source_ready"
):
    body = dict(
        schema_version=1,
        event_id=event_id,
        event_type="pipeline.stage.status",
        producer="indexer",
        timestamp="2026-11-03T00:00:00Z",
        workflow_id=row["workflow_id"],
        run_id="run-1",
        activity_id=activity,
        attempt=1,
        job_id=row["job_id"],
        task_id=row["task_id"],
        source_id=row["source_id"],
        stage="ingest",
        phase=activity,
        status=status,
        counts={},
        metrics={},
        artifacts=[],
        errors=[],
        warnings=[],
    )
    raw = json.dumps(body).encode()
    stamp = str(int(time.time()))
    signature = hmac.new(
        b"event-test-secret", stamp.encode() + b"." + raw, hashlib.sha256
    ).hexdigest()
    return dict(
        content=raw,
        headers={"X-Hawki-Timestamp": stamp, "X-Hawki-Signature": "v1=" + signature},
    )


@pytest.mark.parametrize(
    "status,expected",
    [("completed", "ready"), ("failed", "failed"), ("skipped", "failed")],
)
def test_completion_and_event_collision(env, status, expected):
    assert send(env).status_code == 202
    row = env.repository.find("default", "key-1")
    wire = event_wire(row, status)
    response = send(env, path="/internal/text-ingestion/events", **wire)
    assert response.status_code == 202, response.text
    assert env.repository.find("default", "key-1")["lifecycle_status"] == expected
    assert send(env, path="/internal/text-ingestion/events", **wire).status_code == 200
    assert (
        send(
            env, path="/internal/text-ingestion/events", **event_wire(row, "running")
        ).status_code
        == 409
    )


def test_unsigned_event_rejected(env):
    send(env)
    row = env.repository.find("default", "key-1")
    wire = event_wire(row)
    wire["headers"]["X-Hawki-Signature"] = "v1=" + "0" * 64
    assert send(env, path="/internal/text-ingestion/events", **wire).status_code == 422
    assert env.repository.find("default", "key-1")["lifecycle_status"] == "started"


@pytest.mark.parametrize(
    "raw",
    [
        b'{"text":"\\ud800","dataset_id":"dataset-a","external_document_id":"doc","content_format":"plain_text"}',
        b"[]",
        b"{",
        b'{"dataset_id":"dataset-a","external_document_id":"doc","content_format":"plain_text","text":"x","metadata":{"n":NaN}}',
    ],
)
def test_malformed_json_and_unicode_are_safe_validation_errors(env, raw):
    assert send(env, content=raw).status_code == 422
    no_effects(env)


def test_runtime_sql_never_reads_laravel_schema(env):
    statements = []
    sa.event.listen(
        env.engine,
        "before_cursor_execute",
        lambda conn, cursor, statement, parameters, context, executemany: (
            statements.append(statement)
        ),
    )
    assert send(env).status_code == 202
    assert send(env).status_code == 200
    row = env.repository.find("default", "key-1")
    assert (
        send(env, path="/internal/text-ingestion/events", **event_wire(row)).status_code
        == 202
    )
    assert (
        send(
            env,
            method="DELETE",
            path="/v1/text-ingestions/" + row["source_id"],
            key="delete-1",
        ).status_code
        == 200
    )
    assert statements
    assert not any(
        "public." in statement or 'public".' in statement for statement in statements
    )
    assert not any(
        " users" in statement or "pipeline_jobs" in statement
        for statement in statements
    )


def test_maximum_key_length_is_accepted(env):
    assert send(env, key="a" * 191).status_code == 202


def test_lost_api_response_replays_committed_operation(env):
    # The caller loses the first response after the durable dispatch commits.
    send(env)
    replay = send(env)
    assert replay.status_code == 200
    assert replay.json()["replayed"] is True
    env.port.start_text_ingest_workflow.assert_awaited_once()
