"""URL ingestion extends the existing registration and immutable sink grants."""

from unittest.mock import Mock

import pytest

from hawki_indexer_worker.adapters import managed_writer
from services.hawki_indexer_worker.tests.unit.activities.managed_writer_test_support import (
    registration,
)


def test_url_events_bind_the_operation_dataset_and_generation(tmp_path, monkeypatch):
    writer = registration(tmp_path)
    writer.workflow_input.update(
        owner="url",
        url_ingestion_operation_id="operation",
        generation=2,
        managed_writer_protocol=1,
    )
    sender = Mock()
    sender.__enter__ = Mock(return_value=sender)
    sender.__exit__ = Mock(return_value=False)
    sender.send.return_value = {"success": True, "status": "active", "targets": []}
    monkeypatch.setattr(
        managed_writer, "LaravelCallbackClient", Mock(return_value=sender)
    )
    writer.send("acquire", [])
    event = sender.send.call_args.args[0]
    assert event["owner"] == "url"
    assert event["url_ingestion_operation_id"] == "operation"
    assert event["dataset_id"] == "dataset"
    assert event["generation"] == 2


@pytest.mark.parametrize(
    "changed", ["generation", "url_ingestion_operation_id", "neo4j_database", "owner"]
)
def test_url_grant_cannot_drift_from_the_frozen_generation(tmp_path, changed):
    writer = registration(tmp_path)
    writer.workflow_input.update(
        owner="url",
        url_ingestion_operation_id="operation",
        generation=2,
        managed_writer_protocol=1,
    )
    scope = writer.workflow_input["ingestion"]
    scope["neo4j_database"] = "frozen_database"
    grant = {
        "doc_id": "doc",
        "dataset_id": "dataset",
        "source_id": "source",
        "collection": "historic",
        "neo4j_namespace": None,
        "neo4j_database": "frozen_database",
        "generation": 2,
        "url_ingestion_operation_id": "operation",
        "owner": "url",
    }
    grant[changed] = "wrong"
    writer.send = Mock(
        return_value={"success": True, "status": "active", "targets": [grant]}
    )
    with pytest.raises(RuntimeError, match="differs from its generation"):
        writer.register_targets([{"doc_id": "doc", "relative_path": "a.md"}])
