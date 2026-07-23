"""Ingest endpoint router."""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

from fastapi import APIRouter, Query, Request

from api.http.dependencies import get_provider_or_400
from api.http.schemas import DocumentUpsertRequest, IngestRequest, apply_ingest_request_settings
from api.settings import AppSettings
from domain.ports import ModelProvider


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

    def get_provider(name: str) -> ModelProvider:
        return get_provider_or_400(rag_service, name)

    def _extract_idempotency_key(request: Request, fallback: str | None = None) -> str | None:
        header_value = request.headers.get("Idempotency-Key")
        header_key = header_value.strip() if isinstance(header_value, str) else ""
        fallback_key = fallback.strip() if isinstance(fallback, str) else ""
        return header_key or fallback_key or None

    @router.post("/ingest")
    def ingest(body: IngestRequest, request: Request) -> dict[str, Any]:
        from application.ingest import ingest_documents

        body = apply_ingest_request_settings(body, app_settings)
        request_id = getattr(request.state, "request_id", None)
        idempotency_key = _extract_idempotency_key(request, body.idempotency_key)
        logger.info(
            "api:ingest request_id=%s docs=%s graph=%s idempotency_key=%s",
            request_id,
            len(body.docs),
            body.graph,
            idempotency_key,
        )
        if body.graph:
            log_graph_status("ingest_graph")

        return ingest_documents(
            body,
            rag_service=rag_service,
            get_provider=get_provider,
            public_dir=public_dir,
            graph_debug=app_settings.graph_debug,
            idempotency_key=idempotency_key,
        )

    @router.delete("/documents/{doc_id}")
    def delete_document_endpoint(
        doc_id: str,
        request: Request,
        collection: str | None = Query(default=None),
        neo4j_namespace: str | None = Query(default=None),
    ) -> dict[str, Any]:
        from application.ingest import delete_document

        request_id = getattr(request.state, "request_id", None)
        idempotency_key = _extract_idempotency_key(request, doc_id)
        logger.info(
            "api:delete request_id=%s doc_id=%s idempotency_key=%s collection=%s neo4j_namespace=%s",
            request_id,
            doc_id,
            idempotency_key,
            collection,
            neo4j_namespace,
        )
        result = delete_document(
            doc_id,
            idempotency_key=idempotency_key,
            collection=collection,
            neo4j_namespace=neo4j_namespace,
        )
        return {
            "ok": True,
            "doc_id": str(doc_id),
            "collection": result["qdrant"].get("collection") or collection,
            "neo4j_namespace": result["neo4j"].get("namespace") or neo4j_namespace,
            "qdrant": result["qdrant"],
            "neo4j": result["neo4j"],
        }

    @router.put("/documents/{doc_id}")
    def replace_document(doc_id: str, body: DocumentUpsertRequest, request: Request) -> dict[str, Any]:
        from application.documents import build_replacement_ingest_request
        from application.ingest import delete_document, ingest_documents

        request_id = getattr(request.state, "request_id", None)
        idempotency_key = _extract_idempotency_key(request, body.idempotency_key)
        deletion = delete_document(
            doc_id,
            idempotency_key=idempotency_key,
            collection=body.collection,
            neo4j_namespace=body.neo4j_database,
        )
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
            idempotency_key=idempotency_key,
        )
        ingest_response["replaced_doc_id"] = str(doc_id)
        ingest_response["deleted"] = deletion
        logger.info(
            "api:replace request_id=%s doc_id=%s idempotency_key=%s",
            request_id,
            doc_id,
            idempotency_key,
        )
        return ingest_response

    return router
