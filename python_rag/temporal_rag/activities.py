"""Temporal activities for source ingestion orchestration."""

from __future__ import annotations

import hashlib
import logging
from pathlib import Path
import time
from typing import Any
from urllib.parse import urljoin

import requests
from temporalio import activity

from temporal_rag.external_clients import ExternalJobClient
from temporal_rag.logging import log_event
from temporal_rag.metadata import AppMetadataStore
from temporal_rag.settings import TemporalRagSettings
from temporal_rag.storage import (
    list_markdown_files,
    read_text_file,
    sha256_text,
    stable_document_id,
    write_manifest,
)

logger = logging.getLogger(__name__)


@activity.defn(name="scrape_source")
def scrape_source(workflow_input: dict[str, Any]) -> dict[str, Any]:
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    raw_dir = str(workflow_input["raw_output_path"])
    service_config = _service_config(workflow_input, "scraper", settings)
    metadata.mark_phase(workflow_input, "scrape_source", "started", {"raw_dir": raw_dir})
    log_event(logger, "scrape_source:start", source_id=source_id, raw_dir=raw_dir, task_queue=settings.scraper_task_queue)

    try:
        client = ExternalJobClient(**service_config)
        response = client.start_and_wait({
            "source_id": source_id,
            "source_url": workflow_input["source_url"],
            "output_path": raw_dir,
        })
    except Exception as exc:
        _record_activity_exception(metadata, workflow_input, "scrape_source", exc, raw_dir=raw_dir)
        raise

    status = _status(response)
    result = {
        "source_id": source_id,
        "external_job_id": response.get("external_job_id"),
        "raw_dir": response.get("raw_dir") or response.get("raw_output_path") or raw_dir,
        "files_found": int(response.get("files_found") or response.get("file_count") or 0),
        "status": status,
        "error_details": response.get("error") or response.get("error_details"),
    }
    metadata.mark_phase(workflow_input, "scrape_source", status, result)
    log_event(logger, "scrape_source:end", **result, task_queue=settings.scraper_task_queue)
    return result


@activity.defn(name="inspect_and_convert_files")
def inspect_and_convert_files(payload: dict[str, Any]) -> dict[str, Any]:
    workflow_input = dict(payload["workflow_input"])
    scrape_result = dict(payload["scrape_result"])
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    raw_dir = str(scrape_result.get("raw_dir") or workflow_input["raw_output_path"])
    markdown_dir = str(workflow_input["markdown_output_path"])
    service_config = _service_config(workflow_input, "converter", settings)
    metadata.mark_phase(workflow_input, "inspect_and_convert_files", "started", {"raw_dir": raw_dir, "markdown_dir": markdown_dir})
    log_event(logger, "inspect_and_convert_files:start", source_id=source_id, raw_dir=raw_dir, markdown_dir=markdown_dir, task_queue=settings.converter_task_queue)

    try:
        client = ExternalJobClient(**service_config)
        response = client.start_and_wait({
            "source_id": source_id,
            "raw_dir": raw_dir,
            "markdown_dir": markdown_dir,
        })
    except Exception as exc:
        _record_activity_exception(
            metadata,
            workflow_input,
            "inspect_and_convert_files",
            exc,
            raw_dir=raw_dir,
            markdown_dir=markdown_dir,
        )
        raise

    status = _status(response)
    result = {
        "source_id": source_id,
        "external_job_id": response.get("external_job_id"),
        "markdown_dir": response.get("markdown_dir") or response.get("markdown_output_path") or markdown_dir,
        "markdown_files_created": int(response.get("markdown_files_created") or response.get("file_count") or 0),
        "status": status,
        "error_details": response.get("error") or response.get("error_details"),
    }
    metadata.mark_phase(workflow_input, "inspect_and_convert_files", status, result)
    log_event(logger, "inspect_and_convert_files:end", **result, task_queue=settings.converter_task_queue)
    return result


