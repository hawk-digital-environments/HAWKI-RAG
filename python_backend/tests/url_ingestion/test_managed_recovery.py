import asyncio
import hashlib
from io import BytesIO

import pytest
import sqlalchemy as sa

from hawki_backend.managed_documents.input import ManagedInput
from hawki_backend.managed_documents.schema import documents, revisions
from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.recovery import recover_managed
from hawki_backend.url_ingestion.ownership import classify

from managed_documents.conftest import configure, seed
from managed_documents.test_lifecycle import create, revision, writer
from .conftest import request
from .test_lifecycle import event


def failed_managed(env):
    seed(env.engine)
    managed = configure(env.engine, env.root)
    payload = create(managed)
    row = revision(managed, payload)
    # The original managed protocol supplies its own durable writer evidence.
    managed.repository.record_writer(writer(row))
    managed.repository.record_writer(writer(row, action="release"))
    value = event(
        row | {"url_ingestion_operation_id": row["revision_id"]},
        "failed",
        status="failed",
    )
    managed.repository.complete(value, hashlib.sha256(b"failed").hexdigest(), "failed")
    env.app.state.managed_document_service = managed.service
    return managed, row


def test_managed_recovery_uses_existing_replacement_and_replays_one_successor(env):
    managed, row = failed_managed(env)
    assert classify(env.repository, {"job_id": row["job_id"]}) == "managed"
    for _ in range(2):
        response = request(env, f"/v1/pipeline/recovery/jobs/{row['job_id']}/retry")
        assert response.status_code == 200, response.text
        assert response.json()["recovery"]["retried"] == 1
    with env.engine.connect() as connection:
        assert connection.scalar(sa.select(sa.func.count()).select_from(revisions)) == 2
    assert managed.port.start_ingest_workflow.await_count == 2
    env.port.start_ingest_workflow.assert_not_called()
    assert request(env, "/v1/pipeline/tasks", method="GET").json()["tasks"]
    assert (
        request(env, "/v1/pipeline/status/" + row["job_id"], method="GET").json()[
            "status"
        ]
        == "failed"
    )


def test_managed_recovery_failure_never_becomes_reported_success(env):
    managed, row = failed_managed(env)
    managed.port.start_ingest_workflow.side_effect = TimeoutError()
    response = request(env, f"/v1/pipeline/recovery/jobs/{row['job_id']}/retry")
    assert response.status_code == 502
    assert response.json()["success"] is False
    env.port.start_ingest_workflow.assert_not_called()


def test_old_managed_job_cannot_overwrite_a_newer_generation(env):
    managed, row = failed_managed(env)
    asyncio.run(
        managed.service.mutate(
            managed.principal,
            "update",
            ManagedInput(force=True),
            "a.txt",
            BytesIO(b"new"),
            "replacement",
            row["managed_document_id"],
        )
    )
    response = request(env, f"/v1/pipeline/recovery/jobs/{row['job_id']}/retry")
    assert response.status_code == 409
    assert managed.port.start_ingest_workflow.await_count == 2


def test_managed_expected_generation_is_rechecked_under_the_claim_lock(env):
    managed, row = failed_managed(env)
    with env.engine.begin() as connection:
        connection.execute(documents.update().values(status="deleted"))
    with pytest.raises(UrlIngestionError):
        asyncio.run(
            recover_managed(managed.service, managed.principal, row["job_id"], None)
        )
