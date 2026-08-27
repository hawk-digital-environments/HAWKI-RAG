"""Temporal activities for in-process Markdown indexing."""

from __future__ import annotations

import hashlib
import logging
from collections.abc import Callable
from datetime import datetime, timezone
from typing import Any

from temporalio import activity

from hawki_artifact_store.identity import document_id, sha256_text
from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.artifacts import MarkdownArtifact
from hawki_rag_contracts.ingestion import (
    ConvertResult,
    IndexActivityInput,
    IndexResult,
    ReadyActivityInput,
    shared_storage_root,
)
from hawki_rag_text.markdown import strip_leading_converter_markdown_noise
from hawki_rag_contracts.status import MonitorArtifacts, PipelineStageStatus
from hawki_rag_contracts.temporal import (
    INDEX_MARKDOWN_ACTIVITY,
    MARK_SOURCE_READY_ACTIVITY,
)
from hawki_observability.event_logging import log_event

from hawki_indexer_worker.adapters.artifact_store import (
    load_passthrough_metadata,
)
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
from hawki_indexer_worker.domain.models import IngestDocument
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.orchestration import ingest_documents
from hawki_indexer_worker.indexing.request import IndexRequest
from hawki_indexer_worker.settings import IndexerSettings

logger = logging.getLogger(__name__)
StatusReporter = Callable[..., dict[str, Any]]


@activity.defn(name=INDEX_MARKDOWN_ACTIVITY)
def ingest_markdown_files(payload: dict[str, Any]) -> dict[str, Any]:
    settings = IndexerSettings.from_env()
    info = activity.info()
    return run_index_activity(
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
) -> dict[str, Any]:
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
        result = _index_files(
            files,
            workflow_input=workflow_input,
            markdown_dir=markdown_dir,
            manifest_path=manifest_path,
            settings=settings,
            artifact_store=store,
            artifacts_by_path=artifacts_by_path,
            graph_service=graph_service,
            provider_resolver=provider_resolver,
            workflow_dependencies=workflow_dependencies,
            heartbeat_sender=heartbeat_sender,
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
        **result,
        markdown_dir=markdown_dir,
        task_queue=settings.task_queue,
    )
    return IndexResult.model_validate(result).model_dump(mode="json")


def _index_files(
    files: list[str],
    *,
    workflow_input: dict[str, Any],
    markdown_dir: str,
    manifest_path: str,
    settings: IndexerSettings,
    artifact_store: LocalArtifactStore,
    artifacts_by_path: dict[str, MarkdownArtifact],
    graph_service: Any,
    provider_resolver: Callable[[str], Any],
    workflow_dependencies: IngestWorkflowDependencies,
    heartbeat_sender: Callable[[object], None] | None,
) -> dict[str, Any]:
    source_id = str(workflow_input["source_id"])
    if not files:
        result = _empty_result(source_id, status="skipped")
        result["error_details"] = "No Markdown files were found."
        return result

    options = dict(workflow_input.get("ingestion") or {})
    batch_size = max(1, int(options.get("batch_size") or 64))
    totals = _empty_result(source_id, status="running")
    manifest_records: list[dict[str, Any]] = []

    for batch_index, batch in enumerate(_batches(files, batch_size), start=1):
        docs: list[IngestDocument] = []
        for markdown_file in batch:
            markdown_file = str(artifact_store.resolve(markdown_file))
            content = artifact_store.read_bytes(markdown_file)
            text = strip_leading_converter_markdown_noise(content.decode("utf-8"))
            if not text.strip():
                totals["skipped_documents"] += 1
                continue
            document, record = _document_from_artifact(
                workflow_input,
                options,
                markdown_file=markdown_file,
                markdown_dir=markdown_dir,
                text=text,
                content=content,
                artifact=artifacts_by_path.get(markdown_file),
                artifact_store=artifact_store,
            )
            docs.append(document)
            manifest_records.append(record)

        if not docs:
            continue
        operation_id = _operation_id(workflow_input, str(docs[0].id), "ingest")
        requires_graph = any(
            doc.payload.get("converter_fallback") == "raganything_passthrough"
            for doc in docs
        )
        request = IndexRequest.from_options(
            docs,
            workflow_input=workflow_input,
            options=options,
            operation_id=operation_id,
            requires_graph=requires_graph,
        )
        response = ingest_documents(
            request,
            rag_service=graph_service,
            get_provider=provider_resolver,
            idempotency_key=operation_id,
            dependencies=workflow_dependencies,
        )
        _accumulate_response(totals, response)
        if heartbeat_sender is not None:
            heartbeat_sender(
                {
                    "phase": INDEX_MARKDOWN_ACTIVITY,
                    "batch": batch_index,
                    "documents_indexed": totals["documents_indexed"],
                }
            )

    if manifest_path:
        artifact_store.write_manifest(manifest_path, manifest_records)
    totals["status"] = (
        "success"
        if totals["documents_indexed"] > 0 or totals["unchanged_documents"] > 0
        else "skipped"
    )
    totals["document_version"] = hashlib.sha256(
        "|".join(record["content_hash"] for record in manifest_records).encode("utf-8")
    ).hexdigest()[:24]
    return totals


