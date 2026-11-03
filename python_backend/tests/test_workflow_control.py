"""Signed control, durable dispatch and compatibility; no Temporal server."""

import asyncio
import hashlib
import hmac
import json
import time
import subprocess
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock

import httpx
import pytest
import sqlalchemy as sa

from hawki_backend.main import create_app
from hawki_backend.workflow_control.identity import source_retry, text_workflow
from hawki_backend.workflow_control.repository import OperationRepository
from hawki_backend.workflow_control.schema import operations
from hawki_backend.workflow_control.service import WorkflowControl
from hawki_workflow_control.temporal_client import TemporalExecution
from hawki_workflow_control.temporal_client import TemporalUnavailable
from hawki_workflow_control.temporal_client import WorkflowOwnershipConflict


def source_input():
    return {
        "source_id": "source-a",
        "dataset_id": "dataset-a",
        "task_id": "task-a",
        "job_id": "job-a",
        "source_url": "https://example.test/a",
        "raw_output_path": "/shared/raw",
        "markdown_output_path": "/shared/markdown",
        "storage": {"shared_root": "/shared"},
        "ingestion": {
            "provider": "ollama",
            "embedding_model": "embed",
            "graph_model": "chat",
            "vision_model": "vision",
            "collection": "historic_collection",
            "neo4j_namespace": "historic_namespace",
        },
    }


def text_input():
    value = source_input()
    value.pop("raw_output_path")
    value.update(
        external_document_id="external-a",
        markdown_path="/shared/markdown/document.md",
        content_hash="a" * 64,
    )
    return value


def signed(
    body,
    *,
    operation="source/start",
    method="POST",
    audience="hawki-workflow-control-v1",
    secret="control-test-secret",
    timestamp=None,
):
    encoded = json.dumps(body, separators=(",", ":"))
    stamp = str(int(time.time()) if timestamp is None else timestamp)
    signature = hmac.new(
        secret.encode(),
        (
            audience
            + "\n"
            + method
            + "\n/internal/workflow-control/"
            + operation
            + "\n"
            + stamp
            + "\n"
            + encoded
        ).encode(),
        hashlib.sha256,
    ).hexdigest()
    return {
        "content": encoded,
        "headers": {
            "Content-Type": "application/json",
            "X-Hawki-Timestamp": stamp,
            "X-Hawki-Signature": "v1=" + signature,
        },
    }


@pytest.fixture
def control(monkeypatch):
    for key in tuple(__import__("os").environ):
        if key.startswith(("TEMPORAL_", "HAWKI_BACKEND_", "HAWKI_WORKFLOW_")):
            monkeypatch.delenv(key)
    engine = sa.create_engine(
        "sqlite://", execution_options={"schema_translate_map": {"hawki_backend": None}}
    )
    operations.create(engine)
    port = SimpleNamespace(
        start_ingest_workflow=AsyncMock(
            return_value=TemporalExecution("ingest-source-source-a", "run-a")
        ),
        start_text_ingest_workflow=AsyncMock(
            return_value=TemporalExecution(text_workflow("task-a"), "run-text")
        ),
        start_managed_deletion=AsyncMock(),
        upsert_ingest_schedule=AsyncMock(),
        reconcile_start=AsyncMock(return_value=None),
        current_execution=AsyncMock(
            return_value={"status": "FAILED", "run_id": "old-run"}
        ),
        cancel_workflow=AsyncMock(),
        cancel_workflow_and_wait=AsyncMock(),
        delete_schedule=AsyncMock(),
        workflow_status=AsyncMock(),
    )
    service = WorkflowControl(OperationRepository(engine), port)
    app = create_app(
        workflow_control=service, workflow_control_secret="control-test-secret"
    )
    yield service, port, app, engine
    engine.dispose()


def request(app, operation, body=None, **kwargs):
    async def send():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=app), base_url="http://control.test"
        ) as client:
            return await client.post(
                "/internal/workflow-control/" + operation,
                **(signed(body, operation=operation) if body is not None else kwargs),
            )

    return asyncio.run(send())


