"""Project a completed index result into source readiness state."""

from __future__ import annotations

import logging
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any

from hawki_observability.event_logging import log_event
from hawki_rag_contracts.pipeline.ingestion import (
    ConvertResult,
    IndexResult,
    IngestionStatus,
    ReadyActivityInput,
)
from hawki_rag_contracts.pipeline.status import MonitorArtifacts, PipelineStageStatus
from hawki_rag_contracts.pipeline.temporal import MARK_SOURCE_READY_ACTIVITY

from hawki_indexer_worker.adapters.laravel_metadata_client import (
    directory_reference,
    manifest_reference,
)
from hawki_indexer_worker.application.context import (
    ActivityExecutionInfo,
    StatusReporter,
)
from hawki_indexer_worker.settings import IndexerSettings

logger = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True)
class ReadyProjectionContext:
    """Runtime collaborators for one readiness projection attempt."""

    settings: IndexerSettings
    status_reporter: StatusReporter
    activity_info: ActivityExecutionInfo


@dataclass(frozen=True, slots=True)
class ReadyProjection:
    """Typed terminal source state returned by the readiness activity."""

    source_id: str
    source_url: str | None
    status: IngestionStatus
    workflow_status: IndexResult
    document_version: str | None
    error_details: str | None

    def to_wire(self) -> dict[str, Any]:
        """Serialize the projection for the Temporal workflow boundary."""

        return {
            "source_id": self.source_id,
            "source_url": self.source_url,
            "status": self.status.value,
            "workflow_status": self.workflow_status.model_dump(mode="json"),
            "documents_indexed": self.workflow_status.documents_indexed,
            "chunks_indexed": self.workflow_status.chunks_indexed,
            "vectors_upserted": self.workflow_status.vectors_upserted,
            "graph_records_updated": self.workflow_status.graph_records_updated,
            "failed_documents": self.workflow_status.failed_documents,
            "skipped_documents": self.workflow_status.skipped_documents,
            "document_version": self.document_version,
            "error_details": self.error_details,
        }


def project_source_ready(
    activity_input: ReadyActivityInput,
    *,
    context: ReadyProjectionContext,
) -> ReadyProjection:
    """Report and return the terminal projection for an index result.

    1. Validate converter and indexer results received from the workflow.
    2. Map the index status to ready, skipped, or failed source state.
    3. Build monitor artifacts and send the terminal signed callback.
    4. Return a typed projection for Temporal boundary serialization.
    """

    workflow_input = dict(activity_input.workflow_input)
    convert_result = ConvertResult.model_validate(
        {
            "source_id": workflow_input["source_id"],
            "status": "success",
            **activity_input.convert_result,
        }
    )
    ingest_result = IndexResult.model_validate(
        {"source_id": workflow_input["source_id"], **activity_input.ingest_result}
    )
    markdown_dir = str(
        convert_result.markdown_dir or workflow_input.get("markdown_output_path") or ""
    )
    manifest_path = str(workflow_input.get("ingest_manifest_path") or "")
    status = (
        IngestionStatus.READY
        if ingest_result.status is IngestionStatus.SUCCESS
        else ingest_result.status
    )
    result = ReadyProjection(
        source_id=str(workflow_input["source_id"]),
        source_url=(
            str(workflow_input["source_url"])
            if workflow_input.get("source_url")
            else None
        ),
        status=status,
        workflow_status=ingest_result,
        document_version=ingest_result.document_version,
        error_details=ingest_result.error_details,
    )
    callback_status = {
        IngestionStatus.READY: PipelineStageStatus.COMPLETED,
        IngestionStatus.SKIPPED: PipelineStageStatus.SKIPPED,
    }.get(result.status, PipelineStageStatus.FAILED)
    error = (
        RuntimeError(result.error_details or result.status.value)
        if callback_status is PipelineStageStatus.FAILED
        else None
    )
    artifacts = [directory_reference(markdown_dir)] if markdown_dir else []
    context.status_reporter(
        context.settings,
        workflow_input,
        activity_id=MARK_SOURCE_READY_ACTIVITY,
        status=callback_status,
        phase=MARK_SOURCE_READY_ACTIVITY,
        total=ingest_result.documents_indexed + ingest_result.skipped_documents,
        processed=ingest_result.documents_indexed,
        failed=ingest_result.failed_documents,
        skipped=ingest_result.skipped_documents,
        metrics=_result_metrics(ingest_result),
        artifacts=artifacts,
        manifest=manifest_reference(manifest_path),
        document_version=result.document_version,
        monitor_artifacts=_monitor_artifacts(workflow_input, ingest_result),
        error=error,
        activity_info=context.activity_info,
    )
    log_event(
        logger,
        "mark_source_ready:end",
        source_id=result.source_id,
        source_url=result.source_url,
        status=result.status.value,
        workflow_status=ingest_result.model_dump(mode="json"),
        documents_indexed=ingest_result.documents_indexed,
        chunks_indexed=ingest_result.chunks_indexed,
        vectors_upserted=ingest_result.vectors_upserted,
        graph_records_updated=ingest_result.graph_records_updated,
        failed_documents=ingest_result.failed_documents,
        skipped_documents=ingest_result.skipped_documents,
        document_version=result.document_version,
        error_details=result.error_details,
        task_queue=context.settings.task_queue,
    )
    return result


def _result_metrics(result: IndexResult) -> dict[str, int]:
    return {
        "chunks_indexed": result.chunks_indexed,
        "vectors_upserted": result.vectors_upserted,
        "graph_records_updated": result.graph_records_updated,
        "new_documents": result.new_documents,
        "changed_documents": result.changed_documents,
        "unchanged_documents": result.unchanged_documents,
    }


def _monitor_artifacts(
    workflow_input: dict[str, Any],
    ingest_result: IndexResult,
) -> MonitorArtifacts:
    summary = ingest_result.ingestion_summary
    if summary is None:
        summary = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "estimate_only": False,
            "documents": {
                "processed_docs": ingest_result.documents_indexed,
                "skipped_docs": ingest_result.skipped_documents,
                "failed_docs": ingest_result.failed_documents,
                "total_chunks": ingest_result.chunks_indexed,
            },
            "graph": {
                "enabled": bool(
                    (workflow_input.get("ingestion") or {}).get("graph", False)
                )
            },
            "dry_run": False,
        }

    return MonitorArtifacts(
        summary=summary,
        graph_preview=ingest_result.graph_preview,
        graph_failures=ingest_result.graph_failures,
    )


__all__ = [
    "ReadyProjection",
    "ReadyProjectionContext",
    "project_source_ready",
]
