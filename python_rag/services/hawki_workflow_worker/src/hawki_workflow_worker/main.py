"""Process entrypoint for the Temporal workflow worker."""

from __future__ import annotations

import asyncio
import logging

from temporalio.client import Client
from temporalio.worker import Worker

from hawki_workflow_worker.settings import WorkflowWorkerSettings
from hawki_workflow_worker.workflows import IngestSourceWorkflow


async def run_worker(settings: WorkflowWorkerSettings | None = None) -> None:
    """Connect to Temporal and run the deterministic workflow worker."""

    resolved_settings = settings or WorkflowWorkerSettings.from_environment()
    client = await Client.connect(
        resolved_settings.temporal_address,
        namespace=resolved_settings.temporal_namespace,
    )
    worker = Worker(
        client,
        task_queue=resolved_settings.workflow_task_queue,
        workflows=[IngestSourceWorkflow],
    )
    await worker.run()


def main() -> None:
    """Configure process logging and run until the worker is stopped."""

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    asyncio.run(run_worker())


if __name__ == "__main__":
    main()


__all__ = ["main", "run_worker"]