def test_prepare_commit_precedes_network_and_duplicate_replays_receipt(control):
    service, port, app, engine = control
    transactions = []
    sa.event.listen(engine, "begin", lambda conn: transactions.append(conn))
    sa.event.listen(engine, "commit", lambda conn: transactions.remove(conn))
    sa.event.listen(engine, "rollback", lambda conn: transactions.remove(conn))

    async def start(**kwargs):
        assert not transactions
        with engine.connect() as connection:
            persisted = connection.execute(sa.select(operations)).mappings().one()
            assert persisted["status"] == "dispatching"
            assert (
                persisted["payload"]["workflow_input"]["ingestion"]["collection"]
                == "historic_collection"
            )
        assert kwargs["reuse_completed"] is False
        return TemporalExecution(kwargs["workflow_id"], "run-a")

    port.start_ingest_workflow.side_effect = start
    body = {"workflow_id": "ingest-source-source-a", "workflow_input": source_input()}
    first = request(app, "source/start", body)
    second = request(app, "source/start", body)
    assert first.status_code == second.status_code == 200
    assert first.json() == second.json()
    port.start_ingest_workflow.assert_awaited_once()


def test_uncertain_dispatch_reconciles_without_second_start(control):
    _, port, app, _ = control
    port.start_ingest_workflow.side_effect = RuntimeError(
        "private-address secret-token document content"
    )
    body = {"workflow_id": "ingest-source-source-a", "workflow_input": source_input()}
    failed = request(app, "source/start", body)
    assert failed.status_code == 502
    assert "secret-token" not in failed.text
    # NOT_FOUND is not evidence that a delayed first RPC cannot still arrive.
    assert request(app, "source/start", body).status_code == 502
    port.start_ingest_workflow.assert_awaited_once()
    port.reconcile_start.return_value = {
        "workflow_id": "ingest-source-source-a",
        "run_id": "accepted-run",
        "schedule_id": None,
    }
    assert request(app, "source/start", body).json()["run_id"] == "accepted-run"
    port.start_ingest_workflow.assert_awaited_once()


def test_crash_after_claim_does_not_redispatch(control):
    service, port, app, _ = control
    payload = {
        "workflow_input": service.trusted_input("source", source_input()),
        "replacement": False,
        "cadence": None,
        "schedule_id": None,
    }
    row = service.repository.prepare(
        "source", "ingest-source-source-a", payload, ["task-a", "job-a", 0]
    )
    assert service.repository.claim(row["operation_id"])
    assert (
        request(
            app,
            "source/start",
            {"workflow_id": "ingest-source-source-a", "workflow_input": source_input()},
        ).status_code
        == 502
    )
    port.start_ingest_workflow.assert_not_awaited()


def test_new_job_cannot_bypass_uncertain_start_with_same_workflow_id(control):
    _, port, app, _ = control
    port.start_ingest_workflow.side_effect = TimeoutError()
    body = {"workflow_id": "ingest-source-source-a", "workflow_input": source_input()}
    assert request(app, "source/start", body).status_code == 502
    body["workflow_input"]["job_id"] = "job-b"
    body["workflow_input"]["task_id"] = "task-b"
    assert request(app, "source/start", body).status_code == 502
    port.start_ingest_workflow.assert_awaited_once()


def test_only_one_prepared_operation_can_claim_a_workflow_until_confirmed(control):
    service, _, _, _ = control
    first = service.repository.prepare(
        "source", "ingest-source-source-a", {}, ["task-a", "job-a", 0]
    )
    second = service.repository.prepare(
        "source", "ingest-source-source-a", {}, ["task-b", "job-b", 0]
    )
    assert service.repository.claim(first["operation_id"])
    assert not service.repository.claim(second["operation_id"])
    service.repository.finish(first["operation_id"], None)
    assert not service.repository.claim(second["operation_id"])
    service.repository.finish(first["operation_id"], {"run_id": "run-a"})
    assert service.repository.claim(second["operation_id"])


