"""Task intent is durable before any fence or remote call, never a pipeline mirror."""

import asyncio
from pathlib import Path
from unittest.mock import patch

import httpx
import pytest
import sqlalchemy as sa

from hawki_backend.persistence.schema import dataset_grants
from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.identity import digest
from hawki_backend.url_ingestion.schema import generations
from hawki_backend.url_ingestion.task_commands import accept
from hawki_backend.url_ingestion import task_commands
from hawki_backend.url_ingestion.scheduler import tick

from .conftest import create, request
from .test_dispatch_recovery import ProcessStopped
from .test_lifecycle import generation, ready, writer, event
from .test_quiescence import boundary, receipt


def command(env, task_id, action="cancel"):
    return asyncio.run(getattr(env.service, action + "_task")(env.principal, task_id))


def task_row(env, task_id):
    return env.repository.task("default", task_id)[0]


@pytest.mark.parametrize("action", ["cancel", "delete"])
def test_task_command_authorizes_before_durable_or_remote_side_effect(env, action):
    task = create(env)
    with env.engine.begin() as connection:
        connection.execute(dataset_grants.delete())
    with pytest.raises(UrlIngestionError) as caught:
        command(env, task["task_id"], action)
    assert caught.value.status == 404
    assert task_row(env, task["task_id"])["task_command"] is None
    env.port.cancel_workflow_and_wait.assert_not_awaited()
    env.service.quiescence.ensure.assert_not_called()
    env.service.cleanup.delete.assert_not_called()


@pytest.mark.parametrize("terminal", [False, True])
def test_cancel_multiple_sources_retains_outputs_and_indexed_data(env, terminal):
    task = create(env, urls=["https://example.org/a", "https://example.org/b"])
    children = env.repository.task("default", task["task_id"])[1]
    if terminal:
        ready(env, children[0])
    marker = Path(children[1]["workflow_input"]["raw_output_path"]) / "keep.txt"
    marker.parent.mkdir(parents=True, exist_ok=True)
    marker.write_text("retained")
    for _ in range(2):
        status, result = command(env, task["task_id"])
        assert status == 200 and result["task"]["status"] == "cancelled"
    assert marker.read_text() == "retained"
    env.service.cleanup.delete.assert_not_called()
    assert env.port.cancel_workflow_and_wait.await_count == 2
    assert env.port.start_ingest_workflow.await_count == 2
    for child in children:
        source = env.repository.source("default", child["source_id"])
        assert source["status"] == "cancelled"
    if terminal:
        assert (
            env.repository.source("default", children[0]["source_id"])[
                "current_generation"
            ]
            == 1
        )


@pytest.mark.parametrize(
    "window", ["intent", "fence", "temporal", "drained", "projection"]
)
def test_cancel_crash_retry_resumes_one_intent_and_one_generation(env, window):
    task = create(env)
    old = generation(env, task)
    if window == "intent":
        target, method = env.repository, "claim"
    elif window == "fence":
        target, method = env.service, "resume"
    elif window == "temporal":
        target, method = env.repository, "closed_empty"
    elif window == "drained":
        target, method = env.repository, "advance"
    else:
        target, method = task_commands, "finish"
    with patch.object(target, method, side_effect=ProcessStopped):
        with pytest.raises(ProcessStopped):
            command(env, task["task_id"])
    before = task_row(env, task["task_id"])["task_command"]
    assert before["state"] == "pending"
    assert command(env, task["task_id"])[1]["task"]["status"] == "cancelled"
    after = task_row(env, task["task_id"])["task_command"]
    assert before["targets"] == after["targets"]
    assert env.repository.source("default", old["source_id"])["generation"] == 2
    with env.engine.connect() as connection:
        assert (
            connection.scalar(sa.select(sa.func.count()).select_from(generations)) == 2
        )
    env.service.cleanup.delete.assert_not_called()


