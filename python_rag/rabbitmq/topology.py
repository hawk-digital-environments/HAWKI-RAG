from __future__ import annotations

from typing import Any, Dict

try:  # pragma: no cover - optional in lightweight test envs
    from aio_pika import ExchangeType as _ExchangeType
except Exception:  # pragma: no cover
    class _ExchangeType:
        DIRECT = "direct"
        FANOUT = "fanout"
        TOPIC = "topic"
        HEADERS = "headers"


async def declare_topology(channel: Any, settings: Any) -> Dict[str, Any]:
    events_exchange = await channel.declare_exchange(
        settings.events_exchange,
        _exchange_type(settings.events_exchange_type, default=_ExchangeType.DIRECT),
        durable=True,
    )
    retry_exchange = await channel.declare_exchange(
        settings.retry_exchange,
        _exchange_type(settings.retry_exchange_type, default=_ExchangeType.DIRECT),
        durable=True,
    )
    failed_exchange = await channel.declare_exchange(
        settings.failed_exchange,
        _exchange_type(settings.failed_exchange_type, default=_ExchangeType.DIRECT),
        durable=True,
    )

    main_args: Dict[str, Any] = {}
    failed_args: Dict[str, Any] = {}
    retry_args: Dict[str, Any] = {
        "x-message-ttl": int(settings.retry_delay_ms),
        "x-dead-letter-exchange": settings.events_exchange,
        "x-dead-letter-routing-key": settings.document_converted_routing_key,
    }

    if settings.queue_type == "quorum":
        main_args["x-queue-type"] = "quorum"
        failed_args["x-queue-type"] = "quorum"

    ingestion_queue = await channel.declare_queue(
        settings.rag_ingestion_queue,
        durable=True,
        arguments=main_args or None,
    )
    retry_queue = await channel.declare_queue(
        settings.rag_ingestion_retry_queue,
        durable=True,
        arguments=retry_args,
    )
    failed_queue = await channel.declare_queue(
        settings.failed_queue,
        durable=True,
        arguments=failed_args or None,
    )

    await ingestion_queue.bind(events_exchange, routing_key=settings.document_converted_routing_key)
    await retry_queue.bind(retry_exchange, routing_key=settings.rag_ingestion_retry_routing_key)
    await failed_queue.bind(failed_exchange, routing_key=settings.failed_routing_key)

    return {
        "events_exchange": events_exchange,
        "retry_exchange": retry_exchange,
        "failed_exchange": failed_exchange,
        "ingestion_queue": ingestion_queue,
        "retry_queue": retry_queue,
        "failed_queue": failed_queue,
    }


def _exchange_type(raw: str, *, default: Any) -> Any:
    key = (raw or "").strip().lower()
    if key == "fanout":
        return _ExchangeType.FANOUT
    if key == "topic":
        return _ExchangeType.TOPIC
    if key == "headers":
        return _ExchangeType.HEADERS
    return default
