#!/usr/bin/env python3
from __future__ import annotations

import asyncio
import logging
import os
import signal

from rabbit_worker.bootstrap import RabbitWorker
from rabbit_worker.config import WorkerSettings
from rabbit_worker.job_store import JobStore
from rabbit_worker.processor import IngestionProcessor


def _configure_logging() -> None:
    level = os.environ.get("LOG_LEVEL", "INFO").upper()
    logging.basicConfig(level=level, format="%(asctime)s %(levelname)s:%(name)s:%(message)s")


async def _run_worker() -> None:
    settings = WorkerSettings.from_env()
    worker = RabbitWorker(
        settings=settings,
        processor=IngestionProcessor(),
        job_store=JobStore(settings.idempotency_db_path),
    )

    loop = asyncio.get_running_loop()

    def _request_shutdown() -> None:
        asyncio.create_task(worker.stop())

    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            loop.add_signal_handler(sig, _request_shutdown)
        except NotImplementedError:  # pragma: no cover - Windows fallback
            pass

    try:
        await worker.run()
    finally:
        await worker.stop()


def main() -> None:
    _configure_logging()
    asyncio.run(_run_worker())


if __name__ == "__main__":
    main()