@pytest.mark.parametrize("window", ["after_cancel", "before_confirmation"])
def test_cancel_remote_crash_reconciles_same_crawler_job_and_intent(env, window):
    task = create(env)
    old = generation(env, task)
    cancelled = False
    crash = True
    calls = []

    def respond(req):
        nonlocal cancelled, crash
        calls.append((req.method, req.url.path))
        if req.method == "POST":
            cancelled = True
            if window == "after_cancel" and crash:
                crash = False
                raise ProcessStopped()
        if window == "before_confirmation" and cancelled and crash:
            crash = False
            raise ProcessStopped()
        return httpx.Response(
            200, json=receipt(old, "cancelled" if cancelled else "running", cancelled)
        )

    env.service.quiescence = boundary(env, respond)
    with pytest.raises(ProcessStopped):
        command(env, task["task_id"])
    before = env.repository.generation(old["url_ingestion_operation_id"])[
        "external_job"
    ]
    assert before["cancel_requested_at"]
    assert command(env, task["task_id"])[1]["task"]["status"] == "cancelled"
    after = env.repository.generation(old["url_ingestion_operation_id"])["external_job"]
    assert after["cancel_requested_at"] == before["cancel_requested_at"]
    assert after["terminal_evidence"]["quiescent"]
    assert sum(method == "POST" for method, _ in calls) == 1
    assert all(old["job_id"] in path for _, path in calls)


def test_cancel_blocks_stale_writer_publication_until_writer_release(env):
    task = create(env)
    old = generation(env, task)
    writer(env, old)
    with pytest.raises(UrlIngestionError) as caught:
        command(env, task["task_id"])
    assert caught.value.status == 202
    with pytest.raises(UrlIngestionError):
        writer(env, old, event_id="late-acquire")
    value = event(old)
    assert env.repository.complete(
        value, digest(value.model_dump(mode="json")), "ready"
    )["ignored"]
    writer(env, old, "release")
    assert command(env, task["task_id"])[1]["task"]["status"] == "cancelled"
    assert (
        env.repository.source("default", old["source_id"])["current_generation"] is None
    )


@pytest.mark.parametrize("action", ["cancel", "delete"])
def test_pending_intent_blocks_recrawl_before_generation_fence_and_scheduler_recovers(
    env, action
):
    task = create(env)
    old = generation(env, task)
    accept(env.service, env.principal, task["task_id"], action)
    with pytest.raises(UrlIngestionError) as caught:
        create(env, "competing-recrawl")
    assert caught.value.status == 409
    assert env.repository.source("default", old["source_id"])["generation"] == 1
    assert asyncio.run(tick(env.service))[0]["result"] == "completed"
    assert task_row(env, task["task_id"])["task_command"]["state"] == (
        "deleted" if action == "delete" else "cancelled"
    )


@pytest.mark.parametrize("failure", ["qdrant", "neo4j", "artifact"])
def test_delete_fanout_partial_cleanup_is_visible_and_retry_finishes_only_pending_source(
    env, failure
):
    task = create(env, urls=["https://example.org/a", "https://example.org/b"])
    children = env.repository.task("default", task["task_id"])[1]
    calls = []
    failing = True
    cleanup = env.service.cleanup_artifacts

    def fail(old, *args):
        calls.append(old["source_id"])
        if old["source_id"] == children[0]["source_id"] and failing:
            raise RuntimeError(failure)
        if failure == "artifact":
            cleanup(old)

    if failure == "artifact":
        env.service.cleanup_artifacts = fail
    else:
        env.service.cleanup.delete.side_effect = fail
    with pytest.raises(UrlIngestionError) as caught:
        command(env, task["task_id"], "delete")
    assert caught.value.status == 503
    assert (
        env.repository.source("default", children[1]["source_id"])["status"]
        == "deleted"
    )
    assert (
        request(env, "/v1/pipeline/tasks/" + task["task_id"], method="GET").status_code
        == 200
    )
    failing = False
    assert command(env, task["task_id"], "delete")[1]["deleted"]
    assert command(env, task["task_id"], "delete")[1]["deleted"]
    assert calls.count(children[1]["source_id"]) == 1
    assert (
        request(env, "/v1/pipeline/tasks/" + task["task_id"], method="GET").status_code
        == 404
    )
    assert request(env, "/v1/pipeline/tasks", method="GET").json()["tasks"] == []
    assert env.port.start_ingest_workflow.await_count == 2


