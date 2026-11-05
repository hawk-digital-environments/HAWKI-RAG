import hashlib
import json
from pathlib import Path
from dataclasses import replace

import pytest
import sqlalchemy as sa

from hawki_backend.managed_documents.input import ManagedError
from hawki_backend.managed_documents.schema import (
    documents,
    mutations,
    revisions,
    events,
)
from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.text_ingestion.writers import WriterEvent
from hawki_backend.workflow_control.schema import operations
from hawki_rag_contracts.pipeline.identity import document_id
from hawki_rag_contracts.pipeline.status import PipelineWorkerEvent

from .conftest import send


def count(env, table):
    with env.engine.connect() as connection:
        return connection.execute(
            sa.select(sa.func.count()).select_from(table)
        ).scalar_one()


def create(env, **kwargs):
    result = send(env, **kwargs)
    assert result.status_code == 202, result.text
    return result.json()


def revision(env, payload):
    doc = env.repository.document("default", payload["document"]["id"])
    return env.repository.revision(
        doc["pending_revision_id"] or doc["current_revision_id"]
    )


def writer(row, *, action="acquire", event_id=None, targets=None, **changes):
    return WriterEvent.model_validate(
        dict(
            schema_version=1,
            event_id=event_id or (row["workflow_id"] + action + str(targets)),
            action=action,
            workflow_id=row["workflow_id"],
            run_id="run-1",
            source_id=row["source_id"],
            job_id=row["job_id"],
            activity_id="index",
            attempt=1,
            targets=targets or [],
        )
        | changes
    )


def released(env, row, *, graph=False):
    target = {
        "doc_id": document_id(row["source_id"], "document.md"),
        "relative_path": "document.md",
        "graph": graph,
    }
    env.repository.record_writer(writer(row, targets=[target]))
    env.repository.record_writer(writer(row, action="release"))


def ready(env, row, event_id="ready", **changes):
    path = Path(row["workflow_input"]["ingest_manifest_path"])
    if not path.exists():
        current = env.repository.revision(row["revision_id"])
        records = [
            {
                "document_id": doc_id,
                "relative_path": target["relative_path"],
                "markdown_path": str(
                    Path(row["workflow_input"]["markdown_output_path"])
                    / target["relative_path"]
                ),
                "content_hash": "a" * 64,
            }
            for doc_id, target in current["writer_state"]["managed_targets"].items()
        ]
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(records), encoding="utf-8")
    value = (
        dict(
            schema_version=1,
            event_id=event_id,
            producer="indexer",
            workflow_id=row["workflow_id"],
            run_id="run-1",
            source_id=row["source_id"],
            job_id=row["job_id"],
            task_id=row["task_id"],
            activity_id="mark_source_ready",
            attempt=1,
            stage="ingest",
            phase="mark_source_ready",
            status="completed",
            occurred_at="2026-11-05T10:00:00Z",
            manifest={"uri": str(path), "media_type": "application/json"},
        )
        | changes
    )
    event = PipelineWorkerEvent.model_validate(value)
    return env.repository.complete(
        event,
        hashlib.sha256(json.dumps(value, sort_keys=True).encode()).hexdigest(),
        "ready",
    )


def test_create_and_same_key_replay_keep_one_epoch8_operation(env):
    first = create(env)
    assert create(env)["document"]["id"] == first["document"]["id"]
    assert first["document"]["id"].startswith("adoc_")
    row = revision(env, first)
    assert row["workflow_input"]["managed_document_id"] == first["document"]["id"]
    assert row["workflow_input"]["managed_writer_protocol"] == 1
    assert (
        row["workflow_input"]["upload"]["content_hash"]
        == hashlib.sha256(b"hello").hexdigest()
    )
    for table in (documents, mutations, revisions, operations):
        assert count(env, table) == 1
    env.port.start_ingest_workflow.assert_awaited_once()
    assert env.port.start_ingest_workflow.call_args.kwargs["reuse_completed"] is False


def test_identical_upload_different_key_or_filename_creates_separate_document(env):
    first = create(env)
    second = create(env, key="key-2")
    third = create(env, key="key-3", files=[("file", ("b.txt", b"hello"))])
    assert len({p["document"]["id"] for p in (first, second, third)}) == 3
    assert len({p["pipeline"]["source_id"] for p in (first, second, third)}) == 3


@pytest.mark.parametrize(
    "change",
    [
        {"data": {"dataset_id": "files", "graph": "true"}},
        {"files": [("file", ("a.txt", b"changed"))]},
        {"files": [("file", ("b.txt", b"hello"))]},
    ],
)
def test_same_key_changed_request_conflicts(env, change):
    create(env)
    assert send(env, **change).status_code == 409
    assert count(env, documents) == 1


