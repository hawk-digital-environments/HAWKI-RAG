"""Convert-phase Temporal activity entrypoints."""

from __future__ import annotations

from typing import Any

from temporalio import activity

from temporal_rag.external_clients import ExternalJobClient
from temporal_rag.logging import log_event
from temporal_rag.metadata import AppMetadataStore
from temporal_rag.settings import TemporalRagSettings


@activity.defn(name="inspect_and_convert_files")
def inspect_and_convert_files(payload: dict[str, Any]) -> dict[str, Any]:
    from temporal_rag import activities as support

    workflow_input = dict(payload["workflow_input"])
    scrape_result = dict(payload["scrape_result"])
    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    raw_dir = str(scrape_result.get("raw_dir") or workflow_input["raw_output_path"])
    markdown_dir = str(workflow_input["markdown_output_path"])
    service_config = support._normalize_direct_converter_start_path(support._service_config(workflow_input, "converter", settings))
    metadata.mark_phase(workflow_input, "inspect_and_convert_files", "started", {"raw_dir": raw_dir, "markdown_dir": markdown_dir})
    log_event(support.logger, "inspect_and_convert_files:start", source_id=source_id, raw_dir=raw_dir, markdown_dir=markdown_dir, task_queue=settings.converter_task_queue)

    try:
        if support._uses_direct_converter(service_config):
            response = support._convert_files_with_extract_api(service_config, source_id, raw_dir, markdown_dir)
        else:
            try:
                client = ExternalJobClient(**service_config)
                response = client.start_and_wait({
                    "source_id": source_id,
                    "raw_dir": raw_dir,
                    "markdown_dir": markdown_dir,
                })
            except RuntimeError as exc:
                if not support._should_fallback_to_extract_api(exc, service_config):
                    raise

                fallback_config = dict(service_config)
                fallback_config["start_path"] = "/extract"
                response = support._convert_files_with_extract_api(fallback_config, source_id, raw_dir, markdown_dir)
    except Exception as exc:
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
    result = {
        "source_id": source_id,
        "external_job_id": response.get("external_job_id"),
        "markdown_dir": response.get("markdown_dir") or response.get("markdown_output_path") or markdown_dir,
        "markdown_files_created": int(response.get("markdown_files_created") or response.get("file_count") or 0),
        "status": status,
        "error_details": response.get("error") or response.get("error_details"),
    }
    metadata.mark_phase(workflow_input, "inspect_and_convert_files", status, result)
    log_event(support.logger, "inspect_and_convert_files:end", **result, task_queue=settings.converter_task_queue)
    return result