def _document_from_artifact(
    workflow_input: dict[str, Any],
    options: dict[str, Any],
    *,
    markdown_file: str,
    markdown_dir: str,
    text: str,
    content: bytes,
    artifact: MarkdownArtifact | None,
    artifact_store: LocalArtifactStore,
) -> tuple[IngestDocument, dict[str, Any]]:
    source_id = str(workflow_input["source_id"])
    relative_path = artifact_store.relative_path(markdown_file, markdown_dir)
    doc_id = document_id(source_id, relative_path)
    content_hash = sha256_text(text)
    if artifact is not None:
        _verify_artifact(
            artifact,
            source_id=source_id,
            relative_path=relative_path,
            document_id_value=doc_id,
            content_hash=content_hash,
            content=content,
        )
        doc_id = artifact.document_id
        content_hash = artifact.content_hash
    passthrough = load_passthrough_metadata(
        artifact_store,
        markdown_file,
        allowed_directories=(
            markdown_dir,
            str(workflow_input["raw_output_path"]),
        ),
    )
    payload = dict(passthrough)
    payload.update(
        {
            "managed_document_id": workflow_input.get("managed_document_id"),
            "dataset_id": workflow_input.get("dataset_id"),
            "source_id": source_id,
            "document_id": doc_id,
            "doc_id": doc_id,
            "chunk_id": None,
            "version": content_hash[:16],
            "url": workflow_input.get("source_url"),
            "source_url": workflow_input.get("source_url"),
            "source_format": "markdown",
            "relative_path": relative_path,
            "content_hash": content_hash,
            "job_id": workflow_input.get("job_id"),
            "task_id": workflow_input.get("task_id"),
            "qdrant_collection": options.get("collection"),
            "neo4j_namespace": options.get("neo4j_namespace"),
        }
    )
    record: dict[str, Any] = {
        "document_id": doc_id,
        "relative_path": relative_path,
        "content_hash": content_hash,
        "markdown_path": markdown_file,
    }
    if passthrough:
        record["passthrough"] = passthrough
    return IngestDocument(id=doc_id, text=text, payload=payload), record


def _verify_artifact(
    artifact: MarkdownArtifact,
    *,
    source_id: str,
    relative_path: str,
    document_id_value: str,
    content_hash: str,
    content: bytes,
) -> None:
    mismatches: list[str] = []
    if artifact.source_id != source_id:
        mismatches.append("source_id")
    if artifact.relative_path is not None and artifact.relative_path != relative_path:
        mismatches.append("relative_path")
    if artifact.document_id != document_id_value:
        mismatches.append("document_id")
    if artifact.content_hash != content_hash:
        mismatches.append("content_hash")
    if (
        artifact.sha256 is not None
        and artifact.sha256 != hashlib.sha256(content).hexdigest()
    ):
        mismatches.append("sha256")
    if artifact.size_bytes is not None and artifact.size_bytes != len(content):
        mismatches.append("size_bytes")
    if mismatches:
        fields = ", ".join(mismatches)
        raise RuntimeError(
            f"Converted artifact metadata does not match {artifact.uri}: {fields}"
        )


def _empty_result(source_id: str, *, status: str) -> dict[str, Any]:
    return {
        "source_id": source_id,
        "documents_indexed": 0,
        "chunks_indexed": 0,
        "vectors_upserted": 0,
        "graph_records_updated": 0,
        "failed_documents": 0,
        "skipped_documents": 0,
        "new_documents": 0,
        "changed_documents": 0,
        "unchanged_documents": 0,
        "status": status,
        "error_details": None,
        "ingestion_summary": None,
        "graph_preview": None,
        "graph_failures": [],
    }


def _batches(values: list[str], size: int) -> list[list[str]]:
    return [values[index : index + size] for index in range(0, len(values), size)]


def _accumulate_response(totals: dict[str, Any], response: dict[str, Any]) -> None:
    summary = (
        response.get("summary") if isinstance(response.get("summary"), dict) else {}
    )
    documents = (
        summary.get("documents") if isinstance(summary.get("documents"), dict) else {}
    )
    totals["documents_indexed"] += int(documents.get("processed_docs") or 0)
    totals["skipped_documents"] += int(documents.get("skipped_docs") or 0)
    totals["new_documents"] += int(documents.get("incremental_new_docs") or 0)
    totals["changed_documents"] += int(documents.get("incremental_changed_docs") or 0)
    totals["unchanged_documents"] += int(
        documents.get("incremental_unchanged_docs") or 0
    )
    totals["chunks_indexed"] += int(documents.get("total_chunks") or 0)
    totals["vectors_upserted"] += int(response.get("points") or 0)
    graph = (
        summary.get("graph_preview")
        if isinstance(summary.get("graph_preview"), dict)
        else {}
    )
    totals["graph_records_updated"] += sum(
        len(graph.get(key)) if isinstance(graph.get(key), list) else 0
        for key in ("nodes", "edges")
    )
    totals["ingestion_summary"] = summary
    preview = response.get("graph_preview")
    if not isinstance(preview, dict):
        preview = summary.get("graph_preview")
    if isinstance(preview, dict):
        totals["graph_preview"] = preview
    failures = response.get("graph_failures")
    if isinstance(failures, list):
        totals["graph_failures"].extend(
            failure for failure in failures if isinstance(failure, dict)
        )


def _operation_id(
    workflow_input: dict[str, Any], document_id: str, operation: str
) -> str:
    return ":".join(
        [
            str(workflow_input.get("source_id") or ""),
            str(workflow_input.get("job_id") or ""),
            document_id,
            operation,
        ]
    )


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
