"""Ingest endpoint router."""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

from fastapi import APIRouter  # type: ignore[reportMissingImports]

from app.dependencies import get_provider_or_400
from app.schemas import DocumentUpsertRequest, IngestRequest, apply_ingest_request_settings
from app.settings import AppSettings


def build_ingest_router(
    *,
    logger: logging.Logger,
    rag_service: Any,
    public_dir: Path,
    log_graph_status,
    app_settings: AppSettings,
) -> APIRouter:
    """Build ingest/document management routes."""
    router = APIRouter()

    def get_provider(name: str) -> Any:
        return get_provider_or_400(rag_service, name)

    @router.post("/ingest")
    def ingest(body: IngestRequest) -> dict[str, Any]:
        from app.ingest import ingest_documents

        body = apply_ingest_request_settings(body, app_settings)
        logger.info("api:ingest docs=%s graph=%s", len(body.docs), body.graph)
        if body.graph:
            log_graph_status("ingest_graph")

        return ingest_documents(
            body,
            rag_service=rag_service,
            get_provider=get_provider,
            public_dir=public_dir,
            graph_debug=app_settings.graph_debug,
        )

    @router.delete("/documents/{doc_id}")
    def delete_document_endpoint(doc_id: str) -> dict[str, Any]:
        from app.ingest import delete_document

        logger.info("api:delete doc_id=%s", doc_id)
        result = delete_document(doc_id)
        return {
            "ok": True,
            "doc_id": str(doc_id),
            "qdrant": result["qdrant"],
            "neo4j": result["neo4j"],
        }

    @router.put("/documents/{doc_id}")
    def replace_document(doc_id: str, body: DocumentUpsertRequest) -> dict[str, Any]:
        from app.documents import build_replacement_ingest_request
        from app.ingest import delete_document, ingest_documents

        deletion = delete_document(doc_id)
        ingest_request = build_replacement_ingest_request(
            doc_id=doc_id,
            body=body,
            app_settings=app_settings,
        )

        ingest_response = ingest_documents(
            ingest_request,
            rag_service=rag_service,
            get_provider=get_provider,
            public_dir=public_dir,
            graph_debug=app_settings.graph_debug,
        )
        ingest_response["replaced_doc_id"] = str(doc_id)
        ingest_response["deleted"] = deletion
        logger.info("api:replace doc_id=%s", doc_id)
        return ingest_response

    return router
