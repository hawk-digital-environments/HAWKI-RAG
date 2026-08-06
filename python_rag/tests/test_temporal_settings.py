"""Tests for environment-backed Temporal worker settings."""

from unittest.mock import patch

from hawki_scraper_worker.settings import ScraperWorkerSettings


def test_crawler_defaults_match_external_crawler_contract() -> None:
    with patch.dict(
        "os.environ",
        {
            "HAWKI_RAG_WORKER_CALLBACK_URL": "http://laravel.test/api/internal/pipeline/worker-events",
            "HAWKI_RAG_WORKER_CALLBACK_SECRET": "test-secret",
        },
        clear=True,
    ):
        settings = ScraperWorkerSettings.from_environment()

    assert settings.scraper_url == "http://crawl4ai-service"
    assert settings.scraper_start_path == "/crawl"
    assert settings.scraper_status_path == "/status/{job_id}"
