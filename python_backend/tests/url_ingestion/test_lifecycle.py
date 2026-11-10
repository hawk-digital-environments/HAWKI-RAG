import asyncio
import json
from dataclasses import replace
from pathlib import Path

import pytest
import sqlalchemy as sa

from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.identity import digest
from hawki_backend.url_ingestion.schema import sources, generations, requests
from hawki_backend.text_ingestion.writers import WriterEvent
from hawki_backend.workflow_control.schema import operations
from hawki_rag_contracts.pipeline.identity import document_id
from hawki_rag_contracts.pipeline.status import PipelineWorkerEvent

from .conftest import create, request


def generation(env, payload):
    return env.repository.generation(
        payload["task"]["jobs"][0]["url_ingestion_operation_id"]
    )


def writer(env, row, action="acquire", targets=None, **changes):
    value = WriterEvent.model_validate(
        {
            "schema_version": 1,
            "event_id": row["url_ingestion_operation_id"] + ":" + action,
            "action": action,
            "owner": "url",
            "url_ingestion_operation_id": row["url_ingestion_operation_id"],
            "dataset_id": row["dataset_id"],
            "generation": row["generation"],
            "workflow_id": row["workflow_id"],
            "run_id": row["run_id"],
            "activity_id": "index_markdown",
            "attempt": 1,
            "source_id": row["source_id"],
            "job_id": row["job_id"],
            "targets": targets or [],
            **changes,
        }
    )
    return env.repository.record_writer(value, digest(value.model_dump(mode="json")))


def event(row, suffix="ready", **changes):
    return PipelineWorkerEvent.model_validate(
        {
            "event_id": row["url_ingestion_operation_id"] + ":" + suffix,
            "producer": "indexer",
            "timestamp": "2026-11-05T12:00:00Z",
            "workflow_id": row["workflow_id"],
            "run_id": row["run_id"],
            "activity_id": "mark_source_ready",
            "attempt": 1,
            "job_id": row["job_id"],
            "task_id": row["task_id"],
            "source_id": row["source_id"],
            "stage": "ingest",
            "phase": "mark_source_ready",
            "status": "completed",
            "manifest": {"uri": row["workflow_input"]["ingest_manifest_path"]},
            **changes,
        }
    )


def ready(env, row):
    manifest = Path(row["workflow_input"]["ingest_manifest_path"])
    manifest.parent.mkdir(parents=True, exist_ok=True)
    manifest.write_text("[]")
    writer(env, row)
    writer(env, row, "release")
    value = event(row)
    return env.repository.complete(
        value, digest(value.model_dump(mode="json")), "ready"
    )


@pytest.mark.parametrize(
    "data",
    [
        {"datasetId": "web", "sourceUrl": "Example.org"},
        {"dataset_id": "web", "urls": "Example.org,example.org/a\nexample.org/b"},
        {"dataset_id": "web", "urls": ["example.org/a", "example.org/b"]},
    ],
)
def test_aliases_multi_url_metadata_and_201_contract(env, data):
    metadata = {"origin": "MCP", "nested": {"x": [1, "two"]}}
    response = request(env, "/v1/pipeline/tasks/start", data | {"metadata": metadata})
    assert response.status_code == 201
    payload = response.json()
    assert payload["success"] is True
    assert payload["task"]["metadata"]["request"]["metadata"] == metadata
    assert len(payload["task"]["jobs"]) == (
        3 if isinstance(data.get("urls"), str) else 2 if "urls" in data else 1
    )
    assert "scraper_url" not in json.dumps(payload)


def test_same_key_replays_identity_and_prepares_one_control_operation(env):
    first = create(env)
    assert create(env)["task_id"] == first["task_id"]
    with env.engine.connect() as connection:
        for table in (sources, generations, requests, operations):
            assert connection.scalar(sa.select(sa.func.count()).select_from(table)) == 1
    env.port.start_ingest_workflow.assert_awaited_once()
    row = generation(env, first)
    assert row["workflow_operation_id"] is not None
    assert row["content_identity"] == {"version": 1, "status": "unknown"}
    assert row["workflow_input"]["owner"] == "url"
    assert "content_hash" not in row["workflow_input"].get("refresh", {})


def test_same_key_changed_request_conflicts(env):
    create(env)
    with pytest.raises(UrlIngestionError) as caught:
        create(env, metadata={"changed": True})
    assert caught.value.status == 409


def test_uncertain_dispatch_never_allocates_another_workflow(env):
    env.port.start_ingest_workflow.side_effect = TimeoutError()
    # An unavailable lookup is ambiguous. None now means definitive NOT_FOUND,
    # which can safely retry the same URL identity under REJECT_DUPLICATE.
    env.port.reconcile_start.side_effect = TimeoutError()
    for _ in range(2):
        with pytest.raises(UrlIngestionError):
            create(env)
    env.port.start_ingest_workflow.assert_awaited_once()
    env.port.reconcile_start.assert_awaited_once()
    with env.engine.connect() as connection:
        assert (
            connection.scalar(sa.select(sa.func.count()).select_from(operations)) == 1
        )


