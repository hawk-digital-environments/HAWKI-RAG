"""Temporal activities for source ingestion orchestration."""

from __future__ import annotations

import hashlib
import io
import json
import logging
from pathlib import Path
import shutil
import time
from typing import Any
from urllib.parse import urljoin
import zipfile

import requests
from temporalio import activity

from temporal_rag.external_clients import ExternalJobClient
from temporal_rag.logging import log_event
from temporal_rag.metadata import AppMetadataStore
from temporal_rag.settings import TemporalRagSettings
from temporal_rag.storage import (
    is_object_prefix,
    list_markdown_files,
    read_text_file,
    sha256_text,
    stable_document_id,
    write_manifest,
)

logger = logging.getLogger(__name__)

PASSTHROUGH_METADATA_FILENAME = "rawki_passthrough.json"
SCRAPER_BOOKKEEPING_FILENAMES = frozenset({
    "crawler.log",
    "job_state.json",
    "summary.json",
    "urls_index.json",
})


class DirectExtractUnsupportedFileError(RuntimeError):
    """The direct converter rejected a file type that RAG-Anything may still parse."""

@activity.defn(name="scrape_source")
def scrape_source(workflow_input: dict[str, Any]) -> dict[str, Any]:
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    raw_dir = str(workflow_input["raw_output_path"])
    service_config = _service_config(workflow_input, "scraper", settings)
    metadata.mark_phase(workflow_input, "scrape_source", "started", {"raw_dir": raw_dir})
    log_event(logger, "scrape_source:start", source_id=source_id, raw_dir=raw_dir, task_queue=settings.scraper_task_queue)

    upload_result = _scrape_uploaded_file(workflow_input, source_id, raw_dir)
    if upload_result is not None:
        metadata.mark_phase(workflow_input, "scrape_source", "success", upload_result)
        log_event(logger, "scrape_source:uploaded_file", **upload_result, task_queue=settings.scraper_task_queue)
        return upload_result

    try:
        client = ExternalJobClient(**service_config)
        start_payload = _scraper_start_payload(workflow_input, source_id, raw_dir)
        response = client.start_and_wait(start_payload)
    except Exception as exc:
        _record_activity_exception(metadata, workflow_input, "scrape_source", exc, raw_dir=raw_dir)
        raise

    result = _scrape_result(response, start_payload, source_id, raw_dir)
    metadata.mark_phase(workflow_input, "scrape_source", str(result["status"]), result)
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
        if _uses_direct_converter(service_config):
            response = _convert_files_with_extract_api(service_config, source_id, raw_dir, markdown_dir)
        else:
            try:
                client = ExternalJobClient(**service_config)
                response = client.start_and_wait({
                    "source_id": source_id,
                    "raw_dir": raw_dir,
                    "markdown_dir": markdown_dir,
                })
            except RuntimeError as exc:
                if not _should_fallback_to_extract_api(exc, service_config):
                    raise

                fallback_config = dict(service_config)
                fallback_config["start_path"] = "/extract"
                response = _convert_files_with_extract_api(fallback_config, source_id, raw_dir, markdown_dir)
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


def _scraper_start_payload(workflow_input: dict[str, Any], source_id: str, raw_dir: str) -> dict[str, Any]:
    metadata = workflow_input.get("metadata")
    request = workflow_input.get("request")
    if not isinstance(request, dict) and isinstance(metadata, dict):
        request = metadata.get("request")
    if not isinstance(request, dict):
        request = {}

    request_metadata = request.get("metadata")
    if not isinstance(request_metadata, dict):
        request_metadata = {}

    payload: dict[str, Any] = {
        "job_id": str(workflow_input.get("job_id") or source_id),
        "url": str(workflow_input.get("source_url") or ""),
        "output_dir": raw_dir,
        "source_id": source_id,
        "source_url": workflow_input.get("source_url"),
    }

    if request_metadata.get("site_profile_path"):
        payload["site_profile_path"] = request_metadata["site_profile_path"]

    sitemap_url = _string_value(request.get("sitemapUrl") or request.get("sitemap_url") or request_metadata.get("sitemap_url"))
    if sitemap_url:
        payload["sitemap"] = True
        payload["sitemap_base"] = sitemap_url

    for key in (
        "rescrape_failed",
        "max_pages",
        "max_concurrency",
        "max_rpm",
        "skip_images",
        "max_images_per_page",
        "max_link_density",
        "discovery_mode",
        "wait_until",
        "page_timeout_ms",
    ):
        if key in request_metadata and request_metadata[key] is not None:
            payload[key] = request_metadata[key]

    return payload


