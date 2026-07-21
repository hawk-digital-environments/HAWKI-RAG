"""Ingest-phase Temporal activity entrypoints."""

from __future__ import annotations

import hashlib
from collections import Counter
from pathlib import Path
from typing import Any

from temporalio import activity

from temporal_rag.deduplication import SourceDeduplicationStore, read_plan
from temporal_rag.logging import log_event
from temporal_rag.metadata import AppMetadataStore
from temporal_rag.settings import TemporalRagSettings
from temporal_rag.storage import (
    read_text_file,
    select_markdown_files,
    sha256_text,
    stable_document_id,
    write_manifest,
)


@activity.defn(name="ingest_markdown_files")
def ingest_markdown_files(payload: dict[str, Any]) -> dict[str, Any]:
    from temporal_rag import activities as support

    workflow_input = dict(payload["workflow_input"])
    convert_result = dict(payload["convert_result"])
    deduplication_result = dict(payload.get("deduplication_result") or {})
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    markdown_dir = str(convert_result.get("markdown_dir") or workflow_input["markdown_output_path"])
    manifest_path = str(workflow_input.get("ingest_manifest_path") or "")
    metadata.mark_phase(workflow_input, "ingest_markdown_files", "started", {"markdown_dir": markdown_dir})
    log_event(
        support.logger,
        "ingest_markdown_files:start",
        source_id=source_id,
        markdown_dir=markdown_dir,
        task_queue=settings.ingestion_task_queue,
    )

    plan = read_plan(str(deduplication_result.get("plan_path") or ""))
    store = SourceDeduplicationStore(settings)
    completed_result = store.resume_result(plan)
    if completed_result is not None:
        result = support._ingest_result(source_id, status="success")
        result.update(completed_result)
        result["status"] = "success"
        result["new_documents"] = int(deduplication_result.get("new_documents") or 0)
        result["changed_documents"] = int(deduplication_result.get("updated_documents") or 0)
        result["unchanged_documents"] = int(deduplication_result.get("duplicate_documents") or 0)
        result["document_version"] = deduplication_result.get("document_version")
        result["deduplication"] = deduplication_result
        metadata.mark_phase(workflow_input, "ingest_markdown_files", "success", result)
        log_event(
            support.logger,
            "ingest_markdown_files:resume_completed",
            **result,
            markdown_dir=markdown_dir,
            task_queue=settings.ingestion_task_queue,
        )
        return result

    try:
        selected_files = convert_result.get("markdown_files")
        files = select_markdown_files(
            markdown_dir,
            selected_files if isinstance(selected_files, list) else None,
        )
    except Exception as exc:
        store.mark_failed(plan, str(exc))
        support._record_activity_exception(
            metadata,
            workflow_input,
            "ingest_markdown_files",
            exc,
            markdown_dir=markdown_dir,
        )
        raise

    if not files:
        result = support._ingest_result(source_id, status="skipped")
        result["error_details"] = "No Markdown files were found."
        store.mark_failed(plan, result["error_details"])
        metadata.mark_phase(workflow_input, "ingest_markdown_files", "failed", result)
        return result

    ingest_options = dict(workflow_input.get("ingestion") or {})
    batch_size = max(1, int(ingest_options.get("batch_size") or 64))
    totals = support._empty_totals(source_id)
    manifest_records: list[dict[str, Any]] = []
    handoff_metadata = {
        markdown_file: support._load_passthrough_metadata(markdown_file)
        for markdown_file in files
    }
    dedup_document_counts = Counter(
        str(metadata_value.get("dedup_document_id") or "").strip()
        for metadata_value in handoff_metadata.values()
        if str(metadata_value.get("dedup_document_id") or "").strip()
    )

    try:
        for batch in support._batches(files, batch_size):
            docs: list[dict[str, Any]] = []
            batch_manifest_records: list[dict[str, Any]] = []
            for markdown_file in batch:
                text = read_text_file(markdown_file)
                if not text.strip():
                    totals["skipped_documents"] += 1
                    continue
                passthrough_metadata = handoff_metadata[markdown_file]
                parent_dedup_document_id = str(
                    passthrough_metadata.get("dedup_document_id") or ""
                ).strip()
                doc_id = _document_id_for_markdown(
                    source_id=source_id,
                    markdown_file=markdown_file,
                    markdown_dir=markdown_dir,
                    parent_document_id=parent_dedup_document_id,
                    sibling_count=dedup_document_counts[parent_dedup_document_id],
                )
                content_hash = sha256_text(text)
                relative_path = str(Path(markdown_file).resolve().relative_to(Path(markdown_dir).resolve()))
                neo4j_namespace = ingest_options.get("neo4j_namespace")
                payload = dict(passthrough_metadata or {})
                if parent_dedup_document_id and parent_dedup_document_id != doc_id:
                    payload["dedup_parent_document_id"] = parent_dedup_document_id
                    payload["parent_source_identity"] = payload.get("source_identity")
                    payload["source_identity"] = f"doc:{doc_id}"
                payload["dedup_document_id"] = doc_id
                source_url = (
                    passthrough_metadata.get("source_url")
                    or passthrough_metadata.get("canonical_url")
                    or workflow_input.get("source_url")
                )
                payload.update({
                    "managed_document_id": workflow_input.get("managed_document_id"),
                    "dataset_id": workflow_input.get("dataset_id"),
                    "source_id": source_id,
                    "document_id": doc_id,
                    "doc_id": doc_id,
                    "chunk_id": None,
                    "version": content_hash[:16],
                    "url": source_url,
                    "source_url": source_url,
                    "source_format": "markdown",
                    "relative_path": relative_path,
                    "content_hash": content_hash,
                    "job_id": workflow_input.get("job_id"),
                    "task_id": workflow_input.get("task_id"),
                    "qdrant_collection": ingest_options.get("collection"),
                    "neo4j_namespace": neo4j_namespace,
                })
                docs.append({"id": doc_id, "text": text, "payload": payload})
                manifest_record = {
                    "document_id": doc_id,
                    "relative_path": relative_path,
                    "content_hash": content_hash,
                    "source_content_hash": passthrough_metadata.get("source_content_hash"),
                    "markdown_path": markdown_file,
                }
                if passthrough_metadata:
                    manifest_record["passthrough"] = passthrough_metadata
                batch_manifest_records.append(manifest_record)
                manifest_records.append(manifest_record)

            if not docs:
                continue

            response = support._post_ingest(
                settings,
                workflow_input,
                ingest_options,
                docs,
                force_reprocess=True,
            )
            support._accumulate_ingest_response(totals, response)
            metadata.upsert_documents(workflow_input, batch_manifest_records, response)

        if manifest_path:
            write_manifest(manifest_path, manifest_records)
    except Exception as exc:
        store.mark_failed(plan, str(exc))
        support._record_activity_exception(
            metadata,
            workflow_input,
            "ingest_markdown_files",
            exc,
            markdown_dir=markdown_dir,
        )
        raise

    totals["new_documents"] = int(deduplication_result.get("new_documents") or 0)
    totals["changed_documents"] = int(deduplication_result.get("updated_documents") or 0)
    totals["unchanged_documents"] = int(deduplication_result.get("duplicate_documents") or 0)
    totals["document_version"] = deduplication_result.get("document_version") or hashlib.sha256(
        "|".join(record["content_hash"] for record in manifest_records).encode("utf-8")
    ).hexdigest()[:24]
    totals["deduplication"] = deduplication_result

    if totals["failed_documents"] > 0:
        totals["status"] = "failed"
        totals["error_details"] = "One or more documents failed ingestion."
        store.mark_failed(plan, totals["error_details"])
    elif totals["documents_indexed"] <= 0:
        totals["status"] = "skipped"
        totals["error_details"] = "No non-empty documents were available for ingestion."
        store.mark_failed(plan, totals["error_details"])
    else:
        totals["status"] = "success"
        store.mark_completed(plan, totals)
    metadata.mark_phase(workflow_input, "ingest_markdown_files", totals["status"], totals)
    log_event(
        support.logger,
        "ingest_markdown_files:end",
        **totals,
        markdown_dir=markdown_dir,
        task_queue=settings.ingestion_task_queue,
    )
    return totals


