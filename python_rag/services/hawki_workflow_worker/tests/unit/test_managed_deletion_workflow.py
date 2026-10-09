"""Managed deletion uses sink activities and a separate terminal receipt."""

import asyncio
from unittest.mock import AsyncMock

import pytest
from temporalio.exceptions import ActivityError, ApplicationError

from hawki_rag_contracts.pipeline.deletion import (
    DELETE_MANAGED_GRAPH_ACTIVITY, DELETE_MANAGED_VECTORS_ACTIVITY,
    REPORT_MANAGED_DELETION_ACTIVITY, VERIFY_DELETION_WRITERS_ACTIVITY,
)
from hawki_workflow_worker.workflows import delete_managed_document as module


def payload():
    return {"operation_id": "delete_" + "b" * 64, "managed_document_id": "adoc_test", "dataset_id": "dataset-a",
            "targets": [{"output_id": 4, "doc_id": "doc-a", "source_id": "source-a", "collection": "collection-a", "neo4j_namespace": "namespace-a"}],
            "writers": [], "task_queues": {"indexer": "managed-indexer"}}


def test_sink_order_and_separate_completion(monkeypatch):
    execute = AsyncMock(side_effect=[None, {"verified": True}, {"verified": True}, {"ok": True}])
    monkeypatch.setattr(module.workflow, "execute_activity", execute)
    result = asyncio.run(module.DeleteManagedDocumentWorkflow().run(payload()))
    assert result["status"] == "completed"
    assert result["results"][0]["output_id"] == 4
    assert [call.args[0] for call in execute.call_args_list] == [VERIFY_DELETION_WRITERS_ACTIVITY, DELETE_MANAGED_VECTORS_ACTIVITY, DELETE_MANAGED_GRAPH_ACTIVITY, REPORT_MANAGED_DELETION_ACTIVITY]
    assert all(call.kwargs["task_queue"] == "managed-indexer" for call in execute.call_args_list)
    assert execute.call_args_list[1].args[1]["target"]["source_id"] == "source-a"


def test_graph_failure_is_terminal_failure_not_success(monkeypatch):
    error = ActivityError("graph unavailable", scheduled_event_id=1, started_event_id=2, identity="test", activity_type=DELETE_MANAGED_GRAPH_ACTIVITY, activity_id="3", retry_state=None)
    execute = AsyncMock(side_effect=[None, {"verified": True}, error, {"ok": True}])
    monkeypatch.setattr(module.workflow, "execute_activity", execute)
    with pytest.raises(ApplicationError, match="requires recovery"):
        asyncio.run(module.DeleteManagedDocumentWorkflow().run(payload()))
    assert execute.call_args_list[-1].args[1]["status"] == "failed"
    assert execute.call_args_list[-1].args[1]["results"] == []


def test_no_outputs_still_checks_writers_and_reports(monkeypatch):
    execute = AsyncMock(side_effect=[None, {}])
    monkeypatch.setattr(module.workflow, "execute_activity", execute)
    body = payload()
    body["targets"] = []
    result = asyncio.run(module.DeleteManagedDocumentWorkflow().run(body))
    assert result["results"] == []
    assert [call.args[0] for call in execute.call_args_list] == [VERIFY_DELETION_WRITERS_ACTIVITY, REPORT_MANAGED_DELETION_ACTIVITY]
