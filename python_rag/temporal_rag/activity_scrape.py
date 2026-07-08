"""Scrape-phase Temporal activity entrypoints."""

from __future__ import annotations

from typing import Any

from temporalio import activity

from temporal_rag.external_clients import ExternalJobClient
from temporal_rag.logging import log_event
from temporal_rag.metadata import AppMetadataStore
from temporal_rag.settings import TemporalRagSettings


@activity.defn(name="scrape_source")
def scrape_source(workflow_input: dict[str, Any]) -> dict[str, Any]:
    from temporal_rag import activities as support

    settings = TemporalRagSettings.from_env()
    metadata = AppMetadataStore(settings)
    source_id = str(workflow_input["source_id"])
    raw_dir = str(workflow_input["raw_output_path"])
    service_config = support._service_config(workflow_input, "scraper", settings)
    metadata.mark_phase(workflow_input, "scrape_source", "started", {"raw_dir": raw_dir})
    log_event(support.logger, "scrape_source:start", source_id=source_id, raw_dir=raw_dir, task_queue=settings.scraper_task_queue)

    upload_result = support._scrape_uploaded_file(workflow_input, source_id, raw_dir)
    if upload_result is not None:
        metadata.mark_phase(workflow_input, "scrape_source", "success", upload_result)
        log_event(support.logger, "scrape_source:uploaded_file", **upload_result, task_queue=settings.scraper_task_queue)
        return upload_result

    try:
        client = ExternalJobClient(**service_config)
        start_payload = support._scraper_start_payload(workflow_input, source_id, raw_dir)
        response = client.start_and_wait(start_payload)
    except Exception as exc:
        support._record_activity_exception(metadata, workflow_input, "scrape_source", exc, raw_dir=raw_dir)
        raise

    result = support._scrape_result(response, start_payload, source_id, raw_dir)
    metadata.mark_phase(workflow_input, "scrape_source", str(result["status"]), result)
    log_event(support.logger, "scrape_source:end", **result, task_queue=settings.scraper_task_queue)
    return result