@pytest.mark.parametrize("kind", ["grant", "tenant", "inactive", "scope", "anonymous"])
def test_authorization_precedes_managed_side_effects(env, kind):
    with env.engine.begin() as connection:
        if kind == "grant":
            connection.execute(dataset_grants.delete())
        if kind == "inactive":
            connection.execute(datasets.update().values(status="archived"))
    if kind == "tenant":
        env.authenticator.authenticate.return_value = replace(
            env.principal, tenant_id="wrong"
        )
    if kind == "scope":
        env.authenticator.authenticate.return_value = replace(
            env.principal, scopes=frozenset({"rag:query"})
        )
    result = send(env, auth=kind != "anonymous")
    assert result.status_code in {401, 403, 404}
    assert count(env, documents) == 0
    assert not list(env.root.iterdir())


def test_upload_validation_metadata_graph_and_checksum(env):
    assert send(env, files=[("file", ("a.exe", b"hello"))]).status_code == 422
    assert (
        send(
            env, data={"dataset_id": "files", "source_checksum_sha256": "a" * 64}
        ).status_code
        == 422
    )
    assert (
        send(env, data={"dataset_id": "files", "source_url": "not-url"}).status_code
        == 422
    )
    assert (
        send(env, data={"dataset_id": "files", "metadata_json": '"scalar"'}).status_code
        == 422
    )
    value = create(
        env,
        data={
            "dataset_id": "files",
            "graph": "true",
            "metadata_json": "[1,2]",
            "source_url": "https://example.test/a",
        },
        files=[("file", ("Hello World.TXT", b"hello"))],
    )
    assert value["document"]["graph_enabled"] is True
    assert value["document"]["metadata_json"] == [1, 2]
    assert (
        revision(env, value)["workflow_input"]["ingestion"]["neo4j_namespace"]
        == "historic_graph"
    )


def test_batch_shape_and_key_index_partial_failure(env):
    result = send(
        env,
        path="/v1/documents/batch",
        files=[("files[]", ("a.txt", b"one")), ("files[]", ("b.exe", b"two"))],
    )
    assert result.status_code == 207
    assert result.json()["summary"] == {"total": 2, "accepted": 1, "failed": 1}
    with env.engine.connect() as connection:
        assert (
            connection.execute(sa.select(mutations.c.idempotency_key)).scalar_one()
            == "key-1:0"
        )
    assert (
        send(
            env,
            path="/v1/documents/batch",
            key="all",
            files=[("files[]", ("a.txt", b"one")), ("files[]", ("b.txt", b"two"))],
        ).status_code
        == 202
    )


def test_uncertain_start_reconciles_same_identity_without_redispatch(env):
    env.port.start_ingest_workflow.side_effect = TimeoutError()
    assert send(env).status_code == 502
    with env.engine.connect() as connection:
        before = connection.execute(sa.select(revisions.c.workflow_id)).scalar_one()
    env.port.reconcile_start.return_value = {"workflow_id": before, "run_id": "run-1"}
    assert create(env)["pipeline"]["source_id"] in before
    env.port.start_ingest_workflow.assert_awaited_once()
    assert count(env, operations) == 1


def test_writers_fence_drain_delete_and_late_acquire(env):
    payload = create(env)
    row = revision(env, payload)
    env.repository.record_writer(writer(row))
    path = "/v1/documents/" + payload["document"]["id"]
    assert send(env, path=path, method="DELETE", key="del").status_code == 202
    env.service.cleanup.delete.assert_not_called()
    with pytest.raises(ManagedError):
        env.repository.record_writer(writer(row))
    env.repository.record_writer(writer(row, action="release"))
    assert send(env, path=path, method="DELETE", key="del").status_code == 200
    assert (
        env.repository.document("default", payload["document"]["id"])["status"]
        == "deleted"
    )


def test_graph_writer_exact_scope_and_changed_target_rejected(env):
    payload = create(env, data={"dataset_id": "files", "graph": "true"})
    row = revision(env, payload)
    target = {
        "doc_id": document_id(row["source_id"], "one.md"),
        "relative_path": "one.md",
        "graph": True,
    }
    receipt = env.repository.record_writer(writer(row, targets=[target]))
    assert receipt["targets"][0]["neo4j_namespace"] == "historic_graph"
    with pytest.raises(ManagedError):
        env.repository.record_writer(
            writer(row, targets=[target | {"relative_path": "two.md"}])
        )
    with env.engine.begin() as connection:
        changed = row["workflow_input"] | {
            "ingestion": row["workflow_input"]["ingestion"]
            | {"neo4j_namespace": "wrong"}
        }
        connection.execute(revisions.update().values(workflow_input=changed))
    with pytest.raises(ManagedError):
        env.repository.record_writer(writer(row))