def test_delete_after_cancel_cleans_retained_owned_data(env):
    task = create(env)
    old = generation(env, task)
    command(env, task["task_id"])
    command(env, task["task_id"], "delete")
    assert (
        env.service.cleanup.delete.call_args.args[0]["url_ingestion_operation_id"]
        == old["url_ingestion_operation_id"]
    )
    assert env.repository.source("default", old["source_id"])["status"] == "deleted"


def test_pending_cancel_and_delete_cannot_compete(env):
    task = create(env)
    accept(env.service, env.principal, task["task_id"], "cancel")
    with pytest.raises(UrlIngestionError) as caught:
        command(env, task["task_id"], "delete")
    assert caught.value.status == 409
    assert task_row(env, task["task_id"])["task_command"]["action"] == "cancel"


def test_cancel_delete_api_contracts(env):
    task = create(env)
    path = "/v1/pipeline/tasks/" + task["task_id"]
    assert request(env, path + "/cancel").json()["task"]["status"] == "cancelled"
    assert request(env, path, method="DELETE").json() == {
        "success": True,
        "task_id": task["task_id"],
        "deleted": True,
    }


@pytest.mark.parametrize("failure", ["qdrant", "neo4j", "artifact"])
def test_task_delete_uses_existing_owned_sink_adapters_and_recovers_their_failures(
    env, monkeypatch, failure
):
    import json

    from hawki_backend.managed_documents.cleanup import ManagedCleanup
    from hawki_backend.text_ingestion.settings import TextIngestionSettings
    from hawki_backend.text_ingestion.vectors import TextVectorDeletion
    from hawki_rag_contracts.pipeline.identity import document_id

    task = create(env, metadata={"graph": True})
    old = generation(env, task)
    doc_id = document_id(old["source_id"], "a.md")
    writer(
        env, old, targets=[{"doc_id": doc_id, "relative_path": "a.md", "graph": True}]
    )
    writer(env, old, "release")
    marker = Path(old["workflow_input"]["raw_output_path"]) / "owned.txt"
    marker.parent.mkdir(parents=True, exist_ok=True)
    marker.write_text("owned")
    failing = True
    calls = []
    graphs = []

    def qdrant(req):
        calls.append(req)
        if failure == "qdrant" and failing:
            return httpx.Response(503)
        return httpx.Response(
            200, json={"status": "ok", "result": {"status": "completed", "count": 0}}
        )

    class Graph:
        def __init__(self, **kwargs):
            graphs.append(kwargs)

        def delete_scoped_document(self, actual_doc, request_id):
            assert actual_doc == doc_id
            if failure == "neo4j" and failing:
                raise TimeoutError("unconfirmed graph commit")
            return {"verified": True, "remaining_contributions": 0}

        def close(self):
            pass

    monkeypatch.setattr("hawki_backend.managed_documents.cleanup.Neo4jGraph", Graph)
    env.service.cleanup = ManagedCleanup(
        TextVectorDeletion(
            TextIngestionSettings(qdrant_url="http://vectors.test"),
            httpx.Client(transport=httpx.MockTransport(qdrant)),
        )
    )
    cleanup = env.service.cleanup_artifacts

    def artifacts(row):
        if failure == "artifact" and failing:
            raise OSError("artifact unlink failed")
        cleanup(row)

    env.service.cleanup_artifacts = artifacts
    with pytest.raises(UrlIngestionError):
        command(env, task["task_id"], "delete")
    assert task_row(env, task["task_id"])["task_command"]["state"] == "pending"
    assert marker.exists()
    failing = False
    assert command(env, task["task_id"], "delete")[1]["deleted"]
    assert not marker.exists()
    for req in calls:
        assert req.url.path.startswith("/collections/historic_vectors/")
        conditions = json.loads(req.content)["filter"]["must"]
        assert {item["key"]: item["match"]["value"] for item in conditions} == {
            "doc_id": doc_id,
            "source_id": old["source_id"],
            "dataset_id": "web",
        }
    assert graphs[-1]["neo4j_namespace"] == "historic_graph"
    assert graphs[-1]["settings"].database == "historic_database"
    assert graphs[-1]["allow_database_fallback"] is False


