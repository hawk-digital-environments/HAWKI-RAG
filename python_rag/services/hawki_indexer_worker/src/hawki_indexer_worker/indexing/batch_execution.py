"""Execute verified Markdown artifacts as deterministic ingestion batches."""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass
from typing import Any, Protocol

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.pipeline.artifacts import MarkdownArtifact
from hawki_rag_contracts.pipeline.ingestion import IndexResult
from hawki_rag_contracts.pipeline.temporal import INDEX_MARKDOWN_ACTIVITY

from hawki_indexer_worker.indexing.activity_result import IndexActivityAccumulator
from hawki_indexer_worker.indexing.artifact_documents import (
    ArtifactPreparationContext,
    IngestManifestRecord,
    prepare_artifact_batch,
)
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.request import IndexRequest


class IngestDocuments(Protocol):
    """Callable boundary for the in-process vector and graph workflow."""

    def __call__(
        self,
        request: IndexRequest,
        *,
        rag_service: Any,
        get_provider: Callable[[str], Any],
        idempotency_key: str,
        dependencies: IngestWorkflowDependencies,
    ) -> dict[str, Any]: ...


@dataclass(frozen=True, slots=True)
class IndexBatchRequest:
    """Artifact and workflow data needed to execute every index batch."""

    files: list[str]
    workflow_input: dict[str, Any]
    markdown_dir: str
    manifest_path: str
    artifacts_by_path: dict[str, MarkdownArtifact]


@dataclass(frozen=True, slots=True)
class IndexBatchDependencies:
    """Infrastructure used while preparing and ingesting index batches."""

    artifact_store: LocalArtifactStore
    graph_service: Any
    provider_resolver: Callable[[str], Any]
    workflow_dependencies: IngestWorkflowDependencies
    ingest_documents: IngestDocuments
    heartbeat_sender: Callable[[object], None] | None = None


def execute_index_batches(
    request: IndexBatchRequest,
    dependencies: IndexBatchDependencies,
) -> IndexResult:
    """Prepare, ingest and record all Markdown batches for one activity."""

    source_id = str(request.workflow_input["source_id"])
    totals = IndexActivityAccumulator(source_id)
    if not request.files:
        return totals.skipped_result("No Markdown files were found.")

    options = dict(request.workflow_input.get("ingestion") or {})
    batch_size = max(1, int(options.get("batch_size") or 64))
    preparation_context = ArtifactPreparationContext(
        workflow_input=request.workflow_input,
        options=options,
        markdown_dir=request.markdown_dir,
        artifact_store=dependencies.artifact_store,
        artifacts_by_path=request.artifacts_by_path,
    )
    manifest_records: list[IngestManifestRecord] = []

    for batch_index, batch in enumerate(_batches(request.files, batch_size), start=1):
        prepared = prepare_artifact_batch(batch, preparation_context)
        totals.record_skipped_documents(prepared.skipped_documents)
        manifest_records.extend(prepared.manifest_records)
        if not prepared.documents:
            continue

        operation_id = _operation_id(
            request.workflow_input,
            str(prepared.documents[0].id),
            "ingest",
        )
        requires_graph = any(
            document.payload.get("converter_fallback") == "raganything_passthrough"
            for document in prepared.documents
        )
        index_request = IndexRequest.from_options(
            prepared.documents,
            workflow_input=request.workflow_input,
            options=options,
            operation_id=operation_id,
            requires_graph=requires_graph,
        )
        response = dependencies.ingest_documents(
            index_request,
            rag_service=dependencies.graph_service,
            get_provider=dependencies.provider_resolver,
            idempotency_key=operation_id,
            dependencies=dependencies.workflow_dependencies,
        )
        totals.accumulate_response(response)
        if dependencies.heartbeat_sender is not None:
            dependencies.heartbeat_sender(
                {
                    "phase": INDEX_MARKDOWN_ACTIVITY,
                    "batch": batch_index,
                    "documents_indexed": totals.documents_indexed,
                }
            )

    if request.manifest_path:
        dependencies.artifact_store.write_manifest(
            request.manifest_path,
            manifest_records,
        )
    return totals.completed_result(manifest_records)


def _batches(values: list[str], size: int) -> list[list[str]]:
    return [values[index : index + size] for index in range(0, len(values), size)]


def _operation_id(
    workflow_input: dict[str, Any],
    document_id: str,
    operation: str,
) -> str:
    return ":".join(
        [
            str(workflow_input.get("source_id") or ""),
            str(workflow_input.get("job_id") or ""),
            document_id,
            operation,
        ]
    )


__all__ = [
    "IndexBatchDependencies",
    "IndexBatchRequest",
    "IngestDocuments",
    "execute_index_batches",
]
