"""Publication follows committed ownership, including every crash boundary."""

from pathlib import Path

import pytest
import sqlalchemy as sa

from hawki_backend.managed_documents.schema import documents, mutations, revisions
from hawki_backend.query.settings import DatasetScope, QuerySettings

from .conftest import send
from .test_lifecycle import count, create, ready, released, revision


def test_invalid_graph_scope_never_publishes_even_on_same_key_retry(env):
    env.service.query_settings = QuerySettings(
        {"files": DatasetScope("vectors", "ollama", "embed", None, True)},
        env.service.query_settings.models,
    )
    for _ in range(2):
        response = send(env, data={"dataset_id": "files", "graph": "true"})
        assert response.status_code == 409
    assert not list(env.root.rglob("*"))
    assert count(env, documents) == count(env, revisions) == count(env, mutations) == 0


@pytest.mark.parametrize("purpose", ["create", "update"])
@pytest.mark.parametrize("boundary", ["claim", "publish", "state"])
def test_crash_reconciles_same_owned_destination(env, monkeypatch, purpose, boundary):
    path = "/v1/documents"
    if purpose == "update":
        value = create(env)
        row = revision(env, value)
        released(env, row)
        ready(env, row)
        path += "/" + value["document"]["id"]
    args = dict(
        path=path,
        method="POST" if purpose == "create" else "PUT",
        key="recover",
        data={"dataset_id": "files"} if purpose == "create" else {"force": "true"},
    )
    original_claim = env.repository.claim
    original_store = env.service.storage.store
    original_mark = getattr(env.repository, "mark_artifact_published", None)

    def claim(*a, **kw):
        original_claim(*a, **kw)
        raise SystemExit("after durable claim")

    def store(*a, **kw):
        original_store(*a, **kw)
        raise SystemExit("after final publication")

    def mark(*a, **kw):
        raise RuntimeError("publication state transaction failed")

    with monkeypatch.context() as patch:
        if boundary == "claim":
            patch.setattr(env.repository, "claim", claim)
        elif boundary == "publish":
            patch.setattr(env.service.storage, "store", store)
        else:
            assert original_mark is not None
            patch.setattr(env.repository, "mark_artifact_published", mark)
        if boundary == "state":
            assert send(env, **args).status_code == 502
        else:
            with pytest.raises(SystemExit):
                send(env, **args)
    with env.engine.connect() as connection:
        op = dict(
            connection.execute(
                sa.select(mutations).where(mutations.c.idempotency_key == "recover")
            )
            .mappings()
            .one()
        )
    artifact = op["request"]["artifact"]
    destination = artifact["upload_path"]
    assert artifact["state"] == "prepared"
    assert Path(destination).exists() is (boundary != "claim")
    response = send(env, **args)
    assert response.status_code == 202, response.text
    after = env.repository.mutation(op["mutation_id"])
    assert after["request"]["artifact"]["upload_path"] == destination
    assert after["request"]["artifact"]["state"] == "published"
    assert count(env, mutations) == (1 if purpose == "create" else 2)
    assert Path(destination).read_bytes() == b"hello"


def test_wrong_destination_bytes_fail_closed(env, monkeypatch):
    original = env.repository.claim

    def crash(*a, **kw):
        original(*a, **kw)
        raise SystemExit()

    with monkeypatch.context() as patch:
        patch.setattr(env.repository, "claim", crash)
        with pytest.raises(SystemExit):
            send(env)
    with env.engine.connect() as connection:
        op = connection.execute(sa.select(mutations)).mappings().one()
    path = Path(op["request"]["artifact"]["upload_path"])
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(b"unrelated")
    assert send(env).status_code == 502
    assert path.read_bytes() == b"unrelated"
    env.port.start_ingest_workflow.assert_not_called()


@pytest.mark.parametrize("purpose", ["create", "update"])
def test_transaction_failure_before_claim_commit_cannot_publish(env, purpose):
    path = "/v1/documents"
    if purpose == "update":
        value = create(env)
        row = revision(env, value)
        released(env, row)
        ready(env, row)
        path += "/" + value["document"]["id"]
    before = set(env.root.rglob("*"))

    def fail(conn, cursor, statement, parameters, context, executemany):
        if (
            statement.startswith("INSERT INTO ")
            and "managed_document_mutations" in statement
        ):
            raise RuntimeError("claim failed")

    sa.event.listen(env.engine, "before_cursor_execute", fail)
    try:
        with pytest.raises(RuntimeError, match="claim failed"):
            send(
                env,
                path=path,
                method="POST" if purpose == "create" else "PUT",
                key="failed-claim",
                data={"dataset_id": "files"}
                if purpose == "create"
                else {"force": "true"},
            )
    finally:
        sa.event.remove(env.engine, "before_cursor_execute", fail)
    assert (
        count(env, documents)
        == count(env, revisions)
        == count(env, mutations)
        == (0 if purpose == "create" else 1)
    )
    assert set(env.root.rglob("*")) == before
    if purpose == "update":
        assert (
            env.repository.revision(row["revision_id"])["writer_state"][
                "managed_writer_gate"
            ]
            == "open"
        )


