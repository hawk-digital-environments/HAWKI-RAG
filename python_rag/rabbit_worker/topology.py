from __future__ import annotations

from typing import Any, Dict

from .config import WorkerSettings


async def declare_topology(channel: Any, settings: WorkerSettings) -> Dict[str, Any]:
    from aio_pika import ExchangeType

    main_exchange = await channel.declare_exchange(
        settings.exchange,
        ExchangeType.DIRECT,
        durable=True,
    )
    retry_exchange = await channel.declare_exchange(
        settings.retry_exchange,
        ExchangeType.DIRECT,
        durable=True,
    )
    failed_exchange = await channel.declare_exchange(
        settings.failed_exchange,
        ExchangeType.DIRECT,
        durable=True,
    )

    main_args: Dict[str, Any] = {}
    failed_args: Dict[str, Any] = {}
    retry_args: Dict[str, Any] = {
        "x-message-ttl": int(settings.retry_delay_ms),
        "x-dead-letter-exchange": settings.exchange,
        "x-dead-letter-routing-key": settings.main_routing_key,
    }

    if settings.queue_type == "quorum":
        main_args["x-queue-type"] = "quorum"
        failed_args["x-queue-type"] = "quorum"

    if settings.retry_queue_type == "quorum":
        retry_args["x-queue-type"] = "quorum"

    main_queue = await channel.declare_queue(
        settings.main_queue,
        durable=True,
        arguments=main_args or None,
    )
    retry_queue = await channel.declare_queue(
        settings.retry_queue,
        durable=True,
        arguments=retry_args,
    )
    failed_queue = await channel.declare_queue(
        settings.failed_queue,
        durable=True,
        arguments=failed_args or None,
    )

    await main_queue.bind(main_exchange, routing_key=settings.main_routing_key)
    await retry_queue.bind(retry_exchange, routing_key=settings.retry_routing_key)
    await failed_queue.bind(failed_exchange, routing_key=settings.failed_routing_key)

    return {
        "main_exchange": main_exchange,
        "retry_exchange": retry_exchange,
        "failed_exchange": failed_exchange,
        "main_queue": main_queue,
        "retry_queue": retry_queue,
        "failed_queue": failed_queue,
    }
