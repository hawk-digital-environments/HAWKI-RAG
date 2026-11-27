"""Project internal retrieval responses to public evidence."""

from __future__ import annotations

from typing import Any

from hawki_rag_contracts.retrieval.query import PublicQueryResponse, QueryResponse
from hawki_rag_contracts.source_metadata import (
    MetadataValue,
    PUBLIC_METADATA_MODES,
    validate_source_metadata,
)

_PUBLIC_STRINGS = frozenset(
    {
        "content",
        "snippet",
        "text",
        "title",
        "page_title",
        "page_url",
        "url",
        "source_url",
        "source_format",
        "title_text",
        "document_title",
        "page_url_text",
        "markdown_snippet",
        "type",
        "format",
        "parent_url",
        "parent_page_url",
        "checksum_sha256",
        "file_hash",
        "source_id",
        "doc_id",
        "document_id",
        "dataset_id",
        "external_document_id",
        "display_name",
        "content_format",
        "component_type",
        "chunk_id",
        "content_hash",
        "managed_document_id",
    }
)

_PHYSICAL_KEYS = frozenset(
    {
        "neo4j_namespace",
        "neo4jNamespace",
        "qdrant_collection",
        "qdrantCollection",
    }
)


def sanitize_query_response(response: QueryResponse) -> PublicQueryResponse:
    """Project storage hits to the public evidence and source metadata contract."""
    fields = _sanitize_value(
        response.model_dump(
            mode="json", include={"ok", "count", "kg", "answer", "retrieval"}
        )
    )
    hits = []
    for hit in response.hits:
        payload = hit.payload
        evidence = {
            key: value
            for key, value in payload.items()
            if key in _PUBLIC_STRINGS and isinstance(value, str)
        }
        if type(payload.get("chunk_index")) is int:
            evidence["chunk_index"] = payload["chunk_index"]
        tags = payload.get("tags")
        if isinstance(tags, list) and all(isinstance(tag, str) for tag in tags):
            evidence["tags"] = tags
        hits.append(
            {
                "id": hit.id,
                "score": hit.score,
                "payload": evidence,
                "metadata": _user_metadata(payload),
            }
        )
    return PublicQueryResponse.model_validate({**fields, "hits": hits})


def _user_metadata(payload: dict) -> dict[str, MetadataValue]:
    if payload.get("ingestion_mode") not in PUBLIC_METADATA_MODES:
        return {}
    try:
        return validate_source_metadata(payload.get("metadata"))
    except ValueError:
        # Historical points may contain unvalidated recursive metadata.
        return {}


def _sanitize_value(value: Any) -> Any:
    """Recursively remove physical identifier keys from mappings and lists."""

    if isinstance(value, dict):
        return {
            key: _sanitize_value(item)
            for key, item in value.items()
            if key not in _PHYSICAL_KEYS
        }
    if isinstance(value, list):
        return [_sanitize_value(item) for item in value]
    return value


__all__ = ["sanitize_query_response"]
