"""In-process indexing application logic."""

from hawki_indexer_worker.indexing.orchestration import (
    delete_document,
    ingest_documents,
)

__all__ = ["delete_document", "ingest_documents"]
