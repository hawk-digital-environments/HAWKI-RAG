"""Convert-phase Temporal activity entrypoints."""

from __future__ import annotations

import hashlib
import json
import shutil
from pathlib import Path
from typing import Any

from temporalio import activity

from temporal_rag.external_clients import ExternalJobClient
from temporal_rag.deduplication import ClaimedSourceDocument, SourceDeduplicationStore, read_plan
from temporal_rag.logging import log_event
from temporal_rag.metadata import AppMetadataStore
from temporal_rag.settings import TemporalRagSettings


@activity.defn(name="inspect_and_convert_files")
def inspect_and_convert_files(payload: dict[str, Any]) -> dict[str, Any]:
    from temporal_rag import activities as support

    workflow_input = dict(payload["workflow_input"])
    scrape_result = dict(payload["scrape_result"])
    deduplication_result = dict(payload.get("deduplication_result") or {})
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    raw_dir = str(scrape_result.get("raw_dir") or workflow_input["raw_output_path"])
    markdown_dir = str(workflow_input["markdown_output_path"])
    service_config = support._normalize_direct_converter_start_path(
        support._service_config(workflow_input, "converter", settings)
    )
    metadata.mark_phase(
        workflow_input,
        "inspect_and_convert_files",
        "started",
        {"raw_dir": raw_dir, "markdown_dir": markdown_dir},
    )
    log_event(
        support.logger,
        "inspect_and_convert_files:start",
        source_id=source_id,
        raw_dir=raw_dir,
        markdown_dir=markdown_dir,
        task_queue=settings.converter_task_queue,
    )

    plan = None
    try:
        plan_path = str(deduplication_result.get("plan_path") or "")
        plan = read_plan(plan_path)
        process_documents = list(plan.process_documents)
        if not process_documents:
            raise RuntimeError("Deduplication plan did not contain documents requiring conversion.")

        store = SourceDeduplicationStore(settings)
        completed_result = store.resume_result(plan)
        deduplication_options = workflow_input.get("deduplication")
        force = (
            bool(deduplication_options.get("force", False))
            if isinstance(deduplication_options, dict)
            else False
        )
        checkpoint_path = _conversion_checkpoint_path(
            plan_path=plan_path,
            document_version=plan.document_version,
            service_config=service_config,
        )
        checkpoint = None if force else _read_conversion_checkpoint(checkpoint_path, markdown_dir)
        if completed_result is not None:
            response = {
                "source_id": source_id,
                "markdown_dir": markdown_dir,
                "markdown_files_created": 0,
                "markdown_files": [],
                "converted_files": [],
                "status": "success",
                "dedup_already_completed": True,
            }
        elif checkpoint is not None:
            response = checkpoint
            response["conversion_checkpoint_reused"] = True
        elif support._uses_direct_converter(service_config):
            candidates, source_metadata = _conversion_inputs(process_documents, raw_dir)
            response = support._convert_files_with_extract_api(
                service_config,
                source_id,
                raw_dir,
                markdown_dir,
                candidates=candidates,
                source_metadata_by_path=source_metadata,
            )
        else:
            try:
                response = _convert_documents_with_external_jobs(
                    support,
                    service_config=service_config,
                    source_id=source_id,
                    raw_dir=raw_dir,
                    markdown_dir=markdown_dir,
                    documents=process_documents,
                    claim_token=plan.claim_token,
                )
            except RuntimeError as exc:
                if not support._should_fallback_to_extract_api(exc, service_config):
                    raise

                fallback_config = dict(service_config)
                fallback_config["start_path"] = "/extract"
                candidates, source_metadata = _conversion_inputs(process_documents, raw_dir)
                response = support._convert_files_with_extract_api(
                    fallback_config,
                    source_id,
                    raw_dir,
                    markdown_dir,
                    candidates=candidates,
                    source_metadata_by_path=source_metadata,
                )
    except Exception as exc:
        if plan is not None:
            SourceDeduplicationStore(settings).mark_failed(plan, str(exc))
        support._record_activity_exception(
            metadata,
            workflow_input,
            "inspect_and_convert_files",
            exc,
            raw_dir=raw_dir,
            markdown_dir=markdown_dir,
        )
        raise

    status = support._status(response)
    if status == "success" and response.get("dedup_already_completed") is not True:
        _write_conversion_checkpoint(checkpoint_path, response)
    result = {
        "source_id": source_id,
        "external_job_id": response.get("external_job_id"),
        "markdown_dir": response.get("markdown_dir") or response.get("markdown_output_path") or markdown_dir,
        "markdown_files_created": int(response.get("markdown_files_created") or response.get("file_count") or 0),
        "markdown_files": response.get("markdown_files") if isinstance(response.get("markdown_files"), list) else None,
        "deduplication": deduplication_result,
        "status": status,
        "error_details": response.get("error") or response.get("error_details"),
    }
    if status != "success" and plan is not None:
        SourceDeduplicationStore(settings).mark_failed(
            plan,
            str(result.get("error_details") or "Conversion did not complete successfully."),
        )
    metadata.mark_phase(workflow_input, "inspect_and_convert_files", status, result)
    log_event(support.logger, "inspect_and_convert_files:end", **result, task_queue=settings.converter_task_queue)
    return result


