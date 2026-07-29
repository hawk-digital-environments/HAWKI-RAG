"""Tests for environment-backed Temporal worker settings."""

from unittest.mock import patch

from temporal_rag.settings import TemporalRagSettings


def test_crawler_defaults_match_external_crawler_contract() -> None:
    with patch.dict("os.environ", {}, clear=True):
        settings = TemporalRagSettings.from_env()

    assert settings.scraper_url == "http://crawl4ai-service"
    assert settings.scraper_start_path == "/crawl"
    assert settings.scraper_status_path == "/status/{job_id}"