def test_connection_failure_before_submission_can_retry_same_preparation(control):
    service, port, app, engine = control
    port.start_ingest_workflow.side_effect = TemporalUnavailable()
    body = {"workflow_id": "ingest-source-source-a", "workflow_input": source_input()}
    assert request(app, "source/start", body).status_code == 503
    with engine.connect() as connection:
        assert (
            connection.execute(sa.select(operations.c.status)).scalar_one()
            == "prepared"
        )
    port.start_ingest_workflow.side_effect = None
    assert request(app, "source/start", body).status_code == 200
    assert port.start_ingest_workflow.await_count == 2


def test_another_tasks_existing_run_is_not_confirmed_as_this_operation(control):
    _, port, app, engine = control
    port.start_ingest_workflow.side_effect = WorkflowOwnershipConflict()
    body = {"workflow_id": "ingest-source-source-a", "workflow_input": source_input()}
    assert request(app, "source/start", body).status_code == 409
    with engine.connect() as connection:
        row = connection.execute(sa.select(operations)).mappings().one()
        assert row["status"] == "prepared"
        assert row["receipt"] is None


def test_delayed_timeout_cannot_erase_concurrent_confirmation(control):
    service, _, _, engine = control
    row = service.repository.prepare(
        "source", "ingest-source-source-a", {}, ["task-a", "job-a", 0]
    )
    assert service.repository.claim(row["operation_id"])
    receipt = {"workflow_id": "ingest-source-source-a", "run_id": "run-a"}
    service.repository.finish(row["operation_id"], receipt)
    service.repository.finish(row["operation_id"], None)
    with engine.connect() as connection:
        saved = connection.execute(sa.select(operations)).mappings().one()
        assert saved["status"] == "confirmed"
        assert saved["receipt"] == receipt


def test_additive_migration_compiles_without_touching_laravel(control, monkeypatch):
    import io
    from alembic import command
    from alembic.config import Config

    monkeypatch.setenv(
        "HAWKI_BACKEND_DB_DSN", "postgresql+psycopg://control:test@db/backend"
    )
    output = io.StringIO()
    config = Config(
        str(Path(__file__).resolve().parents[1] / "alembic.ini"), output_buffer=output
    )
    command.upgrade(config, "0001:head", sql=True)
    emitted = output.getvalue()
    assert "CREATE TABLE hawki_backend.workflow_operations" in emitted
    assert "CREATE UNIQUE INDEX uq_workflow_operations_unconfirmed_workflow" in emitted
    assert "public." not in emitted


@pytest.mark.parametrize("mutation", ["unsigned", "expired", "tampered", "jwt"])
def test_only_exact_body_internal_hmac_can_dispatch(control, mutation):
    _, port, app, _ = control
    wire = signed(
        {"workflow_id": "ingest-source-source-a", "workflow_input": source_input()}
    )
    if mutation == "expired":
        wire = signed({}, timestamp=1000000000)
    elif mutation == "tampered":
        wire["content"] += " "
    else:
        wire["headers"].pop("X-Hawki-Signature")
        if mutation == "jwt":
            wire["headers"]["Authorization"] = "Bearer browser-token"
    assert request(app, "source/start", **wire).status_code == 401
    port.start_ingest_workflow.assert_not_awaited()


@pytest.mark.parametrize(
    "field", ["workflow_type", "task_queue", "namespace", "address", "reuse_completed"]
)
def test_caller_cannot_select_temporal_primitives(control, field):
    _, port, app, _ = control
    assert (
        request(
            app,
            "source/start",
            {
                "workflow_id": "ingest-source-source-a",
                "workflow_input": source_input(),
                field: "arbitrary",
            },
        ).status_code
        == 422
    )
    port.start_ingest_workflow.assert_not_awaited()


