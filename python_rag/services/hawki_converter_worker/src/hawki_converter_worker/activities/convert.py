"""Temporal adapter for the typed source-conversion use case."""

from __future__ import annotations

import logging
from typing import Any

from temporalio import activity
from temporalio.exceptions import ApplicationError

from hawki_rag_contracts.ingestion import (
    ConvertActivityInput,
    IngestionStatus,
    ScrapeResult,
)
from hawki_rag_contracts.status import PipelineStageStatus
from hawki_rag_contracts.temporal import CONVERT_FILES_ACTIVITY
from hawki_worker_runtime.logging import log_event

from hawki_converter_worker.adapters.status_callback import report_status
from hawki_converter_worker.application.source_conversion import (
    execute_source_conversion,
)
from hawki_converter_worker.composition import build_conversion_dependencies
from hawki_converter_worker.domain.errors import (
    NonRetryableConverterResponseError,
)
from hawki_converter_worker.settings import ConverterSettings

logger = logging.getLogger(__name__)


@activity.defn(name=CONVERT_FILES_ACTIVITY)
def inspect_and_convert_files(payload: dict[str, Any]) -> dict[str, Any]:
    """Run conversion behind Temporal status and retry semantics."""

    activity_input = ConvertActivityInput.model_validate(payload)
    workflow_input = dict(activity_input.workflow_input)
    ScrapeResult.model_validate(activity_input.scrape_result)
    settings = ConverterSettings.from_env()
    source_id = str(workflow_input["source_id"])
    markdown_dir = str(workflow_input["markdown_output_path"])

    report_status(
        settings,
        workflow_input,
        status=PipelineStageStatus.RUNNING,
        markdown_dir=markdown_dir,
    )
    log_event(logger, "inspect_and_convert_files:start", source_id=source_id)

    try:
        result_contract = execute_source_conversion(
            activity_input,
            settings=settings,
            dependencies=build_conversion_dependencies(),
        )
    except Exception as exc:
        report_status(
            settings,
            workflow_input,
            status=PipelineStageStatus.FAILED,
            markdown_dir=markdown_dir,
            error=exc,
        )
        if isinstance(exc, NonRetryableConverterResponseError):
            raise ApplicationError(
                str(exc),
                type=type(exc).__name__,
                non_retryable=True,
            ) from exc
        raise

    result = result_contract.model_dump(mode="json")
    callback_status = (
        PipelineStageStatus.COMPLETED
        if result_contract.status is IngestionStatus.SUCCESS
        else PipelineStageStatus.FAILED
    )
    report_status(
        settings,
        workflow_input,
        status=callback_status,
        markdown_dir=str(result_contract.markdown_dir or markdown_dir),
        processed=result_contract.markdown_files_created,
        artifacts=list(result_contract.artifacts),
        error=(
            RuntimeError(str(result_contract.error_details))
            if callback_status == PipelineStageStatus.FAILED
            else None
        ),
    )
    log_event(logger, "inspect_and_convert_files:end", **result)
    return result


__all__ = ["inspect_and_convert_files"]
