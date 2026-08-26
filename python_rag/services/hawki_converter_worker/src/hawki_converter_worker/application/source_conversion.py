"""Typed application use case for converting one scraped source."""

from __future__ import annotations

from typing import Any

from hawki_rag_contracts.ingestion import (
    ConvertActivityInput,
    ConvertResult,
    IngestionStatus,
    ScrapeResult,
    shared_storage_root,
)

from hawki_converter_worker.application.configuration import (
    build_converter_endpoint_config,
    normalize_converter_status,
    should_fallback_to_direct_extract,
)
from hawki_converter_worker.application.dependencies import ConversionDependencies
from hawki_converter_worker.conversion.artifacts import collect_markdown_artifacts
from hawki_converter_worker.conversion.direct import convert_files_direct
from hawki_converter_worker.domain.models import (
    ConversionRunResult,
    ConverterEndpointConfig,
)
from hawki_converter_worker.domain.ports import ConverterArtifactStorePort
from hawki_converter_worker.settings import ConverterSettings


def execute_source_conversion(
    request: ConvertActivityInput,
    *,
    settings: ConverterSettings,
    dependencies: ConversionDependencies,
) -> ConvertResult:
    """Convert one scraped source and return its stable workflow contract.

    1. Resolve the authorized shared artifact root and converter profile.
    2. Route files through either synchronous direct extraction or the external
       start/status client, with the established legacy-route fallback.
    3. Validate the converter outcome and collect canonical Markdown artifacts.
    4. Return the typed result consumed by the durable ingestion workflow.
    """

    workflow_input = dict(request.workflow_input)
    scrape_result = ScrapeResult.model_validate(request.scrape_result)
    source_id = str(workflow_input["source_id"])
    raw_dir = str(scrape_result.raw_dir or workflow_input["raw_output_path"])
    markdown_dir = str(workflow_input["markdown_output_path"])

    artifact_store = dependencies.artifact_store_factory(
        shared_storage_root(workflow_input)
    )
    config = build_converter_endpoint_config(
        workflow_input,
        settings,
        artifact_store=artifact_store,
    )
    run_result = _run_converter(
        config,
        source_id=source_id,
        raw_dir=raw_dir,
        markdown_dir=markdown_dir,
        artifact_store=artifact_store,
        dependencies=dependencies,
    )

    artifacts = (
        collect_markdown_artifacts(
            run_result.markdown_dir,
            source_id=source_id,
            source_artifact_uri=(
                scrape_result.artifacts[0].uri if scrape_result.artifacts else raw_dir
            ),
            artifact_store=artifact_store,
        )
        if run_result.status is IngestionStatus.SUCCESS
        else []
    )
    return ConvertResult(
        source_id=source_id,
        external_job_id=run_result.external_job_id,
        markdown_dir=run_result.markdown_dir,
        markdown_files_created=run_result.markdown_files_created or len(artifacts),
        artifacts=artifacts,
        status=run_result.status,
        error_details=run_result.error_details,
    )


def _run_converter(
    config: ConverterEndpointConfig,
    *,
    source_id: str,
    raw_dir: str,
    markdown_dir: str,
    artifact_store: ConverterArtifactStorePort,
    dependencies: ConversionDependencies,
) -> ConversionRunResult:
    if config.uses_direct_extract:
        return _run_direct_converter(
            config,
            source_id=source_id,
            raw_dir=raw_dir,
            markdown_dir=markdown_dir,
            artifact_store=artifact_store,
            dependencies=dependencies,
        )

    try:
        external_client = dependencies.external_converter_client_factory(config)
        response = external_client.start_and_wait(
            {
                "source_id": source_id,
                "raw_dir": raw_dir,
                "markdown_dir": markdown_dir,
            }
        )
    except RuntimeError as exc:
        if not should_fallback_to_direct_extract(exc, config):
            raise
        return _run_direct_converter(
            config.with_start_path("/extract"),
            source_id=source_id,
            raw_dir=raw_dir,
            markdown_dir=markdown_dir,
            artifact_store=artifact_store,
            dependencies=dependencies,
        )
    return _external_run_result(response, source_id, markdown_dir)


def _run_direct_converter(
    config: ConverterEndpointConfig,
    *,
    source_id: str,
    raw_dir: str,
    markdown_dir: str,
    artifact_store: ConverterArtifactStorePort,
    dependencies: ConversionDependencies,
) -> ConversionRunResult:
    return convert_files_direct(
        source_id,
        raw_dir,
        markdown_dir,
        artifact_store=artifact_store,
        extract_client=dependencies.direct_extract_client_factory(config),
    )


def _external_run_result(
    response: dict[str, Any],
    source_id: str,
    default_markdown_dir: str,
) -> ConversionRunResult:
    markdown_dir = str(
        response.get("markdown_dir")
        or response.get("markdown_output_path")
        or default_markdown_dir
    )
    error_details = response.get("error") or response.get("error_details")
    return ConversionRunResult(
        source_id=source_id,
        external_job_id=response.get("external_job_id"),
        markdown_dir=markdown_dir,
        markdown_files_created=int(
            response.get("markdown_files_created") or response.get("file_count") or 0
        ),
        status=normalize_converter_status(response),
        error_details=str(error_details) if error_details is not None else None,
    )


__all__ = ["execute_source_conversion"]
