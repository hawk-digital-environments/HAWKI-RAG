"""Typed ingestion models used by the ingestion pipeline."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, List, TypedDict


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
    by_format: Dict[str, int]
    doc_ids: List[str]
    validation_failures: List[Dict[str, Any]]
    validation_warnings: List[Dict[str, Any]]
    chunks_per_doc: Dict[str, int]
    total_chunks: int
    embedding_failures: List[Dict[str, Any]]
    embedding_failed_chunks: int
    embedding_failed_docs: int
    embedding_skipped_docs: int


@dataclass(slots=True)
class IngestionSummaryResult:
    ok: bool
    points: int
    graph_only: bool
    summary: Dict[str, Any]

    def to_dict(self) -> Dict[str, Any]:
        return {
            "ok": self.ok,
            "points": self.points,
            "graph_only": self.graph_only,
            "summary": self.summary,
        }

