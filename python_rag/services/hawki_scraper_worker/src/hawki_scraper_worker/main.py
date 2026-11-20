"""Process entrypoint for the Temporal scraper worker."""

from __future__ import annotations

import asyncio

from temporalio.client import Client
from temporalio.worker import Worker

from hawki_worker_runtime.logging import configure_logging
from hawki_worker_runtime.temporal import create_activity_executor

from hawki_scraper_worker.activities import scrape_source
from hawki_scraper_worker.settings import ScraperWorkerSettings


async def run_worker(settings: ScraperWorkerSettings | None = None) -> None:
    """Connect to Temporal and poll only the scraper task queue."""

    resolved = settings or ScraperWorkerSettings.from_environment()
    client = await Client.connect(
        resolved.runtime.temporal_address,
        namespace=resolved.runtime.temporal_namespace,
    )
    with create_activity_executor(
        resolved.runtime.activity_worker_threads,
        thread_name_prefix="hawki-scraper-activity",
    ) as activity_executor:
        worker = Worker(
            client,
            task_queue=resolved.task_queue,
            activities=[scrape_source],
            activity_executor=activity_executor,
        )
        await worker.run()


def main() -> None:
    """Configure scraper logging and run until the worker stops."""

    configure_logging("scraper")
    asyncio.run(run_worker())


if __name__ == "__main__":
    main()


__all__ = ["main", "run_worker"]
