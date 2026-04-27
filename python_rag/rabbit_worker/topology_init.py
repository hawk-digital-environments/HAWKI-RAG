from __future__ import annotations

import asyncio
import logging
import os

import aio_pika
from aio_pika.exceptions import AMQPException

from .config import WorkerSettings
from .topology import declare_topology

logger = logging.getLogger(__name__)


def _configure_logging() -> None:
    level = os.environ.get("LOG_LEVEL", "INFO").upper()
    logging.basicConfig(level=level, format="%(asctime)s %(levelname)s:%(name)s:%(message)s")


def _int_env(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


async def _connect_with_retry(url: str) -> aio_pika.RobustConnection:
    max_wait_s = max(1, _int_env("RABBITMQ_TOPOLOGY_INIT_MAX_WAIT_SECONDS", 90))
    retry_delay_s = max(1, _int_env("RABBITMQ_TOPOLOGY_INIT_RETRY_DELAY_SECONDS", 3))
    deadline = asyncio.get_running_loop().time() + max_wait_s
    attempt = 0
    last_exc: Exception | None = None

    while asyncio.get_running_loop().time() < deadline:
        attempt += 1
        try:
            connection = await aio_pika.connect_robust(url, timeout=10)
            logger.info("Topology init connected to RabbitMQ on attempt=%s", attempt)
            return connection
        except (OSError, AMQPException, asyncio.TimeoutError) as exc:
            last_exc = exc
            logger.warning(
                "Topology init RabbitMQ connect failed attempt=%s retry_in=%ss error=%s",
                attempt,
                retry_delay_s,
                exc,
            )
            await asyncio.sleep(retry_delay_s)

    if last_exc is not None:
        raise last_exc
    raise RuntimeError("Topology init failed to connect to RabbitMQ")


async def _main() -> None:
    settings = WorkerSettings.from_env()
    connection = await _connect_with_retry(settings.rabbitmq_url)
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
