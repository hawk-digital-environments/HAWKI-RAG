"""Worker-owned HTTP operations for the permanent-job-v2 crawler."""

import asyncio
from dataclasses import replace

from temporalio import activity
from temporalio.exceptions import ApplicationError

from hawki_external_jobs.crawler import CustomCrawlerClient
from hawki_rag_contracts.pipeline.temporal import CRAWLER_CONTROL_ACTIVITY
from hawki_scraper_worker.scraping.catalog import read_catalog
from hawki_scraper_worker.scraping.client import (
    crawler_endpoint_id,
    create_scraper_client,
)
from hawki_scraper_worker.settings import ScraperWorkerSettings


@activity.defn(name=CRAWLER_CONTROL_ACTIVITY)
async def crawler_control(action: str, scope: dict | None) -> dict:
    # Control HTTP calls use a separate thread pool from long-running scrapes.
    return await asyncio.to_thread(run_crawler_operation, action, scope)


def run_crawler_operation(action: str, scope: dict | None) -> dict:
    try:
        if action in {"status", "cancel"} and (
            not scope or scope.get("owner") != "url"
        ):
            raise ValueError("Crawler job operations require URL ownership.")
        settings = replace(
            ScraperWorkerSettings.from_environment(),
            request_timeout_seconds=2.5,
            http_retry_attempts=1,
        )
        wire = scope if action in {"status", "cancel"} else {"owner": "url"}
        crawler = create_scraper_client(wire, settings)
        assert isinstance(crawler, CustomCrawlerClient)
        with crawler.session:
            if action == "probe":
                crawler.require_capability()
                return {"endpoint_id": crawler_endpoint_id(crawler.base_url)}
            if action == "catalog":
                return read_catalog(crawler)
            if action == "status":
                return crawler.read_receipt().model_dump(mode="json")
            if action == "cancel":
                return crawler.cancel().model_dump(mode="json")
            raise ValueError("Unknown crawler operation.")
    except (OSError, RuntimeError, ValueError, TypeError, KeyError, AttributeError):
        # Keep server URLs, credentials and response bodies out of failure history.
        raise ApplicationError(
            "External crawler operation failed.", non_retryable=True
        ) from None
