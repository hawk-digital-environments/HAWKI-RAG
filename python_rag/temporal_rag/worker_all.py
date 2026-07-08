"""Combined Temporal worker process for all HAWKI RAG task queues."""

from __future__ import annotations

import asyncio

from temporalio.client import Client
from temporalio.worker import Worker

from temporal_rag.activities import ingest_markdown_files, inspect_and_convert_files, mark_source_ready
from temporal_rag.logging import configure_logging
from temporal_rag.settings import TemporalRagSettings
from temporal_rag.worker_runtime import create_activity_executor
from temporal_rag.workflows import IngestSourceWorkflow


async def main() -> None:
    configure_logging("workers")
    settings = TemporalRagSettings.from_env()
    client = await Client.connect(settings.temporal_address, namespace=settings.temporal_namespace)

    with create_activity_executor(settings) as converter_executor, create_activity_executor(settings) as ingestion_executor:
        workflow_worker = Worker(
            client,
            task_queue=settings.workflow_task_queue,
            workflows=[IngestSourceWorkflow],
        )
        converter_worker = Worker(
            client,
            task_queue=settings.converter_task_queue,
            activities=[inspect_and_convert_files],
            activity_executor=converter_executor,
        )
        ingestion_worker = Worker(
            client,
            task_queue=settings.ingestion_task_queue,
            activities=[ingest_markdown_files, mark_source_ready],
            activity_executor=ingestion_executor,
        )

        await asyncio.gather(
            workflow_worker.run(),
            converter_worker.run(),
            ingestion_worker.run(),
        )


if __name__ == "__main__":
    asyncio.run(main())
