"""Document text extraction shared by reranker adapters."""

from typing import Any

from hawki_rag_text.safety import strip_control_characters


def extract_rerank_documents(candidates: list[dict[str, Any]]) -> list[str]:
    """Extract one bounded document per candidate in input order."""
    documents: list[str] = []
    for hit in candidates:
        payload = hit.get("payload") or {}
        text = (
            payload.get("snippet")
            or payload.get("content")
            or payload.get("title")
            or ""
        )
        documents.append(strip_control_characters(text[:2000]))
    return documents
