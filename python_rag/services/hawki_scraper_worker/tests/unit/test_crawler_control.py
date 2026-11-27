"""Verify crawler control uses worker configuration and owned job receipts."""

from unittest.mock import Mock

import pytest
from temporalio.exceptions import ApplicationError

from hawki_external_jobs.crawler import CustomCrawlerClient
from hawki_scraper_worker.activities.crawler import run_crawler_operation
from hawki_scraper_worker.scraping.client import (
    crawler_endpoint_id,
    create_scraper_client,
)
from hawki_scraper_worker.settings import ScraperWorkerSettings


@pytest.fixture
def worker_config(monkeypatch):
    monkeypatch.setenv("RAG_WORKER_CALLBACK_SECRET", "test-secret")
    monkeypatch.setenv("EXTERNAL_SCRAPER_URL", "https://crawler.test")
    monkeypatch.setenv("EXTERNAL_SCRAPER_TOKEN", "worker-secret")
    for key in ("URL", "PROFILES_PATH", "TASKS_PATH"):
        monkeypatch.delenv("CUSTOM_CRAWLER_TASK_UI_" + key, raising=False)


def test_probe_returns_the_worker_endpoint_identity(worker_config, monkeypatch):
    def verify(crawler):
        assert crawler.base_url == "https://crawler.test/"
        assert crawler.token == "worker-secret"
        assert crawler.session.trust_env is False

    monkeypatch.setattr(CustomCrawlerClient, "require_capability", verify)
    assert run_crawler_operation("probe", None) == {
        "endpoint_id": crawler_endpoint_id("https://crawler.test")
    }


def test_control_rejects_a_changed_endpoint_before_http(worker_config, monkeypatch):
    status = Mock()
    monkeypatch.setattr(CustomCrawlerClient, "read_receipt", status)
    scope = {
        "owner": "url",
        "metadata": {
            "scraper_configuration": {
                "endpoint_id": crawler_endpoint_id("https://previous.test")
            }
        },
    }
    with pytest.raises(ApplicationError, match="External crawler operation failed"):
        run_crawler_operation("status", scope)
    status.assert_not_called()


def test_legacy_crawler_target_keeps_its_own_credential(worker_config):
    crawler = create_scraper_client(
        {"owner": "url", "external_services": {"scraper_url": "http://old.test"}},
        ScraperWorkerSettings.from_environment(),
    )
    with crawler.session:
        assert crawler.base_url == "http://old.test/"
        assert crawler.token == ""


def test_missing_catalog_requires_a_healthy_crawler(worker_config, monkeypatch):
    fetch = Mock(return_value=Mock(status_code=404))
    probe = Mock()
    monkeypatch.setattr("requests.Session.get", fetch)
    monkeypatch.setattr(CustomCrawlerClient, "require_capability", probe)

    assert run_crawler_operation("catalog", None)["tasks"] == []
    fetch.assert_called_once_with(
        "https://crawler.test/ui/api/profiles",
        headers={"Authorization": "Bearer worker-secret"},
        timeout=2.5,
        allow_redirects=False,
    )
    probe.assert_called_once()

    probe.side_effect = ValueError("invalid crawler contract")
    with pytest.raises(ApplicationError, match="External crawler operation failed"):
        run_crawler_operation("catalog", None)


def test_catalog_preserves_tasks_and_approved_profile_fields(
    worker_config, monkeypatch
):
    profiles = {
        "profiles": [
            {
                "name": "cedar",
                "match_hosts": ["example.org"],
                "profile": {"max_pages": 2, "internal_token": "private"},
                "hostPath": "/private/profile.json",
            }
        ]
    }
    tasks = {"tasks": [{"id": "weekly", "profileId": "cedar", "schedule": "0 1 * * 1"}]}
    fetch = Mock(
        side_effect=[
            Mock(status_code=200, content=b"{}", json=Mock(return_value=body))
            for body in (profiles, tasks)
        ]
    )
    monkeypatch.setattr("requests.Session.get", fetch)

    catalog = run_crawler_operation("catalog", None)["tasks"]
    assert {row["id"] for row in catalog} == {"manual-cedar", "weekly"}
    assert all(row["settings"] == {"max_pages": 2} for row in catalog)
    assert all("hostPath" not in row for row in catalog)