def _shared_worker_path(value: Any) -> str | None:
    if not isinstance(value, str) or not value.strip():
        return None

    path = value.strip()
    for prefix in ("/var/www/html/shared", "/app/shared"):
        if path == prefix:
            return "/shared"
        if path.startswith(prefix + "/"):
            return "/shared/" + path[len(prefix) + 1:]

    return path


def _string_value(value: Any) -> str:
    if isinstance(value, (str, int, float)) and str(value).strip():
        return str(value).strip()

    return ""


def _scrape_result(
    response: dict[str, Any],
    start_payload: dict[str, Any],
    source_id: str,
    raw_dir: str,
) -> dict[str, Any]:
    status = _status(response)
    crawler_raw_dir = _shared_worker_path(
        response.get("raw_dir") or response.get("raw_output_path") or response.get("output_directory")
    )
    output_dir = crawler_raw_dir or raw_dir
    crawled_file_count = _crawled_output_file_count(output_dir)
    pages_crawled = (
        _positive_int(response.get("pages_crawled"))
        or _positive_int(response.get("files_found"))
        or _positive_int(response.get("file_count"))
        or crawled_file_count
        or 0
    )
    page_limit = _positive_int(start_payload.get("max_pages"))
    error_details = response.get("error") or response.get("error_details")
    if status == "success" and pages_crawled <= 0:
        status = "failed"
        error_details = error_details or "Scraper completed without crawled page files."
    elif status == "success" and page_limit is not None and pages_crawled < page_limit:
        status = "failed"
        error_details = error_details or (
            f"Scraper stopped at {pages_crawled}/{page_limit} pages before reaching the configured page limit."
        )

    result = {
        "source_id": source_id,
        "external_job_id": response.get("external_job_id"),
        "raw_dir": output_dir,
        "files_found": pages_crawled,
        "pages_crawled": pages_crawled,
        "raw_files_found": crawled_file_count,
        "status": status,
        "error_details": error_details,
    }

    if page_limit is not None:
        result["max_pages"] = page_limit

    return result


def _crawled_output_file_count(raw_dir: str) -> int:
    if is_object_prefix(raw_dir):
        return 0

    root = Path(raw_dir.removeprefix("file://")).expanduser()
    if not root.is_dir():
        return 0

    return sum(1 for path in root.rglob("*") if path.is_file() and path.name not in SCRAPER_BOOKKEEPING_FILENAMES)


def _positive_int(value: Any) -> int | None:
    if isinstance(value, bool):
        return None

    if isinstance(value, (int, float)):
        integer = int(value)
        return integer if integer > 0 else None

    if isinstance(value, str) and value.strip().isdigit():
        integer = int(value.strip())
        return integer if integer > 0 else None

    return None


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
            batch_manifest_records: list[dict[str, Any]] = []
            for markdown_file in batch:
                text = read_text_file(markdown_file)
                if not text.strip():
                    totals["skipped_documents"] += 1
                    continue
                doc_id = stable_document_id(source_id, markdown_file, markdown_dir)
                content_hash = sha256_text(text)
                relative_path = str(Path(markdown_file).resolve().relative_to(Path(markdown_dir).resolve()))
                passthrough_metadata = _load_passthrough_metadata(markdown_file)
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
                _delete_existing_document(settings, doc_id, _operation_id(workflow_input, doc_id, "delete"))
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

            response = _post_ingest(settings, workflow_input, ingest_options, docs)
            _accumulate_ingest_response(totals, response)
            metadata.upsert_documents(workflow_input, batch_manifest_records, response)

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

    config = {
        "base_url": base_url,
        "start_path": start_path,
        "status_path": status_path,
        "token": token,
        "timeout_seconds": settings.request_timeout_seconds,
        "retry_attempts": settings.http_retry_attempts,
        "poll_interval_seconds": settings.poll_interval_seconds,
        "poll_timeout_seconds": settings.poll_timeout_seconds,
    }

    if service == "converter":
        config.update(_custom_converter_profile_config(workflow_input))

    return config


