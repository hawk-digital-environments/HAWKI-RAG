import asyncio

import httpx
import pytest

from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.quiescence import ExternalJobQuiescence

from .conftest import create
from .test_lifecycle import generation


def boundary(env, handler):
    return ExternalJobQuiescence(
        env.repository,
        client_factory=lambda **kwargs: httpx.Client(
            transport=httpx.MockTransport(handler), **kwargs
        ),
    )


@pytest.mark.parametrize(
    "status", ["running", "unknown", "completed", "failed", "cancelled"]
)
def test_workflow_closed_does_not_prove_remote_drainage(env, status):
    old = generation(env, create(env))
    calls = []

    def respond(request):
        calls.append(request.method)
        return httpx.Response(200, json={"job_id": old["job_id"], "status": status})

    env.service.quiescence = boundary(env, respond)
    for _ in range(2):
        with pytest.raises(UrlIngestionError) as caught:
            asyncio.run(env.service.delete(env.principal, old["source_id"], None))
        assert caught.value.status == 202
    env.service.cleanup.delete.assert_not_called()
    env.port.cancel_workflow_and_wait.assert_awaited()
    assert env.repository.source("default", old["source_id"])["status"] == "deleting"
    assert env.repository.source("default", old["source_id"])["generation"] == 2
    if status in {"running", "unknown"}:
        state = env.repository.generation(old["url_ingestion_operation_id"])[
            "external_job"
        ]
        assert state["cancel_requested_at"] is not None
        assert calls == ["GET", "POST", "GET", "POST"]


@pytest.mark.parametrize("status", ["completed", "failed", "cancelled"])
def test_verified_terminal_remote_receipt_allows_cleanup_and_is_durable(env, status):
    old = generation(env, create(env))
    env.service.quiescence = boundary(
        env,
        lambda request: httpx.Response(
            200,
            json={
                "job_id": old["job_id"],
                "status": status,
                "quiescent": True,
                "writer_admission_closed": True,
            },
        ),
    )
    asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    env.service.cleanup.delete.assert_called_once()
    state = env.repository.generation(old["url_ingestion_operation_id"])["external_job"]
    assert state["quiescent"] is True
    assert state["terminal_evidence"]["status"] == status


@pytest.mark.parametrize(
    "failure", ["unreachable", "not_found", "wrong_job", "active_attestation"]
)
def test_unverified_remote_state_blocks_all_cleanup_and_successor_dispatch(
    env, failure
):
    old = generation(env, create(env))

    def respond(request):
        if failure == "unreachable":
            raise httpx.ConnectError("offline")
        return httpx.Response(
            404 if failure == "not_found" else 200,
            json={
                "job_id": "other" if failure == "wrong_job" else old["job_id"],
                "status": "running",
                "quiescent": True,
                "writer_admission_closed": True,
            },
        )

    env.service.quiescence = boundary(env, respond)
    with pytest.raises(UrlIngestionError):
        create(env, "recrawl")
    env.service.cleanup.delete.assert_not_called()
    assert env.port.start_ingest_workflow.await_count == 1


def test_remote_receipt_cannot_publish_into_successor(env):
    old = generation(env, create(env))
    new = generation(env, create(env, "recrawl"))
    env.repository.record_external_job(
        old["url_ingestion_operation_id"],
        {"job_id": old["job_id"], "lifecycle": "completed", "quiescent": True},
    )
    assert (
        env.repository.generation(new["url_ingestion_operation_id"])["external_job"][
            "quiescent"
        ]
        is False
    )
    assert env.repository.source("default", old["source_id"])["generation"] == 2


def test_terminal_receipt_without_restart_fence_never_allows_cleanup(env):
    old = generation(env, create(env))
    env.service.quiescence = boundary(
        env,
        lambda request: httpx.Response(
            200,
            json={"job_id": old["job_id"], "status": "completed", "quiescent": True},
        ),
    )
    with pytest.raises(UrlIngestionError):
        asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    env.service.cleanup.delete.assert_not_called()


def test_retry_after_remote_drainage_reuses_fence_job_and_cancellation_intent(env):
    from pathlib import Path

    old = generation(env, create(env))
    marker = Path(old["workflow_input"]["raw_output_path"]) / "still-owned.txt"
    marker.parent.mkdir(parents=True, exist_ok=True)
    marker.write_text("old output")
    terminal = False
    seen = []

    def respond(request):
        seen.append(str(request.url))
        return httpx.Response(
            200,
            json={
                "job_id": old["job_id"],
                "status": "cancelled" if terminal else "running",
                "quiescent": terminal,
                "writer_admission_closed": terminal,
            },
        )

    env.service.quiescence = boundary(env, respond)
    with pytest.raises(UrlIngestionError):
        asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    assert marker.read_text() == "old output"
    before = env.repository.generation(old["url_ingestion_operation_id"])[
        "external_job"
    ]
    assert before["cancellation_state"] == "acknowledged"
    terminal = True
    asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    assert not marker.exists()
    after = env.repository.generation(old["url_ingestion_operation_id"])["external_job"]
    assert before["cancel_requested_at"] == after["cancel_requested_at"]
    assert after["quiescent"] is True
    assert env.repository.source("default", old["source_id"])["generation"] == 2
    assert all(old["job_id"] in url for url in seen)
    env.service.cleanup.delete.assert_called_once()
