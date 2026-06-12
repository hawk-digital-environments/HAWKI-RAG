"""Markdown ingestion activity worker."""

from __future__ import annotations

import asyncio

from temporalio.client import Client
from temporalio.worker import Worker

from temporal_rag.activities import ingest_markdown_files, mark_source_ready
from temporal_rag.logging import configure_logging
from temporal_rag.settings import TemporalRagSettings


async def main() -> None:
    configure_logging()
    settings = TemporalRagSettings.from_env()
    client = await Client.connect(settings.temporal_address, namespace=settings.temporal_namespace)
    worker = Worker(
        client,
        task_queue=settings.ingestion_task_queue,
        activities=[ingest_markdown_files, mark_source_ready],
    )
    await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