@pytest.mark.parametrize("action", ["cancel", "delete"])
def test_dataset_archive_cleanup_composes_an_accepted_task_command(env, action):
    from hawki_backend.persistence.schema import datasets

    task = create(env)
    old = generation(env, task)
    with patch.object(env.service, "resume", side_effect=ProcessStopped):
        with pytest.raises(ProcessStopped):
            command(env, task["task_id"], action)
    with env.engine.begin() as connection:
        connection.execute(datasets.update().values(status="archived"))
    asyncio.run(env.service.cleanup_dataset("default", "web"))
    assert env.repository.source("default", old["source_id"])["status"] == "deleted"


@pytest.mark.parametrize("window", ["intent", "fence", "sink", "projection"])
def test_task_delete_recovers_after_process_death_and_stays_visible_until_verified(
    env, window
):
    task = create(env)
    target, method = {
        "intent": (env.repository, "claim"),
        "fence": (env.service, "resume"),
        "sink": (env.service, "cleanup_artifacts"),
        "projection": (task_commands, "finish"),
    }[window]
    with patch.object(target, method, side_effect=ProcessStopped):
        with pytest.raises(ProcessStopped):
            command(env, task["task_id"], "delete")
    assert (
        request(env, "/v1/pipeline/tasks/" + task["task_id"], method="GET").status_code
        == 200
    )
    assert command(env, task["task_id"], "delete")[1]["deleted"]
    assert (
        request(env, "/v1/pipeline/tasks/" + task["task_id"], method="GET").status_code
        == 404
    )


def test_lost_start_response_lookup_is_bound_to_principal_and_existing_authorization(
    env,
):
    task = create(env)
    response = request(env, "/v1/pipeline/start-request?key=one", method="GET")
    assert response.json() == {
        "success": True,
        "owner": "url",
        "task_id": task["task_id"],
    }
    assert request(
        env, "/v1/pipeline/start-request?key=unknown", method="GET"
    ).json() == {"success": True, "owner": "absent"}
    with env.engine.begin() as connection:
        connection.execute(dataset_grants.delete())
    assert (
        request(env, "/v1/pipeline/start-request?key=one", method="GET").status_code
        == 404
    )


def test_cancel_disables_owned_refresh_schedule_without_cleaning_data(env):
    task = create(env, refresh_cadence="daily")
    old = generation(env, task)
    with env.engine.begin() as connection:
        connection.execute(generations.update().values(schedule_id="owned-refresh"))
    command(env, task["task_id"])
    env.port.delete_schedule.assert_awaited_once_with(schedule_id="owned-refresh")
    source = env.repository.source("default", old["source_id"])
    assert source["refresh_cadence"] is None and source["next_refresh_at"] is None
    env.service.cleanup.delete.assert_not_called()


def test_start_response_loss_replays_one_dispatch_and_duplicate_identity_conflicts(env):
    from hawki_backend.workflow_control.schema import operations

    with patch.object(env.service, "task_payload", side_effect=ProcessStopped):
        with pytest.raises(ProcessStopped):
            create(env, task_id="task-stable")
    assert create(env, task_id="task-stable")["task_id"] == "task-stable"
    with pytest.raises(UrlIngestionError) as caught:
        create(env, "another-key", task_id="task-stable")
    assert caught.value.status == 409
    env.port.start_ingest_workflow.assert_awaited_once()
    with env.engine.connect() as connection:
        assert (
            connection.scalar(sa.select(sa.func.count()).select_from(operations)) == 1
        )