def test_event_duplicate_collision_and_publication(env):
    payload = create(env)
    row = revision(env, payload)
    released(env, row)
    assert ready(env, row)["ignored"] is False
    assert ready(env, row)["duplicate"] is True
    with pytest.raises(ManagedError):
        ready(env, row, occurred_at="2026-11-05T11:00:00Z")
    doc = env.repository.document("default", payload["document"]["id"])
    assert doc["generation"] == 1 and doc["current_revision_id"] == row["revision_id"]
    assert count(env, events) == 3


def test_stale_completion_after_delete_is_ignored(env):
    payload = create(env)
    row = revision(env, payload)
    released(env, row)
    path = "/v1/documents/" + payload["document"]["id"]
    assert send(env, path=path, method="DELETE").status_code == 200
    assert ready(env, row)["ignored"] is True
    assert (
        env.repository.document("default", payload["document"]["id"])["status"]
        == "deleted"
    )


def test_put_checksum_skip_and_force_cleanup_then_publish(env):
    payload = create(env)
    row = revision(env, payload)
    released(env, row)
    ready(env, row)
    path = "/v1/documents/" + payload["document"]["id"]
    result = send(
        env,
        path=path,
        method="PUT",
        data={
            "source_updated_at": "2026-11-05T11:00:00Z",
            "source_checksum_sha256": hashlib.sha256(b"hello").hexdigest(),
        },
        key="put",
    )
    assert result.status_code == 200, result.text
    assert result.json()["operation"]["status"] == "skipped_unchanged"
    result = send(env, path=path, method="PUT", data={"force": "true"}, key="force")
    assert result.status_code == 202, result.text
    doc = env.repository.document("default", payload["document"]["id"])
    assert doc["current_revision_id"] == row["revision_id"]
    assert doc["pending_revision_id"] != row["revision_id"]
    new = env.repository.revision(doc["pending_revision_id"])
    released(env, new)
    ready(env, new, event_id="ready-2")
    assert (
        env.repository.document("default", payload["document"]["id"])["generation"] == 2
    )
    assert env.port.start_ingest_workflow.call_args.kwargs["reuse_completed"] is True


def test_cleanup_failure_never_deletes_and_same_key_retry_resumes(env):
    payload = create(env)
    row = revision(env, payload)
    released(env, row, graph=False)
    env.service.cleanup.delete.side_effect = RuntimeError("partial graph cleanup")
    path = "/v1/documents/" + payload["document"]["id"]
    assert send(env, path=path, method="DELETE").status_code == 502
    assert (
        env.repository.document("default", payload["document"]["id"])["status"]
        == "deleting"
    )
    env.service.cleanup.delete.side_effect = None
    assert send(env, path=path, method="DELETE").status_code == 200


def test_upload_size_boundary_with_stream_does_not_allocate_100_mib(env):
    from hawki_backend.managed_documents.artifacts import hash_upload
    from hawki_backend.managed_documents.input import MAX_FILE_BYTES

    class Stream:
        def __init__(self, size):
            self.size = size
            self.remaining = size

        def seek(self, offset):
            self.remaining = self.size

        def read(self, size):
            chunk = b"a" * min(self.remaining, size)
            self.remaining -= len(chunk)
            return chunk

    assert len(hash_upload(Stream(MAX_FILE_BYTES))) == 64
    with pytest.raises(ManagedError):
        hash_upload(Stream(MAX_FILE_BYTES + 1))


def test_custom_profile_survives_recreated_raw_directory_and_keeps_legacy_fields(env):
    value = create(
        env,
        data={
            "dataset_id": "files",
            "converter_mode": "custom",
            "converter_url": "https://converter.example",
            "converter_token": "private",
            "converter_start_path": "start",
        },
        files=[("file", ("document.special", b"bytes"))],
    )
    row = revision(env, value)
    path = Path(row["workflow_input"]["custom_converter_profile_path"])
    assert path.parent.name == "secrets"
    assert not path.is_relative_to(Path(row["workflow_input"]["raw_output_path"]))
    profile = json.loads(path.read_text())
    assert profile["source_id"] == row["source_id"]
    assert profile["version"] == "1" and profile["converter_token"] == "private"
    assert profile["converter_start_path"] == "/start"
    assert profile["converter_status_path"] == "/jobs/{job_id}"


