"""Outbound adapters owned by the scraper worker."""

from hawki_scraper_worker.adapters.artifact_store import LocalUploadArtifactStager
from hawki_scraper_worker.adapters.status_callback import ScraperStatusReporter

__all__ = ["LocalUploadArtifactStager", "ScraperStatusReporter"]
