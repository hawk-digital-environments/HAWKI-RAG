"""Managed cancellation stops graph batches even when extraction keeps failing."""

from types import SimpleNamespace

import pytest
from temporalio.exceptions import CancelledError

from hawki_indexer_worker.adapters import managed_writer
from hawki_indexer_worker.adapters.composition import build_ingest_workflow_dependencies
from hawki_indexer_worker.indexing.graph_prepare import build_triplets_by_doc
from services.hawki_indexer_worker.tests.unit.activities.managed_writer_test_support import (
    registration,
)


@pytest.mark.parametrize("outcome", ["timeout", "empty", "triplets"])
def test_cancel_during_extraction_stops_before_more_documents_or_graph_writes(
    tmp_path, monkeypatch, outcome
):
    writer = registration(tmp_path)
    cancelled = False
    extracted, receipts = [], []
    monkeypatch.setattr(managed_writer.activity, "in_activity", lambda: True)
    monkeypatch.setattr(managed_writer.activity, "heartbeat", lambda: None)
    monkeypatch.setattr(managed_writer.activity, "is_cancelled", lambda: cancelled)
    monkeypatch.setattr(writer, "send", lambda action, targets: receipts.append(action))
    dependencies = writer.bind_dependencies(build_ingest_workflow_dependencies())

    def extract(*_args, **kwargs):
        nonlocal cancelled
        extracted.append(kwargs["doc_id"])
        cancelled = True
        if outcome == "timeout":
            raise TimeoutError("Graph model did not respond")
        return [("A", "KNOWS", "B")] if outcome == "triplets" else []

    graph = SimpleNamespace(
        upsert_triplets=lambda *_args, **_kwargs: pytest.fail(
            "Write after cancellation"
        ),
        delete_by_doc_id=lambda *_args, **_kwargs: pytest.fail("Cleanup before drain"),
    )
    with pytest.raises(CancelledError):
        build_triplets_by_doc(
            [
                {"doc_id": name, "content": "A knows B", "payload": {}}
                for name in ["one", "two"]
            ],
            "raganything",
            SimpleNamespace(extract_triplets=extract),
            None,
            graph=graph,
            graph_doc_timeout_s=0,
            checkpoint=dependencies.checkpoint,
        )
    assert extracted == ["one"]
    assert receipts == []
    assert not writer.uncertain
    writer.release()
    assert receipts == ["release"]