def _custom_converter_profile_config(workflow_input: dict[str, Any]) -> dict[str, Any]:
    profile_path = workflow_input.get("custom_converter_profile_path")
    if not isinstance(profile_path, str) or not profile_path.strip():
        if str(workflow_input.get("converter_mode") or "").lower() == "custom":
            raise RuntimeError("Custom converter mode was requested without a converter profile path.")
        return {}

    path = Path(profile_path.removeprefix("file://")).expanduser()
    if not path.is_file():
        raise RuntimeError(f"Custom converter profile was not found: {path}")

    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Custom converter profile is unreadable: {path}") from exc

    if not isinstance(payload, dict):
        raise RuntimeError(f"Custom converter profile must be a JSON object: {path}")

    profile = {
        "base_url": _profile_string(payload, "converter_url", "base_url", "url"),
        "start_path": _profile_string(payload, "converter_start_path", "start_path") or "/extract",
        "status_path": _profile_string(payload, "converter_status_path", "status_path") or "",
        "token": _profile_string(payload, "converter_token", "token", "api_key") or "",
    }

    if not profile["base_url"]:
        raise RuntimeError(f"Custom converter profile is missing converter_url: {path}")

    logger.info(
        "converter:custom_profile_loaded path=%s start_path=%s has_status_path=%s has_token=%s",
        path,
        profile["start_path"],
        bool(profile["status_path"]),
        bool(profile["token"]),
    )
    return profile


def _profile_string(payload: dict[str, Any], *keys: str) -> str:
    for key in keys:
        value = payload.get(key)
        if isinstance(value, (str, int, float)) and str(value).strip():
            return str(value).strip()
    return ""


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


def _scrape_uploaded_file(workflow_input: dict[str, Any], source_id: str, raw_dir: str) -> dict[str, Any] | None:
    upload = workflow_input.get("upload")
    if not isinstance(upload, dict):
        return None

    local_path = upload.get("local_path") or upload.get("uploaded_path")
    if not isinstance(local_path, str) or not local_path.strip():
        return None

    source = Path(local_path.removeprefix("file://")).expanduser().resolve()
    if not source.exists() or not source.is_file():
        raise RuntimeError(f"Uploaded source file was not found: {source}")

    raw_root = _fresh_local_directory(raw_dir)
    markdown_dir = workflow_input.get("markdown_output_path")
    if isinstance(markdown_dir, str) and markdown_dir.strip():
        _fresh_local_directory(markdown_dir)
    target_name = str(upload.get("target_name") or source.name)
    target = raw_root / target_name
    if source != target:
        shutil.copy2(source, target)

    return {
        "source_id": source_id,
        "external_job_id": None,
        "raw_dir": str(raw_root),
        "files_found": 1,
        "status": "success",
        "error_details": None,
        "uploaded_file": str(target),
    }


def _fresh_local_directory(path: str) -> Path:
    root = Path(path.removeprefix("file://")).expanduser().resolve()
    if root.exists():
        shutil.rmtree(root)
    root.mkdir(parents=True, exist_ok=True)
    return root


def _uses_direct_converter(service_config: dict[str, Any]) -> bool:
    return str(service_config.get("start_path") or "").strip().strip("/") == "extract"


def _should_fallback_to_extract_api(exc: RuntimeError, service_config: dict[str, Any]) -> bool:
    start_path = str(service_config.get("start_path") or "")

    return "/api/convert/start" in start_path and "404 Client Error" in str(exc)


