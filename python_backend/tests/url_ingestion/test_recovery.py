import asyncio

import pytest
import sqlalchemy as sa

from hawki_backend.url_ingestion.identity import digest
from hawki_backend.url_ingestion.schema import generations
from hawki_backend.url_ingestion.errors import UrlIngestionError

from .conftest import create, request
from .test_lifecycle import event, generation


def fail(env, row):
    value = event(row, "failure", status="failed")
    env.repository.complete(value, digest(value.model_dump(mode="json")), "failed")


@pytest.mark.parametrize("field", ["job_ids", "jobIds"])
def test_selected_recovery_replays_same_owned_successor(env, field):
    old = generation(env, create(env))
    fail(env, old)
    for _ in range(2):
        response = request(
            env, "/v1/pipeline/recovery/jobs/retry-selected", {field: [old["job_id"]]}
        )
        assert response.status_code == 200
        assert response.json()["recovery"]["retried"] == 1
    with env.engine.connect() as connection:
        assert (
            connection.scalar(sa.select(sa.func.count()).select_from(generations)) == 2
        )
    assert env.port.start_ingest_workflow.await_count == 2


def test_unknown_legacy_job_cannot_trigger_partial_recovery(env):
    old = generation(env, create(env))
    fail(env, old)
    response = request(
        env,
        "/v1/pipeline/recovery/jobs/retry-selected",
        {"job_ids": [old["job_id"], "legacy-job"]},
    )
    assert response.status_code == 404
    assert env.port.start_ingest_workflow.await_count == 1


def test_retry_all_and_dataset_scope_use_current_owned_generation(env):
    old = generation(env, create(env))
    fail(env, old)
    response = request(env, "/v1/pipeline/recovery/retry-all")
    assert response.status_code == 200
    assert response.json()["recovery"]["retried"] == 1
    response = request(env, "/v1/pipeline/recovery/datasets/web/retry-failed")
    assert response.status_code == 200
    # Reconcile the existing current operation rather than allocate a third.
    assert env.port.start_ingest_workflow.await_count == 2


def test_recovery_cannot_resurrect_a_deleted_source(env):
    old = generation(env, create(env))
    fail(env, old)
    asyncio.run(env.service.delete(env.principal, old["source_id"], "delete"))
    with pytest.raises(UrlIngestionError):
        asyncio.run(
            env.service.recovery(
                env.principal, "jobs/" + old["job_id"] + "/retry", {}, None
            )
        )


def test_old_failed_job_cannot_replace_a_newer_generation(env):
    old = generation(env, create(env))
    fail(env, old)
    current = generation(env, create(env, "newer-request"))
    response = request(env, "/v1/pipeline/recovery/jobs/" + old["job_id"] + "/retry")
    assert response.status_code == 409
    assert env.repository.source("default", old["source_id"])["generation"] == 2
    assert (
        env.repository.generation(current["url_ingestion_operation_id"])[
            "writer_state"
        ]["managed_writer_gate"]
        == "open"
    )
    assert env.port.start_ingest_workflow.await_count == 2