def _document_id_for_markdown(
    *,
    source_id: str,
    markdown_file: str,
    markdown_dir: str,
    parent_document_id: str,
    sibling_count: int,
) -> str:
    if not parent_document_id:
        return stable_document_id(source_id, markdown_file, markdown_dir)
    if sibling_count <= 1:
        return parent_document_id

    relative_path = Path(markdown_file).resolve().relative_to(Path(markdown_dir).resolve()).as_posix()
    digest = hashlib.sha256(f"{parent_document_id}|{relative_path}".encode("utf-8")).hexdigest()[:40]
    return f"doc_{digest}"


@activity.defn(name="mark_source_ready")
def mark_source_ready(payload: dict[str, Any]) -> dict[str, Any]:
    from temporal_rag import activities as support

    workflow_input = dict(payload["workflow_input"])
    ingest_result = dict(payload["ingest_result"])
    deduplication_result = dict(payload.get("deduplication_result") or ingest_result.get("deduplication") or {})
    settings = TemporalRagSettings.from_env()
    result = {
        "source_id": workflow_input.get("source_id"),
        "source_url": workflow_input.get("source_url"),
        "status": "ready" if ingest_result.get("status") == "success" else ingest_result.get("status", "failed"),
        "workflow_status": ingest_result,
        "documents_indexed": int(ingest_result.get("documents_indexed") or 0),
        "chunks_indexed": int(ingest_result.get("chunks_indexed") or 0),
        "vectors_upserted": int(ingest_result.get("vectors_upserted") or 0),
        "graph_records_updated": int(ingest_result.get("graph_records_updated") or 0),
        "failed_documents": int(ingest_result.get("failed_documents") or 0),
        "skipped_documents": int(ingest_result.get("skipped_documents") or 0),
        "document_version": ingest_result.get("document_version"),
        "content_hash": deduplication_result.get("document_version"),
        "deduplication": deduplication_result,
        "error_details": ingest_result.get("error_details"),
    }
    metadata = AppMetadataStore(settings)
    if ingest_result.get("status") == "success":
        metadata.mark_ready(workflow_input, result)
    else:
        metadata.mark_phase(workflow_input, "mark_source_ready", str(result["status"]), result)
    log_event(support.logger, "mark_source_ready:end", **result, task_queue=settings.ingestion_task_queue)
    return result