def _convert_files_with_extract_api(
    service_config: dict[str, Any],
    source_id: str,
    raw_dir: str,
    markdown_dir: str,
) -> dict[str, Any]:
    raw_root = _local_directory(raw_dir, "raw")
    markdown_root = _local_directory(markdown_dir, "markdown", must_exist=False)
    markdown_root.mkdir(parents=True, exist_ok=True)

    candidates = _raw_conversion_candidates(raw_root)

    if not candidates:
        return {
            "source_id": source_id,
            "external_job_id": None,
            "markdown_dir": str(markdown_root),
            "markdown_files_created": 0,
            "converted_files": [],
            "skipped_files": [],
            "status": "failed",
            "error_details": "No files were found for the converter.",
        }

    converted_files: list[str] = []
    passthrough_files: list[str] = []
    markdown_files_created = 0
    for raw_file in candidates:
        output_dir = markdown_root / _converter_output_dir_name(raw_file)
        if output_dir.exists():
            shutil.rmtree(output_dir)
        output_dir.mkdir(parents=True, exist_ok=True)

        try:
            created = _extract_single_file(service_config, raw_file, output_dir)
        except DirectExtractUnsupportedFileError as exc:
            created = _write_raganything_passthrough(raw_file, output_dir, exc)
            passthrough_files.append(str(raw_file))
            logger.info(
                "converter:direct_extract_passthrough file=%s reason=%s",
                raw_file,
                exc,
            )
        markdown_files_created += created
        converted_files.append(str(raw_file))

    return {
        "source_id": source_id,
        "external_job_id": None,
        "markdown_dir": str(markdown_root),
        "markdown_files_created": markdown_files_created,
        "converted_files": converted_files,
        "passthrough_files": passthrough_files,
        "skipped_files": [],
        "status": "success" if markdown_files_created > 0 else "failed",
        "error_details": None if markdown_files_created > 0 else "Converter did not produce Markdown files.",
    }


def _extract_single_file(service_config: dict[str, Any], raw_file: Path, output_dir: Path) -> int:
    base_url = str(service_config["base_url"]).rstrip("/") + "/"
    start_path = str(service_config.get("start_path") or "/extract").lstrip("/")
    url = urljoin(base_url, start_path)
    token = str(service_config.get("token") or "")
    timeout_seconds = float(service_config.get("timeout_seconds") or 30)
    retry_attempts = max(1, int(service_config.get("retry_attempts") or 1))
    headers = {"Authorization": f"Bearer {token}"} if token else {}
    last_error: Exception | None = None

    for attempt in range(1, retry_attempts + 1):
        try:
            with raw_file.open("rb") as handle:
                response = requests.post(
                    url,
                    headers=headers,
                    files={"file": (raw_file.name, handle)},
                    timeout=timeout_seconds,
                )

            if response.status_code >= 500 or response.status_code in {408, 429}:
                response.raise_for_status()
            if not response.ok:
                error = _response_error(response)
                if _is_unsupported_direct_extract_response(response.status_code, error):
                    raise DirectExtractUnsupportedFileError(
                        f"Direct converter does not support {raw_file.name}: {error}"
                    )
                raise RuntimeError(
                    f"Converter request failed [{response.status_code}]: {error}"
                )

            return _unpack_converter_zip(response.content, output_dir)
        except DirectExtractUnsupportedFileError:
            raise
        except Exception as exc:
            last_error = exc
            if attempt >= retry_attempts:
                break
            time.sleep(min(2 ** (attempt - 1), 10))

    raise RuntimeError(f"Converter extract request failed for {raw_file.name}: {last_error}") from last_error