def test_queue_override_and_changed_preparation_fail_closed(control):
    _, port, app, _ = control
    value = source_input()
    value["task_queues"] = {"indexer": "untrusted"}
    assert (
        request(
            app,
            "source/start",
            {"workflow_id": "ingest-source-source-a", "workflow_input": value},
        ).status_code
        == 422
    )
    value = source_input()
    assert (
        request(
            app,
            "source/start",
            {"workflow_id": "ingest-source-source-a", "workflow_input": value},
        ).status_code
        == 200
    )
    value["ingestion"]["collection"] = "different_collection"
    assert (
        request(
            app,
            "source/start",
            {"workflow_id": "ingest-source-source-a", "workflow_input": value},
        ).status_code
        == 409
    )
    port.start_ingest_workflow.assert_awaited_once()


def test_text_recovery_uses_text_workflow_and_distinct_operation(control):
    _, port, app, _ = control
    body = {"workflow_id": text_workflow("task-a"), "workflow_input": text_input()}
    assert request(app, "text/start", body).status_code == 200
    assert (
        request(app, "text/start", {**body, "recovery_attempt": 1}).status_code == 200
    )
    assert (
        request(app, "text/start", {**body, "recovery_attempt": 1}).status_code == 200
    )
    assert port.start_text_ingest_workflow.await_count == 2
    port.start_ingest_workflow.assert_not_awaited()


@pytest.mark.parametrize(
    "status,expected",
    [("RUNNING", 409), ("UNKNOWN", 409), ("FAILED", 200), ("COMPLETED", 200)],
)
def test_source_retry_identity_is_python_owned_and_checks_predecessor(
    control, status, expected
):
    _, port, app, _ = control
    port.current_execution.return_value = {"status": status, "run_id": "old-run"}
    response = request(
        app,
        "source/retry-identity",
        {
            "source_id": "source-a",
            "previous_workflow_id": "ingest-source-source-a",
            "attempt": 2,
            "job_id": "job/a",
        },
    )
    assert response.status_code == expected
    if expected == 200:
        assert response.json()["workflow_id"] == "ingest-source-source-a-retry-2-job-a"


@pytest.mark.parametrize(
    "operation", ["execution/cancel", "execution/stop-for-deletion", "execution/status"]
)
def test_cancellation_and_specific_run_status_use_python_port(control, operation):
    _, port, app, _ = control
    port.workflow_status.return_value = {
        "status": "FAILED",
        "error": "private-document-content",
    }
    result = request(
        app, operation, {"workflow_id": "ingest-source-source-a", "run_id": "run-a"}
    )
    assert result.status_code == 200
    assert "private-document-content" not in result.text
    method = {
        "execution/cancel": port.cancel_workflow,
        "execution/stop-for-deletion": port.cancel_workflow_and_wait,
        "execution/status": port.workflow_status,
    }[operation]
    method.assert_awaited_once_with(
        workflow_id="ingest-source-source-a", run_id="run-a"
    )


def test_historical_deletion_scope_and_writers_reach_same_workflow(control):
    _, port, app, _ = control
    value = {
        "operation_id": "delete_" + "a" * 64,
        "managed_document_id": "adoc-a",
        "dataset_id": "dataset-a",
        "writers": [{"workflow_id": "ingest-source-source-old", "run_id": "old-run"}],
        "targets": [
            {
                "output_id": 1,
                "doc_id": "old-doc",
                "source_id": "source-old",
                "collection": "historic_collection",
                "neo4j_namespace": "historic_namespace",
            }
        ],
    }
    identity = "managed-" + value["operation_id"]
    port.start_managed_deletion.return_value = {
        "workflow_id": identity,
        "run_id": "delete-run",
        "status": "pending",
    }
    assert (
        request(
            app, "deletion/start", {"workflow_id": identity, "workflow_input": value}
        ).status_code
        == 200
    )
    forwarded = port.start_managed_deletion.call_args.kwargs["workflow_input"]
    assert forwarded["targets"] == value["targets"]
    assert forwarded["writers"] == value["writers"]


def test_workflow_identity_matches_historical_php_values():
    assert (
        text_workflow("task-a")
        == "ingest-text-" + hashlib.sha256(b"task-a").hexdigest()[:32]
    )
    assert (
        source_retry("source-a", None, 1, "job/a")
        == "ingest-source-source-a-retry-1-job-a"
    )
    assert (
        source_retry("source-a", "ingest-source-source-a-retry-1-job-a", 2, "job/a")
        == "ingest-source-source-a-retry-1-job-a-retry-2-job-a"
    )