def _conversion_inputs(
    documents: list[ClaimedSourceDocument],
    raw_dir: str,
) -> tuple[list[Path], dict[str, dict[str, Any]]]:
    raw_root = Path(raw_dir.removeprefix("file://")).expanduser().resolve()
    candidates: list[Path] = []
    metadata: dict[str, dict[str, Any]] = {}
    for document in documents:
        source_path = Path(document.source_path).expanduser().resolve()
        try:
            source_path.relative_to(raw_root)
        except ValueError as exc:
            raise RuntimeError(f"Deduplication input escaped the raw directory: {source_path}") from exc
        if not source_path.is_file():
            raise RuntimeError(f"Deduplication input no longer exists: {source_path}")
        candidates.append(source_path)
        metadata[str(source_path)] = _source_metadata(document)
    return candidates, metadata


def _source_metadata(document: ClaimedSourceDocument) -> dict[str, Any]:
    source_identity = (
        f"url:{document.source_url}"
        if document.source_url and document.source_url.startswith(("http://", "https://"))
        else f"doc:{document.document_id}"
    )
    return {
        "dedup_document_id": document.document_id,
        "source_identity": source_identity,
        "source_content_hash": document.content_hash,
        "source_url": document.source_url,
        "canonical_url": document.canonical_url,
        "source_relative_path": document.relative_path,
        "page_id": document.page_id,
        "crawler_markdown": bool(document.source_url)
        and document.source_path.lower().endswith((".md", ".markdown")),
    }


def _convert_documents_with_external_jobs(
    support: Any,
    *,
    service_config: dict[str, Any],
    source_id: str,
    raw_dir: str,
    markdown_dir: str,
    documents: list[ClaimedSourceDocument],
    claim_token: str,
) -> dict[str, Any]:
    raw_root = Path(raw_dir.removeprefix("file://")).expanduser().resolve()
    markdown_root = Path(markdown_dir.removeprefix("file://")).expanduser().resolve()
    staging_root = raw_root.parent / "deduplication" / (
        "staging-" + hashlib.sha256(claim_token.encode("utf-8")).hexdigest()[:24]
    )
    if staging_root.exists():
        shutil.rmtree(staging_root)
    staging_root.mkdir(parents=True, exist_ok=True)
    external_job_state_path = staging_root.parent / (
        "external-jobs-" + hashlib.sha256(claim_token.encode("utf-8")).hexdigest()[:24] + ".json"
    )
    external_job_ids = _read_string_mapping(external_job_state_path)

    markdown_files: list[str] = []
    converted_files: list[str] = []
    try:
        for document in documents:
            source = Path(document.source_path).expanduser().resolve()
            try:
                source.relative_to(raw_root)
            except ValueError as exc:
                raise RuntimeError(f"Deduplication input escaped the raw directory: {source}") from exc

            if document.source_url and source.suffix.lower() in {".md", ".markdown"}:
                direct_result = support._convert_files_with_extract_api(
                    service_config,
                    source_id,
                    raw_dir,
                    markdown_dir,
                    candidates=[source],
                    source_metadata_by_path={str(source): _source_metadata(document)},
                )
                markdown_files.extend(direct_result.get("markdown_files") or [])
                converted_files.append(str(source))
                continue

            document_stage = staging_root / document.document_id
            document_stage.mkdir(parents=True, exist_ok=True)
            staged_source = document_stage / source.name
            shutil.copy2(source, staged_source)

            output_dir = markdown_root / "documents" / document.document_id
            resume_job_id = external_job_ids.get(document.document_id)
            if output_dir.exists() and not resume_job_id:
                shutil.rmtree(output_dir)
            output_dir.mkdir(parents=True, exist_ok=True)
            client = ExternalJobClient(**service_config)
            response = client.start_and_wait(
                {
                    "source_id": f"{source_id}:{document.document_id}",
                    "raw_dir": str(document_stage),
                    "markdown_dir": str(output_dir),
                },
                resume_job_id=resume_job_id,
                progress_callback=lambda job_id, document_id=document.document_id: _record_external_job(
                    external_job_state_path,
                    external_job_ids,
                    document_id,
                    job_id,
                ),
            )
            if support._status(response) != "success":
                _forget_external_job(
                    external_job_state_path,
                    external_job_ids,
                    document.document_id,
                )
                raise RuntimeError(
                    f"External conversion failed for document [{document.document_id}]: "
                    f"{response.get('error') or response.get('error_details') or response}"
                )
            produced = support._markdown_paths(output_dir)
            if not produced:
                raise RuntimeError(f"External converter produced no Markdown for [{document.document_id}].")
            for markdown_file in produced:
                support._write_source_metadata(Path(markdown_file).parent, _source_metadata(document))
            markdown_files.extend(produced)
            converted_files.append(str(source))
    finally:
        if staging_root.exists():
            shutil.rmtree(staging_root)

    return {
        "source_id": source_id,
        "markdown_dir": str(markdown_root),
        "markdown_files_created": len(set(markdown_files)),
        "markdown_files": sorted(set(markdown_files)),
        "converted_files": converted_files,
        "skipped_files": [],
        "status": "success" if markdown_files else "failed",
        "error_details": None if markdown_files else "Converter did not produce Markdown files.",
    }


