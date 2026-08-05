"""Temporal entrypoint for source inspection and conversion."""

from __future__ import annotations

import logging
from typing import Any

from temporalio import activity
from temporalio.exceptions import ApplicationError

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.ingestion import (
    ConvertActivityInput,
    ConvertResult,
    ScrapeResult,
    shared_storage_root,
)
from hawki_rag_contracts.status import PipelineStageStatus
from hawki_rag_contracts.temporal import CONVERT_FILES_ACTIVITY
from hawki_worker_runtime.external_jobs import ExternalJobClient
from hawki_worker_runtime.logging import log_event

from hawki_converter_worker.adapters.status_callback import report_status
from hawki_converter_worker.conversion import service
from hawki_converter_worker.conversion.artifacts import collect_markdown_artifacts
from hawki_converter_worker.settings import ConverterSettings

logger = logging.getLogger(__name__)


@activity.defn(name=CONVERT_FILES_ACTIVITY)
def inspect_and_convert_files(payload: dict[str, Any]) -> dict[str, Any]:
    activity_input = ConvertActivityInput.model_validate(payload)
    workflow_input = dict(activity_input.workflow_input)
    scrape_contract = ScrapeResult.model_validate(activity_input.scrape_result)
    scrape_result = scrape_contract.model_dump(mode="json")
    settings = ConverterSettings.from_env()
    source_id = str(workflow_input["source_id"])
    raw_dir = str(scrape_result.get("raw_dir") or workflow_input["raw_output_path"])
    markdown_dir = str(workflow_input["markdown_output_path"])
    report_status(
        settings,
        workflow_input,
        status=PipelineStageStatus.RUNNING,
        markdown_dir=markdown_dir,
    )
    log_event(logger, "inspect_and_convert_files:start", source_id=source_id)

    try:
        artifact_store = LocalArtifactStore(shared_storage_root(workflow_input))
        config = service._normalize_direct_converter_start_path(
            service.converter_service_config(
                workflow_input,
                settings,
                artifact_store=artifact_store,
            )
        )
        if service._uses_direct_converter(config):
            response = service._convert_files_with_extract_api(
                config,
                source_id,
                raw_dir,
                markdown_dir,
                artifact_store=artifact_store,
            )
        else:
            try:
                response = ExternalJobClient(**config).start_and_wait(
                    {
                        "source_id": source_id,
                        "raw_dir": raw_dir,
                        "markdown_dir": markdown_dir,
                    }
                )
            except RuntimeError as exc:
                if not service._should_fallback_to_extract_api(exc, config):
                    raise
                fallback = dict(config)
                fallback["start_path"] = "/extract"
                response = service._convert_files_with_extract_api(
                    fallback,
                    source_id,
                    raw_dir,
                    markdown_dir,
                    artifact_store=artifact_store,
                )
        status = service._status(response)
        resolved_markdown_dir = str(
            response.get("markdown_dir")
            or response.get("markdown_output_path")
            or markdown_dir
        )
        artifacts = (
            collect_markdown_artifacts(
                resolved_markdown_dir,
                source_id=source_id,
                source_artifact_uri=(
                    scrape_contract.artifacts[0].uri
                    if scrape_contract.artifacts
                    else raw_dir
                ),
                artifact_store=artifact_store,
            )
            if status == "success"
            else []
        )
        contract_result = ConvertResult.model_validate(
            {
                "source_id": source_id,
                "external_job_id": response.get("external_job_id"),
                "markdown_dir": resolved_markdown_dir,
                "markdown_files_created": int(
                    response.get("markdown_files_created")
                    or response.get("file_count")
                    or len(artifacts)
                ),
                "artifacts": artifacts,
                "status": status,
                "error_details": response.get("error") or response.get("error_details"),
            }
        )
    except Exception as exc:
        report_status(
            settings,
            workflow_input,
            status=PipelineStageStatus.FAILED,
            markdown_dir=markdown_dir,
            error=exc,
        )
        if isinstance(exc, service.NonRetryableConverterResponseError):
            raise ApplicationError(
                str(exc),
                type=type(exc).__name__,
                non_retryable=True,
            ) from exc
        raise

    result = contract_result.model_dump(mode="json")
    callback_status = (
        PipelineStageStatus.COMPLETED
        if status == "success"
        else PipelineStageStatus.FAILED
    )
    report_status(
        settings,
        workflow_input,
        status=callback_status,
        markdown_dir=str(result["markdown_dir"]),
        processed=int(result["markdown_files_created"]),
        artifacts=list(contract_result.artifacts),
        error=(
            RuntimeError(str(result["error_details"]))
            if callback_status.value == "failed"
            else None
        ),
    )
    log_event(logger, "inspect_and_convert_files:end", **result)
    return result


__all__ = ["inspect_and_convert_files"]
