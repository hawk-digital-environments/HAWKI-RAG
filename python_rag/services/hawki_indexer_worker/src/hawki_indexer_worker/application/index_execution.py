"""Application workflow for one Markdown index activity."""

from __future__ import annotations

import logging
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

from hawki_artifact_store.local import LocalArtifactStore
from hawki_observability.event_logging import log_event
from hawki_rag_contracts.pipeline.ingestion import (
    ConvertResult,
    IndexActivityInput,
    IndexResult,
    shared_storage_root,
)
from hawki_rag_contracts.pipeline.status import PipelineStageStatus
from hawki_rag_contracts.pipeline.temporal import INDEX_MARKDOWN_ACTIVITY

from hawki_indexer_worker.adapters.laravel_metadata_client import directory_reference
from hawki_indexer_worker.application.context import (
    ActivityExecutionInfo,
    StatusReporter,
)
from hawki_indexer_worker.indexing.batch_execution import (
    IndexBatchDependencies,
    IndexBatchRequest,
    IngestDocuments,
    execute_index_batches,
)
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.settings import IndexerSettings

logger = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True)
class IndexActivityContext:
    """Runtime state associated with one Temporal activity attempt."""

    settings: IndexerSettings
    activity_info: ActivityExecutionInfo
    heartbeat_sender: Callable[[object], None] | None = None


@dataclass(frozen=True, slots=True)
class IndexActivityDependencies:
    """External collaborators required by the index activity workflow."""

    artifact_store: LocalArtifactStore | None
    graph_service: Any
    provider_resolver: Callable[[str], Any]
    workflow_dependencies: IngestWorkflowDependencies
    status_reporter: StatusReporter
    ingest_documents: IngestDocuments


def execute_index_activity(
    activity_input: IndexActivityInput,
    *,
    context: IndexActivityContext,
    dependencies: IndexActivityDependencies,
) -> IndexResult:
    """Execute the index activity lifecycle and return its typed result.

    1. Report the activity as running and record its artifact directory.
    2. Validate the converter handoff and resolve its immutable artifacts.
    3. Prepare and execute deterministic vector and graph ingestion batches.
    4. Report failures for Temporal retry handling or return the aggregate result.
    """

    settings = context.settings
    workflow_input = dict(activity_input.workflow_input)
    source_id = str(workflow_input["source_id"])
    markdown_dir = str(
        activity_input.convert_result.get("markdown_dir")
        or workflow_input["markdown_output_path"]
    )
    manifest_path = str(workflow_input.get("ingest_manifest_path") or "")
    dependencies.status_reporter(
        settings,
        workflow_input,
        activity_id=INDEX_MARKDOWN_ACTIVITY,
        status=PipelineStageStatus.RUNNING,
        artifacts=[directory_reference(markdown_dir)],
        activity_info=context.activity_info,
    )
    log_event(
        logger,
        "ingest_markdown_files:start",
        source_id=source_id,
        markdown_dir=markdown_dir,
        task_queue=settings.task_queue,
    )

    try:
        store = dependencies.artifact_store or LocalArtifactStore(
            shared_storage_root(workflow_input)
        )
        convert_result = ConvertResult.model_validate(
            {
                "source_id": workflow_input["source_id"],
                "status": "success",
                **activity_input.convert_result,
            }
        )
        markdown_dir = str(
            convert_result.markdown_dir or workflow_input["markdown_output_path"]
        )
        artifacts_by_path = {
            str(store.resolve(artifact.uri)): artifact
            for artifact in convert_result.artifacts
        }
        files = list(artifacts_by_path)
        if not files:
            files = store.list_markdown(markdown_dir)
        result = execute_index_batches(
            IndexBatchRequest(
                files=files,
                workflow_input=workflow_input,
                markdown_dir=markdown_dir,
                manifest_path=manifest_path,
                artifacts_by_path=artifacts_by_path,
            ),
            IndexBatchDependencies(
                artifact_store=store,
                graph_service=dependencies.graph_service,
                provider_resolver=dependencies.provider_resolver,
                workflow_dependencies=dependencies.workflow_dependencies,
                ingest_documents=dependencies.ingest_documents,
                heartbeat_sender=context.heartbeat_sender,
            ),
        )
    except Exception as exc:
        dependencies.status_reporter(
            settings,
            workflow_input,
            activity_id=INDEX_MARKDOWN_ACTIVITY,
            status=PipelineStageStatus.FAILED,
            artifacts=[directory_reference(markdown_dir)],
            error=exc,
            activity_info=context.activity_info,
        )
        raise

    log_event(
        logger,
        "ingest_markdown_files:end",
        source_id=result.source_id,
        status=result.status.value,
        documents_indexed=result.documents_indexed,
        chunks_indexed=result.chunks_indexed,
        vectors_upserted=result.vectors_upserted,
        graph_records_updated=result.graph_records_updated,
        markdown_dir=markdown_dir,
        task_queue=settings.task_queue,
    )
    return result


__all__ = [
    "IndexActivityContext",
    "IndexActivityDependencies",
    "execute_index_activity",
]
