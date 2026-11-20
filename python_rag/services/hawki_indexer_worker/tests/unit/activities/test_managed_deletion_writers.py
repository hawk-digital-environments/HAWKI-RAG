"""The sink barrier rejects unknown and running writer executions."""

import asyncio
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest
from temporalio.client import WorkflowExecutionStatus
from temporalio.exceptions import ApplicationError

from hawki_indexer_worker.activities import deletion


@pytest.mark.parametrize("status", [WorkflowExecutionStatus.RUNNING, None])
def test_writer_barrier_rejects_execution_without_terminal_proof(monkeypatch, status):
    client = SimpleNamespace(
        get_workflow_handle=Mock(
            return_value=SimpleNamespace(
                describe=AsyncMock(return_value=SimpleNamespace(status=status))
            )
        )
    )
    monkeypatch.setattr(deletion.Client, "connect", AsyncMock(return_value=client))
    monkeypatch.setattr(
        deletion.IndexerSettings,
        "from_env",
        lambda: SimpleNamespace(
            temporal_address="test:7233", temporal_namespace="test"
        ),
    )
    body = {
        "operation_id": "delete_" + "d" * 64,
        "managed_document_id": "adoc_a",
        "dataset_id": "dataset-a",
        "targets": [],
        "writers": [{"workflow_id": "writer-a", "run_id": "writer-run"}],
    }
    with pytest.raises(ApplicationError):
        asyncio.run(deletion.verify_managed_deletion_writers(body))


def test_writer_barrier_reads_the_recorded_execution(monkeypatch):
    description = AsyncMock(
        return_value=SimpleNamespace(status=WorkflowExecutionStatus.COMPLETED)
    )
    client = SimpleNamespace(
        get_workflow_handle=Mock(return_value=SimpleNamespace(describe=description))
    )
    monkeypatch.setattr(deletion.Client, "connect", AsyncMock(return_value=client))
    monkeypatch.setattr(
        deletion.IndexerSettings,
        "from_env",
        lambda: SimpleNamespace(
            temporal_address="test:7233", temporal_namespace="test"
        ),
    )
    body = {
        "operation_id": "delete_" + "d" * 64,
        "managed_document_id": "adoc_a",
        "dataset_id": "dataset-a",
        "targets": [],
        "writers": [{"workflow_id": "writer-a", "run_id": "writer-run"}],
    }
    asyncio.run(deletion.verify_managed_deletion_writers(body))
    client.get_workflow_handle.assert_called_once_with("writer-a", run_id="writer-run")


def rpc_absence(kind="execution"):
    from temporalio.service import RPCError, RPCStatusCode
    from temporalio.api.errordetails.v1 import NotFoundFailure, NamespaceNotFoundFailure
    from google.rpc.status_pb2 import Status

    status = Status(code=5)
    if kind != "untyped":
        status.details.add().Pack(
            NotFoundFailure() if kind == "execution" else NamespaceNotFoundFailure()
        )
    return RPCError(
        "workflow execution not found",
        RPCStatusCode.NOT_FOUND,
        status.SerializeToString(),
    )


@pytest.mark.parametrize(
    "failure",
    [
        "execution",
        "namespace",
        "untyped",
        "text",
        "timeout",
        "connection",
        "http404",
        "malformed",
    ],
)
def test_absence_and_transport_failures_do_not_authorize_cleanup(monkeypatch, failure):
    import httpx

    errors = {
        "execution": rpc_absence(),
        "namespace": rpc_absence("namespace"),
        "untyped": rpc_absence("untyped"),
        "text": RuntimeError("workflow execution not found"),
        "timeout": TimeoutError(),
        "connection": ConnectionError(),
        "http404": httpx.HTTPStatusError(
            "not found",
            request=httpx.Request("GET", "http://wrong"),
            response=httpx.Response(404),
        ),
        "malformed": AttributeError("malformed response"),
    }
    describe = AsyncMock(side_effect=errors[failure])
    client = SimpleNamespace(
        get_workflow_handle=Mock(return_value=SimpleNamespace(describe=describe))
    )
    monkeypatch.setattr(deletion.Client, "connect", AsyncMock(return_value=client))
    monkeypatch.setattr(
        deletion.IndexerSettings,
        "from_env",
        lambda: SimpleNamespace(temporal_address="test", temporal_namespace="test"),
    )
    proof = AsyncMock(side_effect=RuntimeError("approved evidence missing"))
    monkeypatch.setattr(deletion, "require_durable_proof", proof)
    body = {
        "operation_id": "delete_" + "d" * 64,
        "managed_document_id": "adoc_a",
        "dataset_id": "dataset-a",
        "targets": [],
        "writers": [{"workflow_id": "writer-a", "run_id": "writer-run"}],
    }
    with pytest.raises(Exception):
        asyncio.run(deletion.verify_managed_deletion_writers(body))
    assert proof.await_count == (1 if failure == "execution" else 0)


@pytest.mark.parametrize("kind", ["released", "approved_reconciliation"])
def test_genuine_absence_uses_exact_positive_durable_barrier(monkeypatch, kind):
    describe = AsyncMock(side_effect=rpc_absence())
    client = SimpleNamespace(
        get_workflow_handle=Mock(return_value=SimpleNamespace(describe=describe))
    )
    monkeypatch.setattr(deletion.Client, "connect", AsyncMock(return_value=client))
    monkeypatch.setattr(
        deletion.IndexerSettings,
        "from_env",
        lambda: SimpleNamespace(temporal_address="test", temporal_namespace="test"),
    )
    proof = AsyncMock()
    monkeypatch.setattr(deletion, "require_durable_proof", proof)
    body = {
        "operation_id": "delete_" + "d" * 64,
        "managed_document_id": "adoc_a",
        "dataset_id": "dataset-a",
        "targets": [],
        "writers": [{"workflow_id": "writer-a", "run_id": "writer-run"}],
    }
    asyncio.run(deletion.verify_managed_deletion_writers(body))
    proven_body, writer, _ = proof.await_args.args
    assert proven_body.operation_id == body["operation_id"]
    assert writer.workflow_id == "writer-a" and writer.run_id == "writer-run"
