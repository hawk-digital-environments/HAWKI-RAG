"""The existing signed writer protocol gates cleanup, not workflow status."""

import hashlib
import hmac
import json
import time
from concurrent.futures import ThreadPoolExecutor
from threading import Event

import pytest

from .conftest import send
from .test_deletion import delete, ready


def writer_event(row, action="acquire", *, attempt=1, targets=None, **changes):
    return (
        dict(
            schema_version=1,
            event_id=f"writer-{action}-{attempt}",
            action=action,
            workflow_id=row["workflow_id"],
            run_id=row["run_id"],
            activity_id="1",
            attempt=attempt,
            source_id=row["source_id"],
            job_id=row["job_id"],
            targets=[] if targets is None else targets,
        )
        | changes
    )


def writer(env, row, action="acquire", **changes):
    body = json.dumps(writer_event(row, action, **changes)).encode()
    stamp = str(int(time.time()))
    signature = hmac.new(
        b"event-test-secret", stamp.encode() + b"." + body, hashlib.sha256
    ).hexdigest()
    return send(
        env,
        path="/internal/text-ingestion/writers",
        content=body,
        headers={"X-Hawki-Timestamp": stamp, "X-Hawki-Signature": "v1=" + signature},
    )


def started(env):
    assert send(env).status_code == 202
    return env.repository.find("default", "key-1")


def test_fence_blocks_cleanup_until_admitted_writer_releases(env):
    row = started(env)
    assert writer(env, row).status_code == 200
    assert delete(env, row).status_code == 502
    env.port.cancel_workflow_and_wait.assert_awaited_once()
    env.vectors.delete.assert_not_called()  # workflow closure is not release
    assert send(env, key="key-2").status_code == 409
    assert writer(env, row, attempt=2).status_code == 409
    assert writer(env, row).status_code == 409  # cached acquisition also fenced
    assert writer(env, row, "release").json()["status"] == "released"
    assert delete(env, row).status_code == 200
    env.vectors.delete.assert_called_once()
    assert writer(env, row, attempt=3).status_code == 409
    assert send(env, key="key-2").status_code == 202
    assert writer(env, row, attempt=4).status_code == 409


def test_admitted_delayed_write_drains_before_verification(env):
    row = started(env)
    assert writer(env, row).status_code == 200
    entered, proceed = Event(), Event()
    points, ordering = [], []

    def delayed():
        entered.set()
        assert proceed.wait(5)
        points.append("owned")
        ordering.append("old writer acknowledged its sink call")
        assert writer(env, row, "release").status_code == 200

    def cleanup(**kwargs):
        assert ordering == ["old writer acknowledged its sink call"]
        points.clear()
        ordering.append("verified zero")

    env.vectors.delete.side_effect = cleanup
    with ThreadPoolExecutor(1) as executor:
        future = executor.submit(delayed)
        assert entered.wait(5)
        try:
            assert delete(env, row).status_code == 502
            assert send(env, key="key-2").status_code == 409
            env.vectors.delete.assert_not_called()
        finally:
            proceed.set()
        future.result(5)
    assert delete(env, row).status_code == 200
    assert points == []
    assert ordering[-1] == "verified zero"
    assert writer(env, row).status_code == 409
    assert send(env, key="key-2").status_code == 202


def test_uncertain_writer_never_expires_or_clears_on_late_release(env):
    row = started(env)
    assert writer(env, row).status_code == 200
    assert writer(env, row, "uncertain").json()["status"] == "uncertain"
    assert writer(env, row, "release").json()["status"] == "uncertain"
    assert delete(env, row).status_code == 502
    assert delete(env, row).status_code == 502
    assert send(env, key="key-2").status_code == 409
    env.vectors.delete.assert_not_called()


@pytest.mark.parametrize(
    "changes",
    [
        {"job_id": "foreign"},
        {"source_id": "foreign"},
        {"run_id": "foreign"},
        {"workflow_id": "unknown"},
        {"targets": [{"doc_id": "foreign", "relative_path": "document.md"}]},
        {
            "targets": [
                {"doc_id": "foreign", "relative_path": "document.md", "graph": True}
            ]
        },
    ],
)
def test_writer_owner_and_target_cannot_be_forged(env, changes):
    row = started(env)
    before = row["request_metadata"]["managed_writers"]
    assert writer(env, row, **changes).status_code == 409
    assert (
        env.repository.find("default", "key-1")["request_metadata"]["managed_writers"]
        == before
    )


def test_writer_hmac_and_age_are_required(env):
    row = started(env)
    before = row["request_metadata"]["managed_writers"]
    body = json.dumps(writer_event(row)).encode()
    for stamp, signature in [(str(int(time.time())), "0" * 64), ("1000000000", None)]:
        if signature is None:
            signature = hmac.new(
                b"event-test-secret", stamp.encode() + b"." + body, hashlib.sha256
            ).hexdigest()
        assert (
            send(
                env,
                path="/internal/text-ingestion/writers",
                content=body,
                headers={
                    "X-Hawki-Timestamp": stamp,
                    "X-Hawki-Signature": "v1=" + signature,
                },
            ).status_code
            == 422
        )
    assert (
        env.repository.find("default", "key-1")["request_metadata"]["managed_writers"]
        == before
    )


@pytest.mark.parametrize(
    "field", ["managed_writer_protocol", "managed_writer_registered", "managed_writers"]
)
def test_unknown_protocol_blocks_verified_deletion(env, field):
    import sqlalchemy as sa
    from hawki_backend.text_ingestion.schema import ingest_text_operations

    row = ready(env)
    metadata = row["request_metadata"]
    if field == "managed_writers":
        metadata[field] = {}
    else:
        metadata.pop(field)
    with env.engine.begin() as conn:
        conn.execute(
            sa.update(ingest_text_operations).values(request_metadata=metadata)
        )
    assert delete(env, row).status_code == 502
    env.vectors.delete.assert_not_called()
