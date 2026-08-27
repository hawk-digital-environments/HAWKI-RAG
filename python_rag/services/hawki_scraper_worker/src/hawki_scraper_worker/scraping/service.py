"""Application service for upload staging and external crawling."""

from __future__ import annotations

from collections.abc import Callable
from typing import Any, Protocol

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.pipeline.ingestion import shared_storage_root
from hawki_external_jobs import ExternalJobClient

from hawki_scraper_worker.adapters.artifact_store import LocalUploadArtifactStager
from hawki_scraper_worker.scraping.client import create_scraper_client
from hawki_scraper_worker.scraping.requests import build_scraper_start_payload
from hawki_scraper_worker.scraping.responses import normalize_scrape_result
from hawki_scraper_worker.settings import ScraperWorkerSettings


class UploadArtifactStager(Protocol):
    """Boundary used to stage a Laravel-owned upload."""

    def stage(
        self,
        workflow_input: dict[str, Any],
        source_id: str,
        raw_dir: str,
        artifact_store: LocalArtifactStore,
    ) -> dict[str, Any] | None: ...


ScraperClientFactory = Callable[
    [dict[str, Any], ScraperWorkerSettings], ExternalJobClient
]


class ScraperService:
    """Scrape one source without knowing Temporal or Laravel callback APIs."""

    def __init__(
        self,
        settings: ScraperWorkerSettings,
        *,
        upload_stager: UploadArtifactStager | None = None,
        client_factory: ScraperClientFactory = create_scraper_client,
    ) -> None:
        self.settings = settings
        self.upload_stager = upload_stager or LocalUploadArtifactStager()
        self.client_factory = client_factory

    def scrape(
        self,
        workflow_input: dict[str, Any],
        *,
        resume_external_job_id: str | None,
        progress_callback: Callable[[str], None],
    ) -> dict[str, Any]:
        source_id = _required_string(workflow_input, "source_id")
        raw_dir = _required_string(workflow_input, "raw_output_path")
        artifact_store = LocalArtifactStore(shared_storage_root(workflow_input))

        upload_result = self.upload_stager.stage(
            workflow_input,
            source_id,
            raw_dir,
            artifact_store,
        )
        if upload_result is not None:
            return upload_result

        client = self.client_factory(workflow_input, self.settings)
        start_payload = build_scraper_start_payload(workflow_input, source_id, raw_dir)
        response = client.start_and_wait(
            start_payload,
            resume_job_id=resume_external_job_id,
            progress_callback=progress_callback,
        )
        return normalize_scrape_result(
            response,
            start_payload,
            source_id,
            raw_dir,
            artifact_store,
        )


def _required_string(payload: dict[str, Any], key: str) -> str:
    value = payload.get(key)
    if not isinstance(value, (str, int)) or not str(value).strip():
        raise ValueError(f"{key} is required for scrape_source")
    return str(value).strip()


__all__ = ["ScraperService", "ScraperClientFactory", "UploadArtifactStager"]
