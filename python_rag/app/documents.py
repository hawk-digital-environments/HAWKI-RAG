"""Document endpoint helpers."""
from __future__ import annotations

import os

from fastapi import HTTPException

from .schemas import DocumentUpsertRequest, IngestDoc, IngestRequest


def build_replacement_ingest_request(doc_id: str, body: DocumentUpsertRequest) -> IngestRequest:
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    if not (body.text and body.text.strip()):
        raise HTTPException(status_code=400, detail="text is required to replace a document")

    ingest_doc = IngestDoc(id=doc_id, text=body.text, payload=body.payload)
    return IngestRequest(
        docs=[ingest_doc],
        provider=body.provider or os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"),
        collection=body.collection,
        distance=body.distance or os.environ.get("QDRANT_DISTANCE", "Cosine"),
        chunk_chars=body.chunk_chars or 3200,
        chunk_overlap=body.chunk_overlap or 250,
        graph=body.graph,
        graph_engine=body.graph_engine or os.environ.get("GRAPH_ENGINE", "fallback"),
    )