def test_backend_runtime_has_no_worker_dependency():
    root = Path(__file__).resolve().parents[1]
    metadata = (root / "pyproject.toml").read_text()
    assert "hawki-workflow-control" in metadata
    assert "hawki-workflow-worker" not in metadata
    assert "hawki-indexer-worker" not in metadata
    assert "HAWKI_QUERY_BACKEND=laravel" in (root.parent / ".env.example").read_text()


@pytest.mark.parametrize("count", [1, 1728])
def test_real_php_signature_and_historical_deletion_wire_reach_backend(control, count):
    _, port, app, _ = control
    root = Path(__file__).resolve().parents[2]
    value = {
        "operation_id": "delete_" + "b" * 64,
        "managed_document_id": "adoc-a",
        "dataset_id": "dataset-a",
        "writers": [],
        "targets": [
            {
                "output_id": index + 1,
                "doc_id": "old-doc",
                "source_id": "old-source",
                "collection": "historic_collection",
                "neo4j_namespace": "historic_namespace",
            }
            for index in range(count)
        ],
    }
    wire = json.loads(
        subprocess.run(
            ["php", str(root / "tests/Support/managed_deletion_wire.php")],
            input=json.dumps(value),
            text=True,
            capture_output=True,
            check=True,
        ).stdout
    )
    app.state.workflow_control_secret = "contract-test-secret"
    identity = "managed-" + value["operation_id"]
    port.start_managed_deletion.return_value = {
        "workflow_id": identity,
        "run_id": "delete-run",
        "status": "pending",
    }
    response = request(
        app, "deletion/start", content=wire["body"], headers=wire["headers"]
    )
    assert response.status_code == 200, response.text
    assert (
        port.start_managed_deletion.call_args.kwargs["workflow_input"]["targets"]
        == value["targets"]
    )


def test_signed_php_replacement_selects_restricted_reuse_in_python(control):
    _, port, app, _ = control
    root = Path(__file__).resolve().parents[2]
    value = {**source_input(), "managed_document_id": "adoc-a"}
    wire = json.loads(
        subprocess.run(
            [
                "php",
                str(root / "tests/Support/managed_deletion_wire.php"),
                "replacement",
            ],
            input=json.dumps(value),
            text=True,
            capture_output=True,
            check=True,
        ).stdout
    )
    app.state.workflow_control_secret = "contract-test-secret"
    response = request(
        app, "source/start", content=wire["body"], headers=wire["headers"]
    )
    assert response.status_code == 200, response.text
    assert port.start_ingest_workflow.call_args.kwargs["reuse_completed"] is True


@pytest.mark.parametrize(
    "operation,other",
    [
        ("execution/status", "execution/cancel"),
        ("execution/cancel", "execution/status"),
        ("source/start", "execution/cancel"),
    ],
)
def test_signature_cannot_authorize_another_operation(control, operation, other):
    _, port, app, _ = control
    port.workflow_status.return_value = {"status": "RUNNING", "error": None}
    body = {"workflow_id": "ingest-source-source-a", "run_id": "run-a"}
    wire = signed(body, operation=operation)
    response = request(app, other, **wire)
    assert response.status_code == 401
    port.cancel_workflow.assert_not_awaited()
    port.workflow_status.assert_not_awaited()