@pytest.mark.parametrize("active_writer", [False, True])
def test_failed_generation_cancel_requires_remote_and_writer_drainage(
    env, active_writer
):
    task = create(env, refresh_cadence="daily")
    old = generation(env, task)
    if active_writer:
        writer(env, old)
    env.repository.fail_workflow(
        old,
        {
            "workflow_id": old["workflow_id"],
            "run_id": old["run_id"],
            "status": "FAILED",
        },
    )
    drained = False

    def remote(req):
        return httpx.Response(
            200, json=receipt(old, "cancelled" if drained else "running", drained)
        )

    env.service.quiescence = boundary(env, remote)
    with pytest.raises(UrlIngestionError) as caught:
        command(env, task["task_id"])
    assert caught.value.status == 202
    assert task_row(env, task["task_id"])["task_command"]["state"] == "pending"
    drained = True
    if active_writer:
        with pytest.raises(UrlIngestionError):
            command(env, task["task_id"])
        writer(env, old, "release")
    assert command(env, task["task_id"])[1]["task"]["status"] == "cancelled"
    source = env.repository.source("default", old["source_id"])
    assert source["refresh_cadence"] is None
    env.service.cleanup.delete.assert_not_called()
    env.port.start_ingest_workflow.assert_awaited_once()


def test_cancel_failed_already_quiescent_generation(env):
    task = create(env)
    old = generation(env, task)
    env.repository.fail_workflow(
        old,
        {
            "workflow_id": old["workflow_id"],
            "run_id": old["run_id"],
            "status": "FAILED",
        },
    )
    env.service.quiescence = boundary(
        env, lambda req: httpx.Response(200, json=receipt(old))
    )
    assert command(env, task["task_id"])[0] == 200
    env.service.cleanup.delete.assert_not_called()


@pytest.mark.parametrize("action", ["cancel", "delete"])
@pytest.mark.parametrize("unfinished", [False, True])
def test_recrawl_task_commands_finish_retirement_and_resume_on_scheduler(
    env, action, unfinished
):
    first = create(env)
    if unfinished:
        with patch.object(
            env.service.cleanup, "delete", side_effect=RuntimeError("offline")
        ):
            with pytest.raises(RuntimeError):
                create(env, "recrawl")
        from hawki_backend.url_ingestion.schema import requests

        with env.engine.connect() as connection:
            task_id = connection.scalar(
                sa.select(requests.c.task_id).where(
                    requests.c.idempotency_key == "recrawl"
                )
            )
    else:
        task_id = create(env, "recrawl")["task_id"]
    env.service.cleanup.delete.reset_mock()
    accept(env.service, env.principal, task_id, action)
    assert asyncio.run(tick(env.service))[0]["result"] == "completed"
    assert task_row(env, task_id)["task_command"]["state"] == (
        "cancelled" if action == "cancel" else "deleted"
    )
    current = env.repository.task("default", task_id)[1][0]
    assert current["cleanup_operations"]  # Historical evidence remains durable.
    assert env.port.start_ingest_workflow.await_count == (1 if unfinished else 2)
    expected_cleanup = (1 if unfinished else 0) + (1 if action == "delete" else 0)
    assert env.service.cleanup.delete.call_count == expected_cleanup
    calls = env.service.cleanup.delete.call_count
    assert command(env, task_id, action)[0] == 200
    assert env.service.cleanup.delete.call_count == calls
    with pytest.raises(UrlIngestionError):
        writer(env, generation(env, first), event_id="stale-after-command")


def test_historical_cancel_does_not_fence_successor(env):
    first = create(env)
    ready(env, generation(env, first))
    successor = create(env, "recrawl")
    current = generation(env, successor)
    env.port.cancel_workflow_and_wait.reset_mock()
    command(env, first["task_id"])
    env.port.cancel_workflow_and_wait.assert_not_awaited()
    assert (
        env.repository.source("default", current["source_id"])["pending_operation_id"]
        == current["url_ingestion_operation_id"]
    )