def test_extension_fallback_preserves_original_filename_for_bff_upload(env):
    value = create(
        env,
        files=[
            (
                "file",
                (
                    "report.txt",
                    b"hello",
                    "text/plain",
                    {"X-Hawki-Original-Filename": "report"},
                ),
            )
        ],
    )
    row = revision(env, value)
    assert value["document"]["original_filename"] == "report"
    assert row["workflow_input"]["upload"]["original_filename"] == "report"
    assert row["workflow_input"]["upload"]["extension"] == "txt"


def test_timestamp_priority_skips_stale_and_accepts_newer_content(env):
    value = create(
        env, data={"dataset_id": "files", "source_updated_at": "2026-11-05T10:00:00Z"}
    )
    row = revision(env, value)
    released(env, row)
    ready(env, row)
    path = "/v1/documents/" + value["document"]["id"]
    for index, stamp in enumerate(["2026-11-05T09:00:00Z", "2026-11-05T10:00:00Z"]):
        result = send(
            env,
            path=path,
            method="PUT",
            data={"source_updated_at": stamp},
            files=[("file", ("new.special", b"new"))],
            key=f"skip-{index}",
        )
        assert result.status_code == 200
        assert result.json()["operation"]["status"] == "skipped_unchanged"
    result = send(
        env,
        path=path,
        method="PUT",
        data={
            "source_updated_at": "2026-11-05T11:00:00Z",
            "source_checksum_sha256": hashlib.sha256(b"hello").hexdigest(),
        },
        key="newer",
    )
    assert result.status_code == 202
    assert result.json()["pipeline"]["source_id"] != row["source_id"]


def test_uncertain_writer_cannot_be_released_or_used_as_drainage_proof(env):
    value = create(env)
    row = revision(env, value)
    env.repository.record_writer(writer(row))
    env.repository.record_writer(writer(row, action="uncertain"))
    receipt = env.repository.record_writer(writer(row, action="release"))
    assert receipt["status"] == "uncertain"
    result = send(env, path="/v1/documents/" + value["document"]["id"], method="DELETE")
    assert result.status_code == 202
    env.service.cleanup.delete.assert_not_called()


def test_wrong_manifest_cannot_publish_even_with_released_writer(env):
    value = create(env)
    row = revision(env, value)
    released(env, row)
    with pytest.raises(ManagedError):
        ready(env, row, manifest={"uri": "/wrong/manifest.json"})
    assert (
        env.repository.document("default", value["document"]["id"])[
            "current_revision_id"
        ]
        is None
    )


def test_create_replay_canonicalizes_defaults_and_matching_checksum(env):
    first = create(env)
    replay = create(
        env,
        data={
            "dataset_id": "files",
            "graph": "false",
            "display_name": "a.txt",
            "metadata_json": "{}",
            "source_checksum_sha256": hashlib.sha256(b"hello").hexdigest(),
        },
    )
    assert first["document"]["id"] == replay["document"]["id"]
    assert count(env, documents) == 1


def test_batch_retains_per_item_storage_failure_shape(env, monkeypatch):
    original = env.service.storage.store

    def store(task, filename, *args):
        if filename == "bad.txt":
            raise OSError("private path must not be returned")
        return original(task, filename, *args)

    monkeypatch.setattr(env.service.storage, "store", store)
    result = send(
        env,
        path="/v1/documents/batch",
        files=[("files[]", ("a.txt", b"hello")), ("files[]", ("bad.txt", b"hello"))],
    )
    assert result.status_code == 207
    failed = result.json()["items"][1]
    assert failed["http_status"] == 502
    assert failed["result"]["operation"]["status"] == "failed"
    assert "private path" not in result.text
    assert count(env, documents) == 2
    monkeypatch.setattr(env.service.storage, "store", original)
    retry = send(
        env,
        path="/v1/documents/batch",
        files=[("files[]", ("a.txt", b"hello")), ("files[]", ("bad.txt", b"hello"))],
    )
    assert retry.status_code == 202
    assert count(env, documents) == 2


def test_conflicting_put_retains_legacy_operation_and_document_error_shape(env):
    value = create(env)
    row = revision(env, value)
    released(env, row)
    ready(env, row)
    path = "/v1/documents/" + value["document"]["id"]
    first = send(
        env, path=path, method="PUT", data={"force": "true"}, key="replace-one"
    )
    assert first.status_code == 202
    conflict = send(
        env, path=path, method="PUT", data={"force": "true"}, key="replace-two"
    )
    assert conflict.status_code == 409
    assert conflict.json()["operation"] == {"type": "update", "status": "failed"}
    assert conflict.json()["document"]["document_id"] == value["document"]["id"]
    assert conflict.json()["error"] is None
