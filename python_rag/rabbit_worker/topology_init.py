from __future__ import annotations

import asyncio
import logging
import os

import aio_pika

from .config import WorkerSettings
from .topology import declare_topology


def _configure_logging() -> None:
    level = os.environ.get("LOG_LEVEL", "INFO").upper()
    logging.basicConfig(level=level, format="%(asctime)s %(levelname)s:%(name)s:%(message)s")


async def _main() -> None:
    settings = WorkerSettings.from_env()
    connection = await aio_pika.connect_robust(settings.rabbitmq_url)
    try:
        channel = await connection.channel(publisher_confirms=True)
        await declare_topology(channel, settings)
        await channel.close()
    finally:
        await connection.close()


def main() -> None:
    _configure_logging()
    asyncio.run(_main())


if __name__ == "__main__":
    main()

