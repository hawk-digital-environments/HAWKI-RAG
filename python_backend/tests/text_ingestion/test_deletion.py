"""Verified cleanup gates reuse; a replay cannot erase a newer operation."""

from unittest.mock import Mock
import pytest
from hawki_backend.text_ingestion.vectors import (
    TextVectorDeletion,
    TextVectorDeletionError,
)
from hawki_backend.text_ingestion.settings import TextIngestionSettings
from .conftest import send
from .test_api import event_wire


def ready(env):
    assert send(env).status_code == 202
    row = env.repository.find("default", "key-1")
    assert (
        send(env, path="/internal/text-ingestion/events", **event_wire(row)).status_code
        == 202
    )
    return row


def delete(env, row, key="delete-1"):
    return send(
        env, method="DELETE", path="/v1/text-ingestions/" + row["source_id"], key=key
    )


def test_delete_replay_and_reingestion(env):
    row = ready(env)
    assert delete(env, row).status_code == 200
    assert delete(env, row).json()["replayed"] is True
    assert not (env.root / "sources" / row["source_id"]).exists()
    env.vectors.delete.assert_called_once()
    env.port.cancel_workflow_and_wait.assert_not_awaited()
    assert send(env, key="key-2").status_code == 202
    # A delayed retry of the old DELETE must not cancel the new operation.
    assert delete(env, row).json()["replayed"] is True
    env.vectors.delete.assert_called_once()
    assert env.repository.find("default", "key-2")["lifecycle_status"] == "started"


def test_failed_cleanup_blocks_reingestion_until_verified_retry(env):
    row = ready(env)
    env.vectors.delete.side_effect = RuntimeError("private qdrant address")
    response = delete(env, row)
    assert response.status_code == 502
    assert "private" not in response.text
    assert send(env, key="key-2").status_code == 409
    assert (
        env.repository.find("default", "key-1")["lifecycle_status"] == "delete_failed"
    )
    env.vectors.delete.side_effect = None
    assert delete(env, row).status_code == 200
    assert send(env, key="key-2").status_code == 202


def test_overlapping_cleanup_refused_even_with_same_key(env):
    row = ready(env)
    env.repository.prepare_delete(
        tenant_id="default", source_id=row["source_id"], deletion_key="delete-1"
    )
    assert delete(env, row).status_code == 409
    assert send(env, key="key-2").status_code == 409
    env.vectors.delete.assert_not_called()


def test_uncertain_dispatch_cannot_be_deleted(env):
    env.port.start_text_ingest_workflow.side_effect = TimeoutError()
    assert send(env).status_code == 502
    assert delete(env, env.repository.find("default", "key-1")).status_code == 409
    env.vectors.delete.assert_not_called()


def test_same_content_new_key_after_completion_is_distinct(env):
    row = ready(env)
    response = send(env, key="key-2")
    assert response.status_code == 202
    assert response.json()["workflow_id"] != row["workflow_id"]
    assert response.json()["source_id"] == row["source_id"]


@pytest.mark.parametrize(
    "status,body,count,success",
    [
        (200, {"status": "ok", "result": {"status": "completed"}}, 0, True),
        (200, {"status": "ok", "result": {"status": "acknowledged"}}, 0, False),
        (200, {"status": "ok", "result": {"status": "completed"}}, 1, False),
        (200, {}, 0, False),
        (404, {}, 0, False),
        (503, {}, 0, False),
    ],
)
def test_vector_cleanup_requires_exact_owned_filter_and_verified_zero(
    status, body, count, success
):
    client = Mock()
    client.post.side_effect = [
        Mock(status_code=status, json=lambda: body),
        Mock(
            status_code=200, json=lambda: {"status": "ok", "result": {"count": count}}
        ),
    ]
    vectors = TextVectorDeletion(TextIngestionSettings(), client)
    kwargs = dict(
        collection="owned",
        dataset_id="dataset-a",
        source_id="source-a",
        document_id="doc-a",
        operation_key="key",
    )
    if success:
        vectors.delete(**kwargs)
    else:
        with pytest.raises(TextVectorDeletionError):
            vectors.delete(**kwargs)
    call = client.post.call_args_list[0]
    assert call.kwargs["json"]["filter"]["must"] == [
        {"key": "doc_id", "match": {"value": "doc-a"}},
        {"key": "source_id", "match": {"value": "source-a"}},
        {"key": "dataset_id", "match": {"value": "dataset-a"}},
    ]
    assert all("neo4j" not in str(call) for call in client.post.call_args_list)


def test_failed_event_does_not_prove_writer_closed_and_requires_cleanup(env):
    assert send(env).status_code == 202
    row = env.repository.find("default", "key-1")
    assert (
        send(
            env, path="/internal/text-ingestion/events", **event_wire(row, "failed")
        ).status_code
        == 202
    )
    assert send(env).json()["status"] == "failed"
    assert send(env, key="key-2").status_code == 409
    assert delete(env, row).status_code == 200
    env.port.cancel_workflow_and_wait.assert_awaited_once()
    assert send(env, key="key-2").status_code == 202


def test_configuration_drift_cannot_redirect_replay_or_cleanup(env):
    from hawki_backend.query.settings import QuerySettings, DatasetScope

    row = ready(env)
    old = env.service._query_settings
    env.service._query_settings = QuerySettings(
        {"dataset-a": DatasetScope("new_collection", "ollama", "other-model")},
        old.models,
    )
    assert send(env).json()["status"] == "ready"
    assert send(env, key="key-2").status_code == 409
    assert delete(env, row).status_code == 200
    assert send(env, key="key-2").status_code == 202
    assert env.vectors.delete.call_args.kwargs["collection"] == "owned_collection"


def test_uncertain_replay_uses_original_prepared_scope_after_config_change(env):
    from hawki_backend.query.settings import QuerySettings, DatasetScope

    env.port.start_text_ingest_workflow.side_effect = TimeoutError()
    assert send(env).status_code == 502
    row = env.repository.find("default", "key-1")
    old = env.service._query_settings
    env.service._query_settings = QuerySettings(
        {"dataset-a": DatasetScope("new_collection", "ollama", "other-model")},
        old.models,
    )
    env.port.reconcile_start.return_value = {
        "workflow_id": row["workflow_id"],
        "run_id": "run-1",
    }
    assert send(env).status_code == 200
    env.port.start_text_ingest_workflow.assert_awaited_once()
    # The real writer protocol must acknowledge drainage after reconciliation.
    from .test_writer_fencing import writer

    confirmed = env.repository.find("default", "key-1")
    assert writer(env, confirmed).status_code == 200
    assert writer(env, confirmed, "release").status_code == 200
    assert delete(env, row).status_code == 200
    assert env.vectors.delete.call_args.kwargs["collection"] == "owned_collection"
