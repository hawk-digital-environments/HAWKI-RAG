"""Typed ingestion models used by the ingestion pipeline."""
from __future__ import annotations

from typing import Any, TypedDict


class IngestPayload(TypedDict, total=False):
    doc_id: str
    chunk_index: int
    title: str
    source_url: str
    page_url: str
    source_format: str
    file_path: str
    component_type: str
    job_id: str
    trace_id: str


class IngestChunkRecord(TypedDict):
    doc_id: str
    content: str
    payload: IngestPayload


class IngestDocumentStats(TypedDict, total=False):
    total_docs: int
    processed_docs: int
    skipped_docs: int
    by_format: dict[str, int]
    doc_ids: list[str]
    validation_failures: list[dict[str, Any]]
    validation_warnings: list[dict[str, Any]]
    chunks_per_doc: dict[str, int]
    total_chunks: int
    embedding_failures: list[dict[str, Any]]
    embedding_failed_chunks: int
    embedding_failed_docs: int
    embedding_skipped_docs: int
