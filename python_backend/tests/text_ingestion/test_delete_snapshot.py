"""Collection selection, artifact removal and publication share one identity."""

from datetime import timedelta
from pathlib import Path

import pytest
import sqlalchemy as sa

from hawki_backend.text_ingestion import identity
from hawki_backend.text_ingestion.schema import ingest_text_operations
from .conftest import send
from .test_deletion import ready, delete


def test_delayed_delete_selects_b_after_a_was_deleted(env, monkeypatch):
    row = ready(env)
    prepare = env.repository.prepare_delete

    def intervening(**kwargs):
        # A different DELETE finishes A before this DELETE obtains its lock.
        old, _, _ = prepare(
            tenant_id="default", source_id=row["source_id"], deletion_key="other-delete"
        )
        env.repository.finish_delete(old, lambda: None)
        # A new operation owns B. The delayed request had already read A.
        new = dict(row)
        task = identity.task_id(row["dataset_id"], "key-2")
        new.update(
            idempotency_key="key-2",
            task_id=task,
            job_id=identity.job_id(task, row["source_id"]),
            workflow_id=identity.workflow_id(task),
            created_at=row["created_at"] + timedelta(seconds=1),
            fingerprint="b" * 64,
            lifecycle_status="started",
            markdown_path=row["markdown_path"].replace(row["fingerprint"], "b" * 64),
        )
        new["request_metadata"] = {
            **row["request_metadata"],
            "workflow_input": {
                **row["request_metadata"]["workflow_input"],
                "task_id": task,
                "ingestion": {
                    **row["request_metadata"]["workflow_input"]["ingestion"],
                    "collection": "B",
                },
            },
        }
        path = Path(new["markdown_path"])
        path.parent.mkdir(parents=True)
        path.write_text("B content")
        env.repository.claim(new)
        return prepare(**kwargs)

    monkeypatch.setattr(env.repository, "prepare_delete", intervening)
    # Runtime C must also have no influence over the selected persisted B.
    from hawki_backend.query.settings import QuerySettings, DatasetScope

    env.service._query_settings = QuerySettings(
        {"dataset-a": DatasetScope("C", "ollama", "embed")},
        env.service._query_settings.models,
    )
    assert delete(env, row).status_code == 200
    assert env.vectors.delete.call_args.kwargs["collection"] == "B"
    assert env.repository.find("default", "key-2")["lifecycle_status"] == "deleted"
    assert not (env.root / "sources" / row["source_id"]).exists()


@pytest.mark.parametrize("change", ["collection", "path", "fence", "workflow"])
def test_stale_final_snapshot_never_removes_artifacts_or_marks_successor_deleted(
    env, change
):
    row = ready(env)

    def drift(**kwargs):
        current = env.repository.find("default", "key-1")
        metadata = current["request_metadata"]
        values = {}
        if change == "collection":
            metadata["workflow_input"]["ingestion"]["collection"] = "changed"
        elif change == "path":
            values["markdown_path"] = "/different/artifact"
        elif change == "fence":
            metadata["managed_deletion"]["operation_id"] = "new-owner"
        else:
            values["workflow_id"] = "successor-workflow"
        with env.engine.begin() as conn:
            conn.execute(
                sa.update(ingest_text_operations).values(
                    request_metadata=metadata, **values
                )
            )

    env.vectors.delete.side_effect = drift
    assert delete(env, row).status_code == 502
    assert Path(row["markdown_path"]).exists()
    assert env.repository.find("default", "key-1")["lifecycle_status"] == "deleting"
    assert send(env, key="key-2").status_code == 409


def test_artifact_cleanup_uses_selected_historical_path_after_root_drift(env, tmp_path):
    from hawki_backend.text_ingestion.artifacts import TextArtifactStorage

    row = ready(env)
    current = tmp_path / "current-root"
    unrelated = current / "sources" / row["source_id"]
    unrelated.mkdir(parents=True)
    (unrelated / "keep.txt").write_text("different storage generation")
    env.service._artifacts = TextArtifactStorage(str(current))
    assert delete(env, row).status_code == 200
    assert not Path(row["markdown_path"]).exists()
    assert (unrelated / "keep.txt").exists()
