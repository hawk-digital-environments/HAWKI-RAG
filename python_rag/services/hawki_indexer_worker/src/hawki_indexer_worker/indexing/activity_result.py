"""Typed accumulation for one index activity execution."""

from __future__ import annotations

import hashlib
from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field
from typing import Any

from hawki_rag_contracts.pipeline.ingestion import IndexResult, IngestionStatus


@dataclass(slots=True)
class IndexActivityAccumulator:
    """Collect batch responses before producing the index activity contract."""

    source_id: str
    documents_indexed: int = 0
    chunks_indexed: int = 0
    vectors_upserted: int = 0
    graph_records_updated: int = 0
    failed_documents: int = 0
    skipped_documents: int = 0
    new_documents: int = 0
    changed_documents: int = 0
    unchanged_documents: int = 0
    ingestion_summary: dict[str, Any] | None = None
    graph_preview: dict[str, Any] | None = None
    graph_failures: list[dict[str, Any]] = field(default_factory=list)

    def record_skipped_document(self) -> None:
        """Record a Markdown artifact that contained no indexable text."""

        self.skipped_documents += 1

    def accumulate_response(self, response: Mapping[str, Any]) -> None:
        """Merge one ingestion batch response into the activity totals."""

        summary_value = response.get("summary")
        summary = summary_value if isinstance(summary_value, dict) else {}
        documents_value = summary.get("documents")
        documents = documents_value if isinstance(documents_value, dict) else {}

        self.documents_indexed += int(documents.get("processed_docs") or 0)
        self.skipped_documents += int(documents.get("skipped_docs") or 0)
        self.new_documents += int(documents.get("incremental_new_docs") or 0)
        self.changed_documents += int(documents.get("incremental_changed_docs") or 0)
        self.unchanged_documents += int(
            documents.get("incremental_unchanged_docs") or 0
        )
        self.chunks_indexed += int(documents.get("total_chunks") or 0)
        self.vectors_upserted += int(response.get("points") or 0)

        summary_graph = summary.get("graph_preview")
        graph = summary_graph if isinstance(summary_graph, dict) else {}
        self.graph_records_updated += sum(
            len(graph.get(key)) if isinstance(graph.get(key), list) else 0
            for key in ("nodes", "edges")
        )
        self.ingestion_summary = summary

        preview = response.get("graph_preview")
        if not isinstance(preview, dict):
            preview = summary_graph
        if isinstance(preview, dict):
            self.graph_preview = preview

        failures = response.get("graph_failures")
        if isinstance(failures, list):
            self.graph_failures.extend(
                failure for failure in failures if isinstance(failure, dict)
            )

    def completed_result(
        self,
        manifest_records: Sequence[Mapping[str, Any]],
    ) -> IndexResult:
        """Return the validated result after every batch has completed."""

        status = (
            IngestionStatus.SUCCESS
            if self.documents_indexed > 0 or self.unchanged_documents > 0
            else IngestionStatus.SKIPPED
        )
        content_hashes = "|".join(
            str(record["content_hash"]) for record in manifest_records
        )
        document_version = hashlib.sha256(content_hashes.encode("utf-8")).hexdigest()[
            :24
        ]
        return self._result(status=status, document_version=document_version)

    def skipped_result(self, reason: str) -> IndexResult:
        """Return a skipped result when the activity has no Markdown artifacts."""

        return self._result(status=IngestionStatus.SKIPPED, error_details=reason)

    def _result(
        self,
        *,
        status: IngestionStatus,
        document_version: str | None = None,
        error_details: str | None = None,
    ) -> IndexResult:
        return IndexResult(
            source_id=self.source_id,
            status=status,
            documents_indexed=self.documents_indexed,
            chunks_indexed=self.chunks_indexed,
            vectors_upserted=self.vectors_upserted,
            graph_records_updated=self.graph_records_updated,
            failed_documents=self.failed_documents,
            skipped_documents=self.skipped_documents,
            new_documents=self.new_documents,
            changed_documents=self.changed_documents,
            unchanged_documents=self.unchanged_documents,
            document_version=document_version,
            error_details=error_details,
            ingestion_summary=self.ingestion_summary,
            graph_preview=self.graph_preview,
            graph_failures=self.graph_failures,
        )


__all__ = ["IndexActivityAccumulator"]
