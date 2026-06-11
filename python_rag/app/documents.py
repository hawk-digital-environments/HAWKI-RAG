"""Document endpoint helpers."""
from __future__ import annotations

from fastapi import HTTPException  # type: ignore[reportMissingImports]

from app.settings import AppSettings
from .schemas import DocumentUpsertRequest, IngestDoc, IngestRequest


def build_replacement_ingest_request(
    doc_id: str,
    body: DocumentUpsertRequest,
    app_settings: AppSettings,
) -> IngestRequest:
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    if not (body.text and body.text.strip()):
        raise HTTPException(status_code=400, detail="text is required to replace a document")

    ingest_doc = IngestDoc(id=doc_id, text=body.text, payload=body.payload)
    return IngestRequest(
        docs=[ingest_doc],
        provider=body.provider or app_settings.rag_default_provider,
        collection=body.collection,
        distance=body.distance or app_settings.qdrant_distance,
        chunk_chars=body.chunk_chars or 3200,
        chunk_overlap=body.chunk_overlap or 250,
        graph=body.graph,
        graph_engine=body.graph_engine or app_settings.graph_engine,
    )
