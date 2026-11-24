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
    body = {**payload(), "metadata": {"course": "design"}}
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
    values = ["x" * 4093] * 15
    values.append(
        "x" * (size - len(json.dumps({"a": values + [""]}, separators=(",", ":"))))
    )
    assert (
        send(env, body={**payload(), "metadata": {"a": values}}).status_code == status
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


def test_service_text_ingestion_and_callback_need_no_laravel(env, monkeypatch):
    from hawki_backend.auth.models import PrincipalKind
    from hawki_backend.persistence.schema import metadata

    monkeypatch.setenv("HAWKI_BACKEND_SERVICE_ONLY", "true")
    principal = replace(
        env.principal, kind=PrincipalKind.SERVICE, client_id="hawki-service"
    )
    env.authenticator.authenticate.return_value = principal
    metadata.create_all(env.engine)
    with env.engine.begin() as connection:
        connection.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="dataset-a",
                principal_type="service",
                principal_id="hawki-service",
                permission="ingest",
            )
        )
    env.app.state.workflow_control = env.control
    env.app.state.workflow_control_secret = "event-test-secret"
    assert send(env).status_code == 202
    row = env.repository.find("default", "key-1")
    assert row["principal_type"] == "service"
    from hawki_backend.url_ingestion.ownership import classify
    from hawki_backend.url_ingestion.repository import UrlRepository

    assert (
        classify(UrlRepository(env.engine), {"workflow_id": row["workflow_id"]})
        == "text"
    )
    wire = event_wire(row)
    assert send(env, path="/internal/pipeline/worker-events", **wire).status_code == 202
    assert send(env, path="/internal/pipeline/worker-events", **wire).status_code == 200
    with env.engine.begin() as connection:
        current = env.repository.find("default", "key-1")["request_metadata"]
        current["workflow_input"].pop("task_queues")
        connection.execute(
            ingest_text_operations.update().values(request_metadata=current)
        )
    assert (
        classify(UrlRepository(env.engine), {"workflow_id": row["workflow_id"]})
        == "text"
    )
    with env.engine.begin() as connection:
        current["workflow_input"]["ingestion"]["collection"] = (
            "different-physical-scope"
        )
        connection.execute(
            ingest_text_operations.update().values(request_metadata=current)
        )
    from hawki_backend.url_ingestion.errors import UrlIngestionError

    with pytest.raises(UrlIngestionError):
        classify(UrlRepository(env.engine), {"workflow_id": row["workflow_id"]})


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


@pytest.mark.parametrize(
    "metadata",
    [
        None,
        [],
        {"nested": {"x": 1}},
        {"a.b": 1},
        {"a" * 65: 1},
        {"year": 2**63},
        {"year": -(2**63) - 1},
        {"labels": [1]},
        {"labels": ["a"] * 33},
        {"value": None},
        {"title": "a" * 4097},
        {"a": "\0"},
        {f"k{i}": i for i in range(33)},
        *[
            {key: "opaque"}
            for key in (
                "datasetId",
                "tenant_id",
                "physical_scope",
                "qdrantCollection",
                "source_id",
                "generation",
                "operation_id",
                "writerState",
                "managed_targets",
                "grant",
                "auth_context",
                "neo4j_namespace",
                "rawki_document_complete",
                "access_token",
                "source_owner_id",
                "source_document_id",
            )
        ],
    ],
)
def test_invalid_user_metadata_rejected_before_side_effects(env, metadata):
    response = send(env, body={**payload(), "metadata": metadata})
    assert response.status_code == 422
    assert "metadata" in response.json()["detail"]["message"]
    assert "opaque" not in response.text
    no_effects(env)


def test_metadata_persistence_replay_and_scoped_deletion(env):
    from pathlib import Path
    from hawki_rag_contracts.source_metadata import metadata_digest

    metadata = {
        "course": "design",
        "year": 2025,
        "score": 1.25,
        "public": False,
        "topics": ["UX", "interaction"],
    }
    body = {**payload(), "metadata": metadata}
    settings = env.service._query_settings
    env.service._query_settings = replace(
        settings,
        datasets={
            "dataset-a": replace(settings.datasets["dataset-a"], graph_enabled=True)
        },
    )
    first = send(env, body=body)
    assert first.status_code == 202
    row = env.repository.find("default", "key-1")
    assert row["request_metadata"]["metadata"] == metadata
    wire = env.port.start_text_ingest_workflow.call_args.kwargs["workflow_input"]
    assert wire["metadata_sha256"] == metadata_digest(metadata)
    assert wire["ingestion"]["graph"] is False
    artifact = Path(wire["markdown_path"])
    sidecar = json.loads(artifact.with_name("rawki_passthrough.json").read_text())
    assert sidecar["metadata"] == metadata
    replay = send(env, body={**body, "metadata": dict(reversed(metadata.items()))})
    assert replay.status_code == 200 and replay.json()["replayed"]
    assert (
        send(env, body={**body, "metadata": {**metadata, "year": "2025"}}).status_code
        == 409
    )
    env.port.start_text_ingest_workflow.assert_awaited_once()
    assert (
        send(env, path="/internal/text-ingestion/events", **event_wire(row)).status_code
        == 202
    )
    deleted = send(
        env,
        method="DELETE",
        path="/v1/text-ingestions/" + first.json()["source_id"],
        key="cleanup",
    )
    assert deleted.status_code == 200
    assert not artifact.exists()
    assert env.vectors.delete.call_args.kwargs["source_id"] == row["source_id"]


def test_integral_numbers_replay_across_historical_fingerprint_encoding(env):
    from hawki_backend.text_ingestion.identity import fingerprint

    value = {**payload(), "metadata": {"count": 1e18}}
    assert send(env, body=value).status_code == 202
    row = env.repository.find("default", "key-1")
    assert type(row["request_metadata"]["metadata"]["count"]) is int
    historical_fields = {**value, "display_name": None, "source_url": None}
    historical_metadata = {**row["request_metadata"], "metadata": value["metadata"]}
    with env.engine.begin() as connection:
        connection.execute(
            ingest_text_operations.update().values(
                fingerprint=fingerprint(historical_fields),
                request_metadata=historical_metadata,
            )
        )
    assert send(env, body=value).status_code == 200
    assert send(env, body={**payload(), "metadata": {"count": True}}).status_code == 409
