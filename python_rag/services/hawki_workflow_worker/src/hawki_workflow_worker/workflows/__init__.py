"""Workflow definitions registered by the HAWKI workflow worker."""

from hawki_workflow_worker.workflows.ingest_source import IngestSourceWorkflow
from hawki_workflow_worker.workflows.ingest_text import IngestTextWorkflow

__all__ = ["IngestSourceWorkflow", "IngestTextWorkflow"]
