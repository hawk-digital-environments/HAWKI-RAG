"""
Ingest entrypoints (calls into pipeline.ingest_logic).
"""
from pipeline.ingest_logic import ingest_documents, delete_document

__all__ = ["ingest_documents", "delete_document"]