@pytest.mark.parametrize(
    "mutation", ["method", "path", "audience", "body", "stale", "invalid"]
)
def test_signature_authenticates_every_contract_component(control, mutation, caplog):
    _, port, app, _ = control
    body = {"workflow_id": "ingest-source-source-a", "run_id": "run-a"}
    options = {"operation": "execution/cancel"}
    if mutation == "method":
        options["method"] = "GET"
    elif mutation == "path":
        options["operation"] = "execution/cancel/"
    elif mutation == "audience":
        options["audience"] = "another-service"
    elif mutation == "stale":
        options["timestamp"] = int(time.time()) - 301
    wire = signed(body, **options)
    if mutation == "body":
        wire["content"] += " "
    elif mutation == "invalid":
        wire["headers"]["X-Hawki-Signature"] = "v1=" + "0" * 64
    response = request(app, "execution/cancel", **wire)
    assert response.status_code == 401
    assert response.json() == {"detail": "Invalid control-plane signature."}
    port.cancel_workflow.assert_not_awaited()
    for sensitive in [
        "control-test-secret",
        wire["content"],
        wire["headers"]["X-Hawki-Signature"],
    ]:
        assert sensitive not in response.text + caplog.text


@pytest.mark.parametrize("operation", ["execution/status", "execution/cancel"])
def test_php_signer_and_python_verifier_bind_operation_independently_of_host(
    control, operation
):
    _, port, app, _ = control
    port.workflow_status.return_value = {"status": "RUNNING", "error": None}
    app.state.workflow_control_secret = "contract-test-secret"
    root = Path(__file__).resolve().parents[2]
    body = {"workflow_id": "ingest-source-source-a", "run_id": "run-a"}
    stamp = int(time.time())
    wires = []
    for host in ["http://backend.test", "http://different-host.test"]:
        wires.append(
            json.loads(
                subprocess.run(
                    [
                        "php",
                        str(root / "tests/Support/managed_deletion_wire.php"),
                        operation,
                        host,
                        str(stamp),
                    ],
                    input=json.dumps(body),
                    text=True,
                    capture_output=True,
                    check=True,
                ).stdout
            )
        )
    assert wires[0] == wires[1]
    expected = signed(
        body, operation=operation, secret="contract-test-secret", timestamp=stamp
    )
    assert (
        wires[0]["headers"]["X-Hawki-Signature"]
        == expected["headers"]["X-Hawki-Signature"]
    )
    wire = wires[0]
    wire["headers"]["Host"] = "attacker-controlled.test"
    wire["headers"]["X-Hawki-Audience"] = "attacker-controlled"
    assert (
        request(
            app, operation, content=wire["body"], headers=wire["headers"]
        ).status_code
        == 200
    )
    other = (
        "execution/status" if operation == "execution/cancel" else "execution/cancel"
    )
    assert (
        request(app, other, content=wire["body"], headers=wire["headers"]).status_code
        == 401
    )
    assert port.cancel_workflow.await_count == (
        1 if operation == "execution/cancel" else 0
    )


def test_changed_http_method_is_rejected_by_verifier_and_router(control):
    from starlette.requests import Request
    from fastapi import HTTPException
    from hawki_backend.workflow_control.api import authorized_body

    _, port, app, _ = control
    wire = signed(
        {"workflow_id": "ingest-source-source-a"}, operation="execution/cancel"
    )

    async def verify():
        async def receive():
            return {"type": "http.request", "body": wire["content"].encode()}

        request_value = Request(
            {
                "type": "http",
                "method": "GET",
                "app": app,
                "headers": [
                    (k.lower().encode(), v.encode()) for k, v in wire["headers"].items()
                ],
            },
            receive,
        )
        with pytest.raises(HTTPException) as error:
            await authorized_body(request_value, "execution/cancel")
        assert error.value.status_code == 401
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=app), base_url="http://control.test"
        ) as client:
            response = await client.request(
                "GET", "/internal/workflow-control/execution/cancel", **wire
            )
        assert response.status_code == 405

    asyncio.run(verify())
    port.cancel_workflow.assert_not_awaited()


