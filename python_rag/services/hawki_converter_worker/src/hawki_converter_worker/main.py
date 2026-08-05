"""Converter Temporal worker process."""

from __future__ import annotations

import asyncio

from temporalio.client import Client
from temporalio.worker import Worker

from hawki_converter_worker.activities.convert import inspect_and_convert_files
from hawki_converter_worker.settings import ConverterSettings
from hawki_worker_runtime.logging import configure_logging
from hawki_worker_runtime.temporal import create_activity_executor


async def serve() -> None:
    configure_logging("converter")
    settings = ConverterSettings.from_env()
    client = await Client.connect(
        settings.temporal_address,
        namespace=settings.temporal_namespace,
    )
    with create_activity_executor(settings.activity_worker_threads) as executor:
        worker = Worker(
            client,
            task_queue=settings.task_queue,
            activities=[inspect_and_convert_files],
            activity_executor=executor,
        )
        await worker.run()


def main() -> None:
    asyncio.run(serve())


if __name__ == "__main__":
    main()
