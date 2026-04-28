from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict
from urllib.parse import quote

from .topology import declare_topology

try:  # pragma: no cover - optional in lightweight unit tests
    import aio_pika
except Exception:  # pragma: no cover
    aio_pika = None


def _bool_env(name: str, default: bool) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return str(raw).strip().lower() in {"1", "true", "yes", "on"}


def _int_env(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


@dataclass(frozen=True)
class RabbitMQSettings:
    communication_enabled: bool
    communication_method: str
    rabbitmq_host: str
    rabbitmq_port: int
    rabbitmq_user: str
    rabbitmq_password: str
    rabbitmq_vhost: str
    rabbitmq_heartbeat: int
    rabbitmq_connection_timeout: int
    rabbitmq_url: str

    events_exchange: str
    events_exchange_type: str
    retry_exchange: str
    retry_exchange_type: str
    failed_exchange: str
    failed_exchange_type: str

    rag_ingestion_queue: str
    document_converted_routing_key: str
    rag_ingestion_retry_queue: str
    rag_ingestion_retry_routing_key: str
    failed_queue: str
    failed_routing_key: str

    retry_delay_ms: int
    prefetch_count: int
    max_retries: int
    queue_type: str
    publisher_confirms: bool
    persistent_messages: bool

    job_db_path: str
    shared_storage_root: str
    schema_version: str
    service_name: str

    @classmethod
    def from_env(cls) -> "RabbitMQSettings":
        host = os.environ.get("RABBITMQ_HOST", "rabbitmq").strip()
        port = max(1, _int_env("RABBITMQ_PORT", 5672))
        user = os.environ.get("RABBITMQ_USER", "guest").strip()
        password = os.environ.get("RABBITMQ_PASSWORD", "guest").strip()
        vhost = os.environ.get("RABBITMQ_VHOST", "/").strip() or "/"
        vhost_url = quote(vhost, safe="")
        default_url = f"amqp://{user}:{password}@{host}:{port}/{vhost_url}"

        project_root = Path(__file__).resolve().parents[2]
        default_db = project_root / "storage" / "app" / "private" / "rag_worker_jobs.sqlite"

        return cls(
            communication_enabled=_bool_env("COMMUNICATION_ENABLED", True),
            communication_method=os.environ.get("COMMUNICATION_METHOD", "rabbitmq").strip().lower(),
            rabbitmq_host=host,
            rabbitmq_port=port,
            rabbitmq_user=user,
            rabbitmq_password=password,
            rabbitmq_vhost=vhost,
            rabbitmq_heartbeat=max(1, _int_env("RABBITMQ_HEARTBEAT", 30)),
            rabbitmq_connection_timeout=max(1, _int_env("RABBITMQ_CONNECTION_TIMEOUT", 30)),
            rabbitmq_url=os.environ.get("RABBITMQ_URL", default_url).strip() or default_url,
            events_exchange=os.environ.get("RABBITMQ_EVENTS_EXCHANGE", "pipeline.events"),
            events_exchange_type=os.environ.get("RABBITMQ_EVENTS_EXCHANGE_TYPE", "direct"),
            retry_exchange=os.environ.get("RABBITMQ_RETRY_EXCHANGE", "pipeline.retry"),
            retry_exchange_type=os.environ.get("RABBITMQ_RETRY_EXCHANGE_TYPE", "direct"),
            failed_exchange=os.environ.get("RABBITMQ_FAILED_EXCHANGE", "pipeline.failed"),
            failed_exchange_type=os.environ.get("RABBITMQ_FAILED_EXCHANGE_TYPE", "direct"),
            rag_ingestion_queue=os.environ.get("RABBITMQ_RAG_INGESTION_QUEUE", "rag_ingestion_jobs"),
            document_converted_routing_key=os.environ.get(
                "RABBITMQ_DOCUMENT_CONVERTED_ROUTING_KEY", "convert.document.completed"
            ),
            rag_ingestion_retry_queue=os.environ.get(
                "RABBITMQ_RAG_INGESTION_RETRY_QUEUE", "rag_ingestion_jobs_retry"
            ),
            rag_ingestion_retry_routing_key=os.environ.get(
                "RABBITMQ_RAG_INGESTION_RETRY_ROUTING_KEY", "convert.document.completed.retry"
            ),
            failed_queue=os.environ.get("RABBITMQ_FAILED_QUEUE", "failed_jobs"),
            failed_routing_key=os.environ.get("RABBITMQ_FAILED_ROUTING_KEY", "pipeline.failed"),
            retry_delay_ms=max(1, _int_env("RABBITMQ_RETRY_DELAY_MS", 5000)),
            prefetch_count=max(1, _int_env("RABBITMQ_PREFETCH_COUNT", 1)),
            max_retries=max(0, _int_env("RABBITMQ_MAX_RETRIES", 3)),
            queue_type=os.environ.get("RABBITMQ_QUEUE_TYPE", "quorum").strip().lower(),
            publisher_confirms=_bool_env("RABBITMQ_PUBLISHER_CONFIRMS", True),
            persistent_messages=_bool_env("RABBITMQ_PERSISTENT_MESSAGES", True),
            job_db_path=os.environ.get("RABBITMQ_JOB_DB_PATH", str(default_db)),
            shared_storage_root=os.environ.get("SHARED_STORAGE_ROOT", "/app/shared"),
            schema_version=os.environ.get("JOB_SCHEMA_VERSION", "1"),
            service_name=os.environ.get("SERVICE_NAME", "hawki-rag"),
        )


class RabbitMQClient:
    def __init__(self, settings: RabbitMQSettings):
        self.settings = settings
        self.connection: Any = None
        self.channel: Any = None
        self.events_exchange: Any = None
        self.retry_exchange: Any = None
        self.failed_exchange: Any = None
        self.ingestion_queue: Any = None
        self.retry_queue: Any = None
        self.failed_queue: Any = None

    async def connect(self) -> None:
        if aio_pika is None:  # pragma: no cover - runtime dependency
            raise RuntimeError("aio-pika is required for RabbitMQ client runtime.")
        self.connection = await aio_pika.connect_robust(
            self.settings.rabbitmq_url,
            timeout=self.settings.rabbitmq_connection_timeout,
            heartbeat=self.settings.rabbitmq_heartbeat,
        )
        self.channel = await self.connection.channel(publisher_confirms=self.settings.publisher_confirms)
        await self.channel.set_qos(prefetch_count=self.settings.prefetch_count)
        topology = await declare_topology(self.channel, self.settings)
        self.events_exchange = topology["events_exchange"]
        self.retry_exchange = topology["retry_exchange"]
        self.failed_exchange = topology["failed_exchange"]
        self.ingestion_queue = topology["ingestion_queue"]
        self.retry_queue = topology["retry_queue"]
        self.failed_queue = topology["failed_queue"]

    async def close(self) -> None:
        if self.channel is not None:
            try:
                await self.channel.close()
            except Exception:
                pass
            self.channel = None
        if self.connection is not None:
            try:
                await self.connection.close()
            except Exception:
                pass
            self.connection = None

    async def consume_ingestion_queue(self, callback) -> str:
        if self.ingestion_queue is None:
            raise RuntimeError("RabbitMQ ingestion queue is not initialized")
        return await self.ingestion_queue.consume(callback, no_ack=False)

    async def cancel_consumer(self, consumer_tag: str) -> None:
        if self.ingestion_queue is not None and consumer_tag:
            await self.ingestion_queue.cancel(consumer_tag)

    async def publish_retry(self, payload: Dict[str, Any]) -> None:
        await self._publish_json(
            exchange=self.retry_exchange,
            routing_key=self.settings.rag_ingestion_retry_routing_key,
            payload=payload,
        )

    async def publish_failed_event(self, payload: Dict[str, Any]) -> None:
        await self._publish_json(
            exchange=self.failed_exchange,
            routing_key=self.settings.failed_routing_key,
            payload=payload,
        )

    async def _publish_json(self, *, exchange: Any, routing_key: str, payload: Dict[str, Any]) -> None:
        if exchange is None:
            raise RuntimeError("RabbitMQ exchange is not initialized")
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        message = self._make_message(body)
        await exchange.publish(message, routing_key=routing_key)

    def _make_message(self, body: bytes) -> Any:
        if aio_pika is None:  # pragma: no cover
            raise RuntimeError("aio-pika is required for publish operations.")
        mode = aio_pika.DeliveryMode.PERSISTENT if self.settings.persistent_messages else aio_pika.DeliveryMode.NOT_PERSISTENT
        return aio_pika.Message(body=body, content_type="application/json", delivery_mode=mode)