@activity.defn(name="ingest_markdown_files")
def ingest_markdown_files(payload: dict[str, Any]) -> dict[str, Any]:
    workflow_input = dict(payload["workflow_input"])
    convert_result = dict(payload["convert_result"])
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    markdown_dir = str(convert_result.get("markdown_dir") or workflow_input["markdown_output_path"])
    manifest_path = str(workflow_input.get("ingest_manifest_path") or "")
    metadata.mark_phase(workflow_input, "ingest_markdown_files", "started", {"markdown_dir": markdown_dir})
    log_event(logger, "ingest_markdown_files:start", source_id=source_id, markdown_dir=markdown_dir, task_queue=settings.ingestion_task_queue)

    try:
        files = list_markdown_files(markdown_dir)
    except Exception as exc:
        _record_activity_exception(metadata, workflow_input, "ingest_markdown_files", exc, markdown_dir=markdown_dir)
        raise

    if not files:
        result = _ingest_result(source_id, status="skipped")
        result["error_details"] = "No Markdown files were found."
        metadata.mark_phase(workflow_input, "ingest_markdown_files", "failed", result)
        return result

    ingest_options = dict(workflow_input.get("ingestion") or {})
    batch_size = max(1, int(ingest_options.get("batch_size") or 64))
    totals = _empty_totals(source_id)
    manifest_records: list[dict[str, Any]] = []

    try:
        for batch in _batches(files, batch_size):
            docs: list[dict[str, Any]] = []
            for markdown_file in batch:
                text = read_text_file(markdown_file)
                if not text.strip():
                    totals["skipped_documents"] += 1
                    continue
                doc_id = stable_document_id(source_id, markdown_file, markdown_dir)
                content_hash = sha256_text(text)
                relative_path = str(Path(markdown_file).resolve().relative_to(Path(markdown_dir).resolve()))
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
                _delete_existing_document(settings, doc_id, _operation_id(workflow_input, doc_id, "delete"))
                docs.append({"id": doc_id, "text": text, "payload": payload})
                manifest_records.append({
                    "document_id": doc_id,
                    "relative_path": relative_path,
                    "content_hash": content_hash,
                })

            if not docs:
                continue

            response = _post_ingest(settings, workflow_input, ingest_options, docs)
            _accumulate_ingest_response(totals, response)

        if manifest_path:
            write_manifest(manifest_path, manifest_records)
    except Exception as exc:
        _record_activity_exception(metadata, workflow_input, "ingest_markdown_files", exc, markdown_dir=markdown_dir)
        raise

    totals["status"] = "success" if totals["documents_indexed"] > 0 else "skipped"
    totals["document_version"] = hashlib.sha256(
        "|".join(record["content_hash"] for record in manifest_records).encode("utf-8")
    ).hexdigest()[:24]
    metadata.mark_phase(workflow_input, "ingest_markdown_files", totals["status"], totals)
    log_event(logger, "ingest_markdown_files:end", **totals, markdown_dir=markdown_dir, task_queue=settings.ingestion_task_queue)
    return totals


@activity.defn(name="mark_source_ready")
def mark_source_ready(payload: dict[str, Any]) -> dict[str, Any]:
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
    log_event(logger, "mark_source_ready:end", **result, task_queue=settings.ingestion_task_queue)
    return result


def _service_config(workflow_input: dict[str, Any], service: str, settings: TemporalRagSettings) -> dict[str, Any]:
    external = dict(workflow_input.get("external_services") or {})
    if service == "scraper":
        base_url = str(external.get("scraper_url") or settings.scraper_url)
        start_path = str(external.get("scraper_start_path") or settings.scraper_start_path)
        status_path = str(external.get("scraper_status_path") or settings.scraper_status_path)
        token = str(external.get("scraper_token") or settings.scraper_token)
    else:
        base_url = str(external.get("converter_url") or settings.converter_url)
        start_path = str(external.get("converter_start_path") or settings.converter_start_path)
        status_path = str(external.get("converter_status_path") or settings.converter_status_path)
        token = str(external.get("converter_token") or settings.converter_token)

    return {
        "base_url": base_url,
        "start_path": start_path,
        "status_path": status_path,
        "token": token,
        "timeout_seconds": settings.request_timeout_seconds,
        "retry_attempts": settings.http_retry_attempts,
        "poll_interval_seconds": settings.poll_interval_seconds,
        "poll_timeout_seconds": settings.poll_timeout_seconds,
    }


def _status(payload: dict[str, Any]) -> str:
    status = str(payload.get("status") or "running").strip().lower()
    if status in {"completed", "complete", "succeeded", "success", "done", "ready"}:
        return "success"
    if status in {"failed", "error", "timeout", "cancelled", "canceled"}:
        return "failed"
    return status


