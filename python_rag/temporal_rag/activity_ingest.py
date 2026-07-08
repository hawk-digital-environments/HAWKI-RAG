"""Ingest-phase Temporal activity entrypoints."""

from __future__ import annotations

import hashlib
from pathlib import Path
from typing import Any

from temporalio import activity

from temporal_rag.logging import log_event
from temporal_rag.metadata import AppMetadataStore
from temporal_rag.settings import TemporalRagSettings
from temporal_rag.storage import list_markdown_files, read_text_file, sha256_text, stable_document_id, write_manifest


@activity.defn(name="ingest_markdown_files")
def ingest_markdown_files(payload: dict[str, Any]) -> dict[str, Any]:
    from temporal_rag import activities as support

    workflow_input = dict(payload["workflow_input"])
    convert_result = dict(payload["convert_result"])
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    markdown_dir = str(convert_result.get("markdown_dir") or workflow_input["markdown_output_path"])
    manifest_path = str(workflow_input.get("ingest_manifest_path") or "")
    metadata.mark_phase(workflow_input, "ingest_markdown_files", "started", {"markdown_dir": markdown_dir})
    log_event(support.logger, "ingest_markdown_files:start", source_id=source_id, markdown_dir=markdown_dir, task_queue=settings.ingestion_task_queue)

    try:
        files = list_markdown_files(markdown_dir)
    except Exception as exc:
        support._record_activity_exception(metadata, workflow_input, "ingest_markdown_files", exc, markdown_dir=markdown_dir)
        raise

    if not files:
        result = support._ingest_result(source_id, status="skipped")
        result["error_details"] = "No Markdown files were found."
        metadata.mark_phase(workflow_input, "ingest_markdown_files", "failed", result)
        return result

    ingest_options = dict(workflow_input.get("ingestion") or {})
    batch_size = max(1, int(ingest_options.get("batch_size") or 64))
    totals = support._empty_totals(source_id)
    manifest_records: list[dict[str, Any]] = []

    try:
        for batch in support._batches(files, batch_size):
            docs: list[dict[str, Any]] = []
            batch_manifest_records: list[dict[str, Any]] = []
            for markdown_file in batch:
                text = read_text_file(markdown_file)
                if not text.strip():
                    totals["skipped_documents"] += 1
                    continue
                doc_id = stable_document_id(source_id, markdown_file, markdown_dir)
                content_hash = sha256_text(text)
                relative_path = str(Path(markdown_file).resolve().relative_to(Path(markdown_dir).resolve()))
                passthrough_metadata = support._load_passthrough_metadata(markdown_file)
                payload = {
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
                }
                if passthrough_metadata:
                    payload.update(passthrough_metadata)
                docs.append({"id": doc_id, "text": text, "payload": payload})
                manifest_record = {
                    "document_id": doc_id,
                    "relative_path": relative_path,
                    "content_hash": content_hash,
                    "markdown_path": markdown_file,
                }
                if passthrough_metadata:
                    manifest_record["passthrough"] = passthrough_metadata
                batch_manifest_records.append(manifest_record)
                manifest_records.append(manifest_record)

            if not docs:
                continue

            response = support._post_ingest(settings, workflow_input, ingest_options, docs)
            support._accumulate_ingest_response(totals, response)
            metadata.upsert_documents(workflow_input, batch_manifest_records, response)

        if manifest_path:
            write_manifest(manifest_path, manifest_records)
    except Exception as exc:
        support._record_activity_exception(metadata, workflow_input, "ingest_markdown_files", exc, markdown_dir=markdown_dir)
        raise

    totals["status"] = "success" if totals["documents_indexed"] > 0 or totals["unchanged_documents"] > 0 else "skipped"
    totals["document_version"] = hashlib.sha256(
        "|".join(record["content_hash"] for record in manifest_records).encode("utf-8")
    ).hexdigest()[:24]
    metadata.mark_phase(workflow_input, "ingest_markdown_files", totals["status"], totals)
    log_event(support.logger, "ingest_markdown_files:end", **totals, markdown_dir=markdown_dir, task_queue=settings.ingestion_task_queue)
    return totals


@activity.defn(name="mark_source_ready")
def mark_source_ready(payload: dict[str, Any]) -> dict[str, Any]:
    from temporal_rag import activities as support

    workflow_input = dict(payload["workflow_input"])
    ingest_result = dict(payload["ingest_result"])
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
        "error_details": ingest_result.get("error_details"),
    }
    metadata = AppMetadataStore(settings)
    if ingest_result.get("status") == "success":
        metadata.mark_ready(workflow_input, result)
    else:
        metadata.mark_phase(workflow_input, "mark_source_ready", str(result["status"]), result)
    log_event(support.logger, "mark_source_ready:end", **result, task_queue=settings.ingestion_task_queue)
    return result
