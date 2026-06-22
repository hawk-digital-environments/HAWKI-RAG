"""Converter activity worker."""

from __future__ import annotations

import asyncio

from temporalio.client import Client
from temporalio.worker import Worker

from temporal_rag.activities import inspect_and_convert_files
from temporal_rag.logging import configure_logging
from temporal_rag.settings import TemporalRagSettings
from temporal_rag.worker_runtime import create_activity_executor


async def main() -> None:
    configure_logging("converter")
    settings = TemporalRagSettings.from_env()
    client = await Client.connect(settings.temporal_address, namespace=settings.temporal_namespace)
    with create_activity_executor(settings) as activity_executor:
        worker = Worker(
            client,
            task_queue=settings.converter_task_queue,
            activities=[inspect_and_convert_files],
            activity_executor=activity_executor,
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
