"""Temporal activities for in-process Markdown indexing."""

from __future__ import annotations

import logging
from collections.abc import Callable
from datetime import datetime, timezone
from typing import Any

from temporalio import activity

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.pipeline.ingestion import (
    ConvertResult,
    IndexActivityInput,
    IndexResult,
    ReadyActivityInput,
    shared_storage_root,
)
from hawki_rag_contracts.pipeline.status import MonitorArtifacts, PipelineStageStatus
from hawki_rag_contracts.pipeline.temporal import (
    INDEX_MARKDOWN_ACTIVITY,
    MARK_SOURCE_READY_ACTIVITY,
)
from hawki_observability.event_logging import log_event

from hawki_indexer_worker.adapters.composition import (
    build_ingest_workflow_dependencies,
)
from hawki_indexer_worker.adapters.laravel_metadata_client import (
    directory_reference,
    manifest_reference,
)
from hawki_indexer_worker.adapters.providers.composition import (
    create_graph_extractor,
    get_provider,
)
from hawki_indexer_worker.adapters.status_callback import report_status
from hawki_indexer_worker.indexing.batch_execution import (
    IndexBatchDependencies,
    IndexBatchRequest,
    execute_index_batches,
)
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.orchestration import ingest_documents
from hawki_indexer_worker.settings import IndexerSettings

logger = logging.getLogger(__name__)
StatusReporter = Callable[..., dict[str, Any]]


@activity.defn(name=INDEX_MARKDOWN_ACTIVITY)
def ingest_markdown_files(payload: dict[str, Any]) -> dict[str, Any]:
    settings = IndexerSettings.from_env()
    info = activity.info()
    result = run_index_activity(
        payload,
        settings=settings,
        artifact_store=None,
        graph_service=create_graph_extractor(
            settings.rag_working_dir,
            logger_obj=logger,
        ),
        provider_resolver=get_provider,
        workflow_dependencies=build_ingest_workflow_dependencies(),
        status_reporter=report_status,
        activity_info=info,
        heartbeat_sender=activity.heartbeat,
    )
    return result.model_dump(mode="json")


def run_index_activity(
    payload: dict[str, Any],
    *,
    settings: IndexerSettings,
    artifact_store: LocalArtifactStore | None,
    graph_service: Any,
    provider_resolver: Callable[[str], Any],
    workflow_dependencies: IngestWorkflowDependencies,
    status_reporter: StatusReporter,
    activity_info: Any,
    heartbeat_sender: Callable[[object], None] | None = None,
) -> IndexResult:
    """Index one activity payload with all external collaborators injectable."""

    activity_input = IndexActivityInput.model_validate(payload)
    workflow_input = dict(activity_input.workflow_input)
    source_id = str(workflow_input["source_id"])
    markdown_dir = str(
        activity_input.convert_result.get("markdown_dir")
        or workflow_input["markdown_output_path"]
    )
    manifest_path = str(workflow_input.get("ingest_manifest_path") or "")
    status_reporter(
        settings,
        workflow_input,
        activity_id=INDEX_MARKDOWN_ACTIVITY,
        status=PipelineStageStatus.RUNNING,
        artifacts=[directory_reference(markdown_dir)],
        activity_info=activity_info,
    )
    log_event(
        logger,
        "ingest_markdown_files:start",
        source_id=source_id,
        markdown_dir=markdown_dir,
        task_queue=settings.task_queue,
    )

    try:
        store = artifact_store or LocalArtifactStore(
            shared_storage_root(workflow_input)
        )
        convert_contract = ConvertResult.model_validate(
            {
                "source_id": workflow_input["source_id"],
                "status": "success",
                **activity_input.convert_result,
            }
        )
        convert_result = convert_contract.model_dump(mode="json")
        markdown_dir = str(
            convert_result.get("markdown_dir") or workflow_input["markdown_output_path"]
        )
        artifacts_by_path = {
            str(store.resolve(artifact.uri)): artifact
            for artifact in convert_contract.artifacts
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
                graph_service=graph_service,
                provider_resolver=provider_resolver,
                workflow_dependencies=workflow_dependencies,
                ingest_documents=ingest_documents,
                heartbeat_sender=heartbeat_sender,
            ),
        )
    except Exception as exc:
        status_reporter(
            settings,
            workflow_input,
            activity_id=INDEX_MARKDOWN_ACTIVITY,
            status=PipelineStageStatus.FAILED,
            artifacts=[directory_reference(markdown_dir)],
            error=exc,
            activity_info=activity_info,
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


def _result_metrics(result: dict[str, Any]) -> dict[str, int]:
    keys = (
        "chunks_indexed",
        "vectors_upserted",
        "graph_records_updated",
        "new_documents",
        "changed_documents",
        "unchanged_documents",
    )
    return {key: int(result.get(key) or 0) for key in keys}


def _monitor_artifacts(
    workflow_input: dict[str, Any], ingest_result: dict[str, Any]
) -> MonitorArtifacts:
    summary = ingest_result.get("ingestion_summary")
    if not isinstance(summary, dict):
        summary = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "estimate_only": False,
            "documents": {
                "processed_docs": int(ingest_result.get("documents_indexed") or 0),
                "skipped_docs": int(ingest_result.get("skipped_documents") or 0),
                "failed_docs": int(ingest_result.get("failed_documents") or 0),
                "total_chunks": int(ingest_result.get("chunks_indexed") or 0),
            },
            "graph": {
                "enabled": bool(
                    (workflow_input.get("ingestion") or {}).get("graph", False)
                )
            },
            "dry_run": False,
        }

    preview = ingest_result.get("graph_preview")
    return MonitorArtifacts.model_validate(
        {
            "summary": summary,
            "graph_preview": preview if isinstance(preview, dict) else None,
            "graph_failures": ingest_result.get("graph_failures") or [],
        }
    )


