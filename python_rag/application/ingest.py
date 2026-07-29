"""
Ingest entrypoints (calls into pipeline.ingest_logic).
"""
import logging
from application.workflows.ingest_logic import ingest_documents, delete_document

logger = logging.getLogger(__name__)

__all__ = ["ingest_documents", "delete_document"]