def _conversion_checkpoint_path(
    *,
    plan_path: str,
    document_version: str,
    service_config: dict[str, Any],
) -> Path:
    converter_identity = {
        "base_url": service_config.get("base_url"),
        "start_path": service_config.get("start_path"),
        "status_path": service_config.get("status_path"),
    }
    digest = hashlib.sha256(
        (
            document_version
            + "|"
            + json.dumps(converter_identity, sort_keys=True, separators=(",", ":"))
        ).encode("utf-8")
    ).hexdigest()[:32]
    return Path(plan_path).resolve().parent / f"conversion-{digest}.json"


def _read_conversion_checkpoint(path: Path, markdown_dir: str) -> dict[str, Any] | None:
    payload = _read_json_mapping(path)
    response = payload.get("response")
    if not isinstance(response, dict) or str(response.get("status") or "").lower() != "success":
        return None

    markdown_root = Path(markdown_dir.removeprefix("file://")).expanduser().resolve()
    markdown_files = response.get("markdown_files")
    if not isinstance(markdown_files, list) or not markdown_files:
        return None
    for value in markdown_files:
        if not isinstance(value, str) or not value.strip():
            return None
        candidate = Path(value.removeprefix("file://")).expanduser().resolve()
        try:
            candidate.relative_to(markdown_root)
        except ValueError:
            return None
        if not candidate.is_file() or candidate.suffix.lower() not in {".md", ".markdown"}:
            return None
    return dict(response)


def _write_conversion_checkpoint(path: Path, response: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_suffix(".tmp")
    temporary_path.write_text(
        json.dumps({"response": response}, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    temporary_path.replace(path)


def _read_string_mapping(path: Path) -> dict[str, str]:
    return {
        str(key): str(value)
        for key, value in _read_json_mapping(path).items()
        if str(key).strip() and isinstance(value, (str, int)) and str(value).strip()
    }


def _read_json_mapping(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return dict(payload) if isinstance(payload, dict) else {}


def _record_external_job(
    path: Path,
    external_job_ids: dict[str, str],
    document_id: str,
    job_id: str,
) -> None:
    external_job_ids[document_id] = job_id
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_suffix(".tmp")
    temporary_path.write_text(
        json.dumps(external_job_ids, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    temporary_path.replace(path)
    try:
        activity.heartbeat({"document_id": document_id, "external_job_id": job_id})
    except RuntimeError:
        return


def _forget_external_job(
    path: Path,
    external_job_ids: dict[str, str],
    document_id: str,
) -> None:
    external_job_ids.pop(document_id, None)
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_suffix(".tmp")
    temporary_path.write_text(
        json.dumps(external_job_ids, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    temporary_path.replace(path)