def test_url_target_grants_freeze_vectors_graph_and_generation(env):
    row = generation(env, create(env, metadata={"graph": True}))
    target = {
        "doc_id": document_id(row["source_id"], "a.md"),
        "relative_path": "a.md",
        "graph": True,
    }
    grant = writer(env, row, targets=[target])["targets"][0]
    assert grant == {
        "doc_id": target["doc_id"],
        "source_id": row["source_id"],
        "dataset_id": "web",
        "collection": "historic_vectors",
        "neo4j_namespace": "historic_graph",
        "owner": "url",
        "url_ingestion_operation_id": row["url_ingestion_operation_id"],
        "generation": 1,
        "neo4j_database": "historic_database",
    }
    writer(env, row, "release")
    with pytest.raises(UrlIngestionError):
        writer(env, row, event_id="new-acquire")


@pytest.mark.parametrize(
    "changes",
    [
        {"owner": None},
        {"url_ingestion_operation_id": "other"},
        {"dataset_id": "other"},
        {"generation": 2},
        {"run_id": "other"},
    ],
)
def test_wrong_writer_identity_rejected(env, changes):
    row = generation(env, create(env))
    with pytest.raises(UrlIngestionError):
        writer(env, row, **changes)


def test_graph_escalation_and_path_traversal_rejected(env):
    row = generation(env, create(env))
    for relative, graph in [("a.md", True), ("../a.md", False)]:
        with pytest.raises(UrlIngestionError):
            writer(
                env,
                row,
                targets=[
                    {
                        "doc_id": document_id(row["source_id"], "a.md"),
                        "relative_path": relative,
                        "graph": graph,
                    }
                ],
            )


def test_ready_requires_drain_verified_manifest_and_event_collision_detection(env):
    row = generation(env, create(env))
    value = event(row)
    with pytest.raises(UrlIngestionError):
        env.repository.complete(value, "hash", "ready")
    receipt = ready(env, row)
    value = event(row)
    assert receipt["ignored"] is False
    assert (
        env.repository.complete(value, digest(value.model_dump(mode="json")), "ready")[
            "duplicate"
        ]
        is True
    )
    with pytest.raises(UrlIngestionError):
        env.repository.complete(value, "collision", "ready")
    source = env.repository.source("default", row["source_id"])
    assert source["status"] == "ready"
    assert source["current_generation"] == 1


def test_recrawl_closes_previous_generation_before_successor_and_late_events_cannot_publish(
    env,
):
    old = generation(env, create(env))
    ready(env, old)
    new = generation(env, create(env, key="recrawl"))
    assert new["source_id"] == old["source_id"]
    assert new["generation"] == 2
    assert "-retry-1-" in new["workflow_id"]
    assert (
        env.repository.generation(old["url_ingestion_operation_id"])["writer_state"][
            "managed_writer_gate"
        ]
        == "closed"
    )
    assert (
        env.repository.complete(event(old, "late"), "late", "ready")["ignored"] is True
    )
    env.port.cancel_workflow_and_wait.assert_awaited_once_with(
        workflow_id=old["workflow_id"], run_id=old["run_id"]
    )
    with pytest.raises(UrlIngestionError):
        writer(env, old, event_id="late-acquire")
    ready(env, new)
    assert env.repository.source("default", old["source_id"])["current_generation"] == 2


def test_delete_waits_for_writer_release_then_verified_cleanup_and_can_recreate(env):
    old = generation(env, create(env))
    writer(env, old)
    with pytest.raises(UrlIngestionError) as caught:
        asyncio.run(env.service.delete(env.principal, old["source_id"], "delete"))
    assert caught.value.status == 202
    env.service.cleanup.delete.assert_not_called()
    writer(env, old, "release")
    status, _ = asyncio.run(
        env.service.delete(env.principal, old["source_id"], "delete")
    )
    assert status == 200
    assert env.repository.source("default", old["source_id"])["status"] == "deleted"
    new = generation(env, create(env, key="recreate"))
    assert new["generation"] == 3


def test_cleanup_uses_frozen_targets_and_failure_never_publishes_delete(env):
    old = generation(env, create(env, metadata={"graph": True}))
    ready(env, old)
    env.service.query_settings = None
    env.service.settings = replace(
        env.service.settings, shared_root=str(env.root / "drift")
    )
    env.service.cleanup.delete.side_effect = RuntimeError("sink failed")
    with pytest.raises(RuntimeError):
        asyncio.run(env.service.delete(env.principal, old["source_id"], "delete"))
    called = env.service.cleanup.delete.call_args.args[0]
    assert called["workflow_input"]["ingestion"]["collection"] == "historic_vectors"
    assert called["workflow_input"]["ingestion"]["neo4j_namespace"] == "historic_graph"
    assert env.repository.source("default", old["source_id"])["status"] == "deleting"


def test_headerless_delete_resumes_and_replays_completed_operation(env):
    old = generation(env, create(env))
    writer(env, old)
    with pytest.raises(UrlIngestionError) as caught:
        asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    assert caught.value.status == 202
    writer(env, old, "release")
    first = asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    replay = asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    assert first == replay
    assert first[0] == 200
    assert env.repository.source("default", old["source_id"])["generation"] == 2
    env.service.cleanup.delete.assert_called_once()
