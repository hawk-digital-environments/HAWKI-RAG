"""Temporal indexer worker process."""

from __future__ import annotations

import asyncio

from temporalio.client import Client
from temporalio.worker import Worker

from hawki_worker_runtime.logging import configure_logging
from hawki_worker_runtime.temporal import create_activity_executor

from hawki_indexer_worker.activities.index import (
    ingest_markdown_files,
    mark_source_ready,
)
from hawki_indexer_worker.settings import IndexerSettings


async def serve() -> None:
    configure_logging("indexer")
    settings = IndexerSettings.from_env()
    client = await Client.connect(
        settings.temporal_address,
        namespace=settings.temporal_namespace,
    )
    with create_activity_executor(settings.activity_worker_threads) as executor:
        task_queues = tuple(
            dict.fromkeys((settings.task_queue, settings.legacy_task_queue))
        )
        workers = [
            Worker(
                client,
                task_queue=task_queue,
                activities=[ingest_markdown_files, mark_source_ready],
                activity_executor=executor,
            )
            for task_queue in task_queues
        ]
        await asyncio.gather(*(worker.run() for worker in workers))


def main() -> None:
    asyncio.run(serve())


if __name__ == "__main__":
    main()


__all__ = ["main", "serve"]
