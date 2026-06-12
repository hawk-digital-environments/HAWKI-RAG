"""Document preparation helpers for graph ingestion."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(slots=True)
class GraphDocumentInput:
    """Prepared chunk text and source metadata for one graph extraction document."""

    doc_id: str
    chunk_texts: list[str]
    original_chunk_count: int
    original_chars: int
    total_chars: int
    file_path: Any | None

    @property
    def is_empty(self) -> bool:
        return not self.chunk_texts

    @property
    def was_trimmed(self) -> bool:
        return (
            self.original_chunk_count != len(self.chunk_texts)
            or self.original_chars != self.total_chars
        )


def prepare_graph_document(
    doc_id: str,
    parts: list[dict[str, Any]],
    *,
    max_chunks: int,
    max_chars: int,
) -> GraphDocumentInput:
    """Prepare chunk text and source file metadata for one graph document."""

    chunk_texts = [
        part.get("content")
        for part in parts
        if isinstance(part.get("content"), str) and part.get("content").strip()
    ]
    original_chunk_count = len(chunk_texts)
    original_chars = sum(len(text) for text in chunk_texts)

    if max_chunks > 0 and len(chunk_texts) > max_chunks:
        chunk_texts = chunk_texts[:max_chunks]
    if max_chars > 0:
        chunk_texts = _trim_texts_to_chars(chunk_texts, max_chars=max_chars)

    first_payload = (parts[0] or {}).get("payload") if parts else {}
    file_path = None
    if isinstance(first_payload, dict):
        file_path = first_payload.get("file_path") or first_payload.get("page_url") or first_payload.get("source_url")

    return GraphDocumentInput(
        doc_id=doc_id,
        chunk_texts=chunk_texts,
        original_chunk_count=original_chunk_count,
        original_chars=original_chars,
        total_chars=sum(len(text) for text in chunk_texts),
        file_path=file_path,
    )


def _trim_texts_to_chars(chunk_texts: list[str], *, max_chars: int) -> list[str]:
    trimmed: list[str] = []
    total = 0
    for text in chunk_texts:
        if total >= max_chars:
            break
        remaining = max_chars - total
        if len(text) > remaining:
            trimmed.append(text[:remaining])
            break
        trimmed.append(text)
        total += len(text)
    return trimmed


__all__ = ["GraphDocumentInput", "prepare_graph_document"]
