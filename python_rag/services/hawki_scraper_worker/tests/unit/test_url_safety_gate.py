"""A signed URL owner cannot bypass the missing safe-fetch capability."""

from unittest.mock import Mock

import pytest

from hawki_scraper_worker.scraping.service import ScraperService


@pytest.mark.parametrize(
    "owner", [{"owner": "url"}, {"url_ingestion_operation_id": "operation"}]
)
def test_url_worker_fails_before_staging_or_external_http(tmp_path, owner):
    stager = Mock()
    factory = Mock()
    service = ScraperService(Mock(), upload_stager=stager, client_factory=factory)
    with pytest.raises(RuntimeError, match="exclusive authoritative generation"):
        service.scrape(
            {
                "source_id": "source",
                "source_url": "https://example.org",
                "raw_output_path": str(tmp_path / "raw"),
                "storage": {"shared_root": str(tmp_path)},
                "upload": {"local_path": "/etc/passwd"},
                **owner,
            },
            resume_external_job_id=None,
            progress_callback=Mock(),
        )
    stager.stage.assert_not_called()
    factory.assert_not_called()
    assert not (tmp_path / "raw").exists()


def test_xml_sitemap_uses_crawler_resource_parser_and_preserves_generation():
    from hawki_scraper_worker.scraping.requests import build_scraper_start_payload

    wire = dict(
        owner="url",
        job_id="job",
        source_url="https://example.org",
        generation=7,
        url_ingestion_operation_id="operation",
        metadata={
            "request": {
                "sitemap_url": "https://example.org/sitemap.xml",
                "metadata": {},
            }
        },
    )
    payload = build_scraper_start_payload(wire, "source", "/shared/raw")
    assert payload["url"] == "https://example.org/sitemap.xml"
    assert payload["sitemap"] is False
    assert payload["sitemap_only"] is True
    assert payload["source_url"] == "https://example.org"
    assert (
        payload["generation"] == 7
        and payload["url_ingestion_operation_id"] == "operation"
    )