def _write_raganything_passthrough(raw_file: Path, output_dir: Path, error: Exception) -> int:
    output_dir.mkdir(parents=True, exist_ok=True)
    raw_path = str(raw_file.resolve())
    file_hash = _sha256_file(raw_file)
    markdown_path = output_dir / "content_markdown.md"
    markdown_path.write_text(
        "\n".join([
            f"# {raw_file.name}",
            "",
            "The direct converter could not extract Markdown for this file.",
            "The original file is attached for RAG-Anything/MinerU native parsing or OCR during graph ingestion.",
            "",
            f"Original file: `{raw_file.name}`",
            f"Original SHA-256: `{file_hash}`",
            "",
        ]),
        encoding="utf-8",
    )

    metadata: dict[str, Any] = {
        "source_format": "raganything_passthrough",
        "original_filename": raw_file.name,
        "original_path": raw_path,
        "source_file": raw_path,
        "file_path": raw_path,
        "converted_path": str(markdown_path.resolve()),
        "converter_fallback": "raganything_passthrough",
        "converter_error": str(error),
        "original_sha256": file_hash,
    }
    if _is_image_file(raw_file):
        metadata["image_path"] = raw_path
        metadata["images"] = [raw_path]

    (output_dir / PASSTHROUGH_METADATA_FILENAME).write_text(
        json.dumps(metadata, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return 1


def _load_passthrough_metadata(markdown_file: str) -> dict[str, Any]:
    metadata_path = Path(markdown_file).resolve().parent / PASSTHROUGH_METADATA_FILENAME
    if not metadata_path.is_file():
        return {}
    try:
        payload = json.loads(metadata_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        logger.warning("converter:passthrough_metadata unreadable path=%s error=%s", metadata_path, exc)
        return {}
    if not isinstance(payload, dict):
        return {}
    return {
        str(key): value
        for key, value in payload.items()
        if isinstance(key, str) and key.strip()
    }


def _is_unsupported_direct_extract_response(status_code: int, error: str) -> bool:
    message = error.lower()
    return status_code == 400 and "unsupported file type" in message


def _is_image_file(path: Path) -> bool:
    return path.suffix.lower() in {".bmp", ".gif", ".jpeg", ".jpg", ".png", ".tif", ".tiff", ".webp"}


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _unpack_converter_zip(content: bytes, output_dir: Path) -> int:
    archive_data = io.BytesIO(content)
    if not zipfile.is_zipfile(archive_data):
        raise RuntimeError("Converter returned a non-ZIP response.")

    archive_data.seek(0)
    markdown_files_created = 0
    output_root = output_dir.resolve()
    with zipfile.ZipFile(archive_data) as archive:
        for member in archive.infolist():
            if member.is_dir():
                continue

            member_path = Path(member.filename)
            if member_path.is_absolute() or ".." in member_path.parts:
                raise RuntimeError(f"Converter ZIP contained an unsafe path: {member.filename}")

            target = (output_root / member_path).resolve()
            try:
                target.relative_to(output_root)
            except ValueError as exc:
                raise RuntimeError(f"Converter ZIP path escaped output directory: {member.filename}") from exc

            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(member) as source, target.open("wb") as destination:
                shutil.copyfileobj(source, destination)

            if target.suffix.lower() in {".md", ".markdown"}:
                markdown_files_created += 1

    return markdown_files_created


def _local_directory(path: str, label: str, *, must_exist: bool = True) -> Path:
    if is_object_prefix(path):
        raise RuntimeError(f"{label} directory uses object storage; direct converter extraction requires shared storage.")

    root = Path(path.removeprefix("file://")).expanduser().resolve()
    if must_exist and (not root.exists() or not root.is_dir()):
        raise RuntimeError(f"{label.capitalize()} directory was not found: {root}")

    return root


def _raw_conversion_candidates(raw_root: Path) -> list[Path]:
    skip_bookkeeping = _looks_like_scraper_output_dir(raw_root)

    return [
        path
        for path in sorted(raw_root.rglob("*"))
        if path.is_file() and (not skip_bookkeeping or path.name not in SCRAPER_BOOKKEEPING_FILENAMES)
    ]


def _looks_like_scraper_output_dir(raw_root: Path) -> bool:
    return (raw_root / "job_state.json").is_file() or (raw_root / "urls_index.json").is_file()


def _converter_output_dir_name(raw_file: Path) -> str:
    safe_stem = "".join(char.lower() if char.isalnum() else "-" for char in raw_file.stem).strip("-")
    digest = hashlib.sha256(str(raw_file.resolve()).encode("utf-8")).hexdigest()[:8]

    return f"{safe_stem or 'document'}-{digest}"


def _response_error(response: requests.Response) -> str:
    try:
        payload = response.json()
    except ValueError:
        return response.text[:500]

    return str(payload)[:500]


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
    operation_id = _operation_id(workflow_input, docs[0]["id"], "ingest")
    requires_graph = any(
        isinstance(doc.get("payload"), dict)
        and doc["payload"].get("converter_fallback") == "raganything_passthrough"
        for doc in docs
    )
    body = {
        "docs": docs,
        "provider": ingest_options.get("provider") or "ollama",
        "embedding_model": ingest_options.get("embedding_model"),
        "collection": ingest_options.get("collection"),
        "neo4j_database": ingest_options.get("neo4j_database"),
        "chunk_chars": int(ingest_options.get("chunk_chars") or 1200),
        "chunk_overlap": int(ingest_options.get("chunk_overlap") or 250),
        "batch_size": int(ingest_options.get("batch_size") or 64),
        "graph": bool(ingest_options.get("graph", False)) or requires_graph,
        "graph_model": ingest_options.get("graph_model"),
        "idempotency_key": operation_id,
    }
    return _bridge_request(
        settings,
        "POST",
        "/ingest",
        headers={
            "Idempotency-Key": operation_id,
            "X-Request-ID": operation_id,
        },
        json=body,
    )


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