@activity.defn(name=MARK_SOURCE_READY_ACTIVITY)
def mark_source_ready(payload: dict[str, Any]) -> dict[str, Any]:
    settings = IndexerSettings.from_env()
    return run_mark_source_ready(
        payload,
        settings=settings,
        status_reporter=report_status,
        activity_info=activity.info(),
    )


def run_mark_source_ready(
    payload: dict[str, Any],
    *,
    settings: IndexerSettings,
    status_reporter: StatusReporter,
    activity_info: Any,
) -> dict[str, Any]:
    """Project one completed indexing result without repeating indexing work."""

    activity_input = ReadyActivityInput.model_validate(payload)
    workflow_input = dict(activity_input.workflow_input)
    convert_result = ConvertResult.model_validate(
        {
            "source_id": workflow_input["source_id"],
            "status": "success",
            **activity_input.convert_result,
        }
    ).model_dump(mode="json")
    ingest_result = IndexResult.model_validate(
        {"source_id": workflow_input["source_id"], **activity_input.ingest_result}
    ).model_dump(mode="json")
    markdown_dir = str(
        convert_result.get("markdown_dir")
        or workflow_input.get("markdown_output_path")
        or ""
    )
    manifest_path = str(workflow_input.get("ingest_manifest_path") or "")
    result = {
        "source_id": workflow_input.get("source_id"),
        "source_url": workflow_input.get("source_url"),
        "status": (
            "ready"
            if ingest_result.get("status") == "success"
            else ingest_result.get("status", "failed")
        ),
        "workflow_status": ingest_result,
        **{
            key: int(ingest_result.get(key) or 0)
            for key in (
                "documents_indexed",
                "chunks_indexed",
                "vectors_upserted",
                "graph_records_updated",
                "failed_documents",
                "skipped_documents",
            )
        },
        "document_version": ingest_result.get("document_version"),
        "error_details": ingest_result.get("error_details"),
    }
    callback_status = {
        "ready": PipelineStageStatus.COMPLETED,
        "skipped": PipelineStageStatus.SKIPPED,
    }.get(str(result["status"]), PipelineStageStatus.FAILED)
    error = (
        RuntimeError(str(result["error_details"] or result["status"]))
        if callback_status is PipelineStageStatus.FAILED
        else None
    )
    artifacts = [directory_reference(markdown_dir)] if markdown_dir else []
    status_reporter(
        settings,
        workflow_input,
        activity_id=MARK_SOURCE_READY_ACTIVITY,
        status=callback_status,
        phase=MARK_SOURCE_READY_ACTIVITY,
        total=int(result["documents_indexed"]) + int(result["skipped_documents"]),
        processed=int(result["documents_indexed"]),
        failed=int(result["failed_documents"]),
        skipped=int(result["skipped_documents"]),
        metrics=_result_metrics(result),
        artifacts=artifacts,
        manifest=manifest_reference(manifest_path),
        document_version=result.get("document_version"),
        monitor_artifacts=_monitor_artifacts(workflow_input, ingest_result),
        error=error,
        activity_info=activity_info,
    )
    log_event(
        logger,
        "mark_source_ready:end",
        **result,
        task_queue=settings.task_queue,
    )
    return result


__all__ = [
    "ingest_markdown_files",
    "mark_source_ready",
    "run_index_activity",
    "run_mark_source_ready",
]
