"""Deletion must remove owned bytes only after drains and verified sink cleanup."""

from copy import deepcopy
from pathlib import Path

import pytest

from .conftest import send
from .test_lifecycle import create, released, revision


def owned_files(env, row):
    root = env.root / "sources" / row["source_id"]
    root.mkdir(parents=True)
    (root / "converted.md").write_text("converted content")
    return root, Path(row["upload_path"])


def test_delete_removes_source_and_upload_but_preserves_neighbor(env):
    payload = create(env)
    row = revision(env, payload)
    source, upload = owned_files(env, row)
    neighbor = env.root / "sources" / "unrelated"
    neighbor.mkdir()
    (neighbor / "keep").write_bytes(b"preserved")
    released(env, row, graph=False)
    response = send(
        env, path="/v1/documents/" + payload["document"]["id"], method="DELETE"
    )
    assert response.status_code == 200
    assert not source.exists() and not upload.exists()
    assert (neighbor / "keep").read_bytes() == b"preserved"


def test_artifact_failure_cannot_complete_delete_and_same_key_retries(env):
    payload = create(env)
    row = revision(env, payload)
    source, upload = owned_files(env, row)
    original = upload.read_bytes()
    upload.write_bytes(b"unexpected replacement")
    released(env, row, graph=False)
    path = "/v1/documents/" + payload["document"]["id"]
    assert send(env, path=path, method="DELETE").status_code == 502
    assert (
        env.repository.document("default", payload["document"]["id"])["status"]
        == "deleting"
    )
    assert source.exists()
    upload.write_bytes(original)
    assert send(env, path=path, method="DELETE").status_code == 200
    assert not source.exists() and not upload.exists()


@pytest.mark.parametrize(
    "attack",
    [
        "shared_root",
        "source_path",
        "task_id",
        "source_link",
        "child_link",
        "upload_link",
    ],
)
def test_changed_frozen_paths_and_symlinks_never_remove_any_bytes(env, attack):
    row = deepcopy(revision(env, create(env)))
    source, upload = owned_files(env, row)
    other = env.root / "other"
    other.mkdir()
    protected = other / "protected"
    protected.write_bytes(b"preserved")
    if attack == "shared_root":
        row["workflow_input"]["storage"]["shared_root"] = str(other)
    elif attack == "source_path":
        row["workflow_input"]["markdown_output_path"] = str(other)
    elif attack == "task_id":
        row["task_id"] = "../other"
    elif attack == "source_link":
        (source / "converted.md").unlink()
        source.rmdir()
        source.symlink_to(other, target_is_directory=True)
    elif attack == "child_link":
        (source / "link").symlink_to(protected)
    elif attack == "upload_link":
        upload.unlink()
        upload.symlink_to(protected)
    with pytest.raises(ValueError):
        env.service.storage.delete_revision(row)
    assert protected.read_bytes() == b"preserved"
    assert source.exists() and upload.exists()