def test_unresolved_retry_blocks_new_generation_even_with_stale_predecessor(control):
    _, port, app, engine = control
    predecessor = "ingest-source-source-a"
    identity_body = {
        "source_id": "source-a",
        "previous_workflow_id": predecessor,
        "attempt": 1,
        "job_id": "job-a",
    }
    identity = request(app, "source/retry-identity", identity_body).json()[
        "workflow_id"
    ]
    body = {"workflow_id": identity, "workflow_input": source_input()}
    port.start_ingest_workflow.side_effect = TimeoutError()
    assert request(app, "source/start", body).status_code == 502
    assert (
        request(
            app, "source/retry-identity", {**identity_body, "attempt": 2}
        ).status_code
        == 502
    )
    assert (
        request(app, "source/retry-identity", identity_body).json()["workflow_id"]
        == identity
    )
    assert request(app, "source/start", body).status_code == 502
    port.start_ingest_workflow.assert_awaited_once()
    with engine.connect() as conn:
        row = conn.execute(sa.select(operations)).mappings().one()
        assert row["workflow_id"] == identity and row["status"] == "uncertain"
    port.reconcile_start.return_value = {
        "workflow_id": identity,
        "run_id": "accepted-run",
        "schedule_id": None,
    }
    assert request(app, "source/start", body).json()["run_id"] == "accepted-run"
    assert request(app, "source/start", body).json()["run_id"] == "accepted-run"
    port.start_ingest_workflow.assert_awaited_once()


@pytest.mark.parametrize("action", ["source", "text"])
def test_independently_proven_unsubmitted_attempt_retries_same_identity(
    control, action
):
    service, port, app, engine = control
    identity = (
        "ingest-source-source-a" if action == "source" else text_workflow("task-a")
    )
    body = {
        "workflow_id": identity,
        "workflow_input": source_input() if action == "source" else text_input(),
    }
    start = (
        port.start_ingest_workflow
        if action == "source"
        else port.start_text_ingest_workflow
    )
    start.side_effect = TimeoutError()
    assert request(app, action + "/start", body).status_code == 502
    with engine.connect() as conn:
        operation_id = conn.execute(sa.select(operations.c.operation_id)).scalar_one()
    # Only independent proof the original RPC cannot arrive permits this operator
    # transition. A Temporal NOT_FOUND alone is explicitly insufficient.
    with engine.begin() as conn:
        conn.execute(
            operations.update()
            .where(operations.c.operation_id == operation_id)
            .values(status="prepared")
        )
    start.side_effect = None
    assert request(app, action + "/start", body).status_code == 200
    assert start.await_count == 2
    assert {call.kwargs["workflow_id"] for call in start.await_args_list} == {identity}


@pytest.mark.parametrize("action", ["source", "text"])
def test_concurrent_recovery_dispatches_only_once_and_reconciles_after_timeout(
    control, action
):
    _, port, app, engine = control
    identity = (
        "ingest-source-source-a-retry-1-job-a"
        if action == "source"
        else text_workflow("task-a")
    )
    body = {
        "workflow_id": identity,
        "workflow_input": source_input() if action == "source" else text_input(),
    }
    if action == "text":
        body["recovery_attempt"] = 1
    start = (
        port.start_ingest_workflow
        if action == "source"
        else port.start_text_ingest_workflow
    )

    async def scenario():
        submitted = asyncio.Event()
        complete = asyncio.Event()

        async def delayed_start(**kwargs):
            submitted.set()
            await complete.wait()
            raise TimeoutError()

        start.side_effect = delayed_start
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=app), base_url="http://control.test"
        ) as client:

            async def send():
                return await client.post(
                    "/internal/workflow-control/" + action + "/start",
                    **signed(body, operation=action + "/start"),
                )

            first = asyncio.create_task(send())
            await submitted.wait()
            try:
                second = await send()
                assert second.status_code == 502
                with engine.connect() as conn:
                    row = conn.execute(sa.select(operations)).mappings().one()
                    assert (
                        row["status"] == "dispatching"
                        and row["workflow_id"] == identity
                    )
            finally:
                complete.set()
            assert (await first).status_code == 502
            start.assert_awaited_once()
            port.reconcile_start.return_value = {
                "workflow_id": identity,
                "run_id": "adopted-run",
                "schedule_id": None,
            }
            assert (await send()).json()["run_id"] == "adopted-run"
            start.assert_awaited_once()

    asyncio.run(scenario())