def _record_activity_exception(
    metadata: AppMetadataStore,
    workflow_input: dict[str, Any],
    phase: str,
    exc: Exception,
    **details: Any,
) -> None:
    result = {
        "source_id": workflow_input.get("source_id"),
        "source_url": workflow_input.get("source_url"),
        "phase": phase,
        "status": "failed",
        "error_details": str(exc),
        **details,
    }
    metadata.mark_phase(workflow_input, phase, "failed", result)
    log_event(logger, f"{phase}:error", **result)


def _empty_totals(source_id: str) -> dict[str, Any]:
    return _ingest_result(source_id, status="running")


def _ingest_result(source_id: str, *, status: str) -> dict[str, Any]:
    return {
        "source_id": source_id,
        "documents_indexed": 0,
        "chunks_indexed": 0,
        "vectors_upserted": 0,
        "graph_records_updated": 0,
        "failed_documents": 0,
        "skipped_documents": 0,
        "status": status,
        "error_details": None,
    }


def _batches(values: list[str], size: int) -> list[list[str]]:
    return [values[index:index + size] for index in range(0, len(values), size)]


def _post_ingest(
    settings: TemporalRagSettings,
    workflow_input: dict[str, Any],
    ingest_options: dict[str, Any],
    docs: list[dict[str, Any]],
) -> dict[str, Any]:
    body = {
        "docs": docs,
        "provider": ingest_options.get("provider") or "ollama",
        "collection": ingest_options.get("collection"),
        "neo4j_database": ingest_options.get("neo4j_database"),
        "chunk_chars": int(ingest_options.get("chunk_chars") or 1200),
        "chunk_overlap": int(ingest_options.get("chunk_overlap") or 250),
        "batch_size": int(ingest_options.get("batch_size") or 64),
        "graph": bool(ingest_options.get("graph", False)),
        "idempotency_key": _operation_id(workflow_input, docs[0]["id"], "ingest"),
    }
    return _bridge_request(settings, "POST", "/ingest", json=body)


def _delete_existing_document(settings: TemporalRagSettings, doc_id: str, operation_id: str) -> None:
    _bridge_request(settings, "DELETE", f"/documents/{doc_id}", headers={"Idempotency-Key": operation_id})


def _bridge_request(settings: TemporalRagSettings, method: str, path: str, **kwargs: Any) -> dict[str, Any]:
    url = urljoin(settings.bridge_url.rstrip("/") + "/", path.lstrip("/"))
    last_error: Exception | None = None
    for attempt in range(1, settings.http_retry_attempts + 1):
        try:
            response = requests.request(method, url, timeout=settings.request_timeout_seconds, **kwargs)
            response.raise_for_status()
            data = response.json()
            if not isinstance(data, dict):
                raise RuntimeError("RAG bridge returned non-object JSON.")
            return data
        except Exception as exc:
            last_error = exc
            if attempt >= settings.http_retry_attempts:
                break
            time.sleep(min(2 ** (attempt - 1), 10))
    raise RuntimeError(f"RAG bridge request failed: {method} {url}: {last_error}") from last_error


def _accumulate_ingest_response(totals: dict[str, Any], response: dict[str, Any]) -> None:
    summary = response.get("summary") if isinstance(response.get("summary"), dict) else {}
    documents = summary.get("documents") if isinstance(summary.get("documents"), dict) else {}
    totals["documents_indexed"] += int(documents.get("processed_docs") or 0)
    totals["skipped_documents"] += int(documents.get("skipped_docs") or 0)
    totals["chunks_indexed"] += int(documents.get("total_chunks") or 0)
    totals["vectors_upserted"] += int(response.get("points") or 0)
    graph_preview = summary.get("graph_preview") if isinstance(summary.get("graph_preview"), dict) else {}
    totals["graph_records_updated"] += _graph_record_count(graph_preview)


def _graph_record_count(graph_preview: dict[str, Any]) -> int:
    nodes = graph_preview.get("nodes")
    edges = graph_preview.get("edges")
    count = 0
    if isinstance(nodes, list):
        count += len(nodes)
    if isinstance(edges, list):
        count += len(edges)
    return count


def _operation_id(workflow_input: dict[str, Any], document_id: str, operation: str) -> str:
    source_id = workflow_input.get("source_id")
    job_id = workflow_input.get("job_id")
    return f"{source_id}:{job_id}:{document_id}:{operation}"


__all__ = [
    "inspect_and_convert_files",
    "ingest_markdown_files",
    "mark_source_ready",
    "scrape_source",
]
