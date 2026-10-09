"""Temporal sink cleanup and signed terminal receipt activities."""

from typing import Any

from temporalio import activity
from temporalio.client import Client, WorkflowExecutionStatus
from temporalio.exceptions import ApplicationError

from hawki_pipeline_callbacks import LaravelCallbackClient, LaravelCallbackSettings
from hawki_rag_contracts.pipeline.deletion import (
    DELETE_MANAGED_GRAPH_ACTIVITY, DELETE_MANAGED_VECTORS_ACTIVITY,
    REPORT_MANAGED_DELETION_ACTIVITY, VERIFY_DELETION_WRITERS_ACTIVITY,
    DeleteManagedDocumentInput,
)
from hawki_indexer_worker.adapters.neo4j_writer import create_neo4j_writer
from hawki_indexer_worker.adapters.qdrant_writer import create_qdrant_writer
from hawki_indexer_worker.indexing.managed_deletion import delete_graph, delete_vectors
from hawki_indexer_worker.settings import IndexerSettings


@activity.defn(name=VERIFY_DELETION_WRITERS_ACTIVITY)
async def verify_managed_deletion_writers(payload: dict[str, Any]) -> None:
    """Never clean outputs while their recorded ingestion execution is still running."""
    body = DeleteManagedDocumentInput.model_validate(payload)
    settings = IndexerSettings.from_env()
    client = await Client.connect(settings.temporal_address, namespace=settings.temporal_namespace)
    for writer in body.writers:
        description = await client.get_workflow_handle(writer.workflow_id, run_id=writer.run_id).describe()
        if description.status == WorkflowExecutionStatus.RUNNING:
            raise ApplicationError("Ingestion writer is still running; retry deletion after it closes.")


@activity.defn(name=DELETE_MANAGED_VECTORS_ACTIVITY)
def delete_managed_vectors(payload: dict[str, Any]) -> dict[str, Any]:
    return delete_vectors(payload, create_qdrant_writer())


@activity.defn(name=DELETE_MANAGED_GRAPH_ACTIVITY)
def delete_managed_graph(payload: dict[str, Any]) -> dict[str, Any]:
    return delete_graph(payload, create_neo4j_writer)


@activity.defn(name=REPORT_MANAGED_DELETION_ACTIVITY)
def report_managed_deletion(payload: dict[str, Any]) -> dict[str, Any]:
    settings = IndexerSettings.from_env()
    body = DeleteManagedDocumentInput.model_validate(payload["input"])
    info = activity.info()
    # URL is deployment-owned, never supplied by a deletion request.
    endpoint = settings.callback_url.rsplit("/", 1)[0] + "/managed-deletion-events"
    event = {
        "schema_version": 1,
        "event_id": f"{body.operation_id}:{info.workflow_run_id}:{payload['status']}",
        "operation_id": body.operation_id,
        "workflow_id": info.workflow_id,
        "run_id": info.workflow_run_id,
        "status": payload["status"],
        "results": payload["results"],
    }
    with LaravelCallbackClient(LaravelCallbackSettings(
        endpoint=endpoint, secret=settings.callback_secret,
        timeout_seconds=settings.callback_timeout_seconds,
        retry_attempts=settings.callback_retry_attempts,
    )) as sender:
        return sender.send(event)
