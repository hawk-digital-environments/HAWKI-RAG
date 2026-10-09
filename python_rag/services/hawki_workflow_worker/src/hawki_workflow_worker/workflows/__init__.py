"""Workflow definitions registered by the HAWKI workflow worker."""

from hawki_workflow_worker.workflows.ingest_source import IngestSourceWorkflow
from hawki_workflow_worker.workflows.ingest_text import IngestTextWorkflow
from hawki_workflow_worker.workflows.delete_managed_document import DeleteManagedDocumentWorkflow

__all__ = ["IngestSourceWorkflow", "IngestTextWorkflow", "DeleteManagedDocumentWorkflow"]