def test_batch_scope_failure_leaves_no_artifact_for_failed_item(env):
    response = send(
        env,
        path="/v1/documents/batch",
        files=[("files[]", ("a.txt", b"hello")), ("files[]", ("b.exe", b"bad"))],
    )
    assert response.status_code == 207
    assert count(env, mutations) == 1
    assert len([p for p in env.root.rglob("*") if p.is_file()]) == 1


@pytest.mark.parametrize("final_exists", [False, True])
def test_owned_staging_copy_is_reconciled_after_a_hard_crash(
    env, monkeypatch, final_exists
):
    original = env.repository.claim

    def crash(*a, **kw):
        original(*a, **kw)
        raise SystemExit()

    with monkeypatch.context() as patch:
        patch.setattr(env.repository, "claim", crash)
        with pytest.raises(SystemExit):
            send(env)
    with env.engine.connect() as connection:
        op = connection.execute(sa.select(mutations)).mappings().one()
    destination = Path(op["request"]["artifact"]["upload_path"])
    destination.parent.mkdir(parents=True, exist_ok=True)
    staging = destination.with_name("." + destination.name + ".publishing")
    staging.write_bytes(b"partial" if not final_exists else b"hello")
    if final_exists:
        destination.write_bytes(b"hello")
    assert send(env).status_code == 202
    assert destination.read_bytes() == b"hello"
    assert not staging.exists()


def test_replacement_scope_failure_does_not_publish_or_fence_old_revision(env):
    value = create(env)
    row = revision(env, value)
    released(env, row)
    ready(env, row)
    before = set(env.root.rglob("*"))
    env.service.query_settings = QuerySettings(
        {"files": DatasetScope("vectors", "ollama", "embed", None, True)},
        env.service.query_settings.models,
    )
    response = send(
        env,
        path="/v1/documents/" + value["document"]["id"],
        method="PUT",
        data={"force": "true", "graph": "true"},
        key="bad-replacement",
    )
    assert response.status_code == 409
    assert set(env.root.rglob("*")) == before
    assert count(env, mutations) == count(env, revisions) == 1
    assert (
        env.repository.revision(row["revision_id"])["writer_state"][
            "managed_writer_gate"
        ]
        == "open"
    )


def test_invalid_custom_profile_does_not_publish_upload_or_credentials(env):
    response = send(
        env,
        data={
            "dataset_id": "files",
            "converter_mode": "custom",
            "converter_token": "secret",
        },
    )
    assert response.status_code == 422
    assert not list(env.root.rglob("*"))
    assert count(env, mutations) == 0


def test_storage_config_drift_cannot_publish_an_unowned_destination(
    env, monkeypatch, tmp_path
):
    from hawki_backend.managed_documents.artifacts import UploadStorage

    original = env.repository.claim

    def crash(*a, **kw):
        original(*a, **kw)
        raise SystemExit()

    with monkeypatch.context() as patch:
        patch.setattr(env.repository, "claim", crash)
        with pytest.raises(SystemExit):
            send(env)
    env.service.storage = UploadStorage(str(tmp_path / "changed-root"))
    assert send(env).status_code == 502
    assert not list(env.root.rglob("*"))
    assert count(env, mutations) == 1


def test_custom_profile_publication_failure_reconciles_owned_paths(env, monkeypatch):
    original = env.service.storage.profile
    data = {
        "dataset_id": "files",
        "converter_mode": "custom",
        "converter_url": "https://converter.example",
        "converter_token": "private-token",
    }

    def fail(*a):
        original(*a)
        raise OSError("unconfirmed profile publication")

    with monkeypatch.context() as patch:
        patch.setattr(env.service.storage, "profile", fail)
        response = send(env, data=data)
        assert response.status_code == 502
        assert "private-token" not in response.text
    with env.engine.connect() as connection:
        op = dict(connection.execute(sa.select(mutations)).mappings().one())
    artifact = op["request"]["artifact"]
    assert artifact["state"] == "prepared"
    assert Path(artifact["profile_path"]).stat().st_mode & 0o777 == 0o600
    assert Path(artifact["upload_path"]).stat().st_mode & 0o444 == 0o444
    assert send(env, data=data).status_code == 202
    assert count(env, mutations) == 1
    assert (
        env.repository.mutation(op["mutation_id"])["request"]["artifact"]["state"]
        == "published"
    )


def test_publication_state_transaction_rollback_retains_recoverable_owner(env):
    def fail(conn, cursor, statement, parameters, context, executemany):
        if (
            statement.startswith("UPDATE ")
            and "managed_document_mutations SET request=" in statement
        ):
            raise RuntimeError("publication-state update failed")

    sa.event.listen(env.engine, "before_cursor_execute", fail)
    try:
        assert send(env).status_code == 502
    finally:
        sa.event.remove(env.engine, "before_cursor_execute", fail)
    with env.engine.connect() as connection:
        op = dict(connection.execute(sa.select(mutations)).mappings().one())
    artifact = op["request"]["artifact"]
    assert artifact["state"] == "prepared"
    assert Path(artifact["upload_path"]).read_bytes() == b"hello"
    response = send(env)
    assert response.status_code == 202
    assert response.json()["document"]["id"] == op["managed_document_id"]
    assert count(env, mutations) == count(env, revisions) == 1
