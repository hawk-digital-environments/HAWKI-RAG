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
    with pytest.raises(RuntimeError, match="DNS-pinning"):
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
