from __future__ import annotations

import asyncio
import json
import logging
from typing import TYPE_CHECKING, Any, Callable, Dict, Optional, Set

from common.job_schema import (
    ValidationError,
    clone_job_with_retry,
    parse_job_message,
)

from .config import WorkerSettings
from .failure_classifier import (
    FAILURE_PERMANENT,
    classify_failure,
    error_name,
)
from .job_store import JobStore
from .topology import declare_topology

if TYPE_CHECKING:  # pragma: no cover
    from .processor import IngestionProcessor

try:  # pragma: no cover - optional in test envs
    import aio_pika
except Exception:  # pragma: no cover
    aio_pika = None


logger = logging.getLogger(__name__)


class _FallbackMessage:
    def __init__(self, body: bytes):
        self.body = body
        self.content_type = "application/json"
        self.delivery_mode = 2


class RabbitWorker:
    """
    RabbitMQ consumer/worker with explicit retry and failed queue publishing.
    """

    def __init__(
        self,
        *,
        settings: WorkerSettings,
        processor: Any,
        job_store: JobStore,
        failure_classifier: Callable[[Exception], str] = classify_failure,
    ):
        self.settings = settings
        self.processor = processor
        self.job_store = job_store
        self.failure_classifier = failure_classifier

        self.connection: Any = None
        self.channel: Any = None
        self.main_exchange: Any = None
        self.retry_exchange: Any = None
        self.failed_exchange: Any = None
        self.main_queue: Any = None
        self.retry_queue: Any = None
        self.failed_queue: Any = None

        self._consumer_tag: str | None = None
        self._stop_event = asyncio.Event()
        self._stopping = False
        self._inflight_tasks: Set[asyncio.Task[Any]] = set()
        self._inflight_drained = asyncio.Event()
        self._inflight_drained.set()

    async def start(self) -> None:
        if aio_pika is None:  # pragma: no cover - exercised only when dependency missing
            raise RuntimeError("aio-pika is required for RabbitWorker runtime. Install python_rag requirements.")

        self.job_store.setup()
        self.connection = await aio_pika.connect_robust(self.settings.rabbitmq_url)
        self.channel = await self.connection.channel(publisher_confirms=True)
        await self.channel.set_qos(prefetch_count=self.settings.prefetch_count)
        await self._declare_topology()

        self._consumer_tag = await self.main_queue.consume(self.on_message, no_ack=False)
        logger.info(
            "worker started queue=%s exchange=%s retry_queue=%s failed_queue=%s prefetch=%s",
            self.settings.main_queue,
            self.settings.exchange,
            self.settings.retry_queue,
            self.settings.failed_queue,
            self.settings.prefetch_count,
        )

    async def run(self) -> None:
        await self.start()
        await self._stop_event.wait()

    async def stop(self) -> None:
        if self._stopping:
            return
        self._stopping = True

        if self.main_queue is not None and self._consumer_tag:
            try:
                await self.main_queue.cancel(self._consumer_tag)
            except Exception:
                logger.exception("worker stop: cancel consumer failed")

        if self._inflight_tasks:
            try:
                await asyncio.wait_for(
                    self._inflight_drained.wait(),
                    timeout=self.settings.shutdown_grace_seconds,
                )
            except asyncio.TimeoutError:
                logger.warning(
                    "worker stop: shutdown grace timeout reached with inflight=%s",
                    len(self._inflight_tasks),
                )

        if self.channel is not None:
            try:
                await self.channel.close()
            except Exception:
                logger.exception("worker stop: close channel failed")

        if self.connection is not None:
            try:
                await self.connection.close()
            except Exception:
                logger.exception("worker stop: close connection failed")

        self._stop_event.set()
        logger.info("worker stopped")

    async def on_message(self, message: Any) -> None:
        current = asyncio.current_task()
        if current is not None:
            self._inflight_tasks.add(current)
            self._inflight_drained.clear()

        try:
            await self._handle_message(message)
        finally:
            if current is not None:
                self._inflight_tasks.discard(current)
            if not self._inflight_tasks:
                self._inflight_drained.set()

    async def _handle_message(self, message: Any) -> None:
        queue = self.settings.main_queue
        exchange = self.settings.exchange

        try:
            payload = json.loads(message.body.decode("utf-8"))
        except Exception as exc:
            err_type = error_name(exc)
            await self._publish_failed(
                payload={
                    "job_id": "__invalid__",
                    "retry_count": 0,
                    "max_retries": self.settings.max_retries,
                    "error_type": err_type,
                    "error_message": str(exc),
                    "raw_body": message.body.decode("utf-8", errors="replace"),
                }
            )
            self._log_event(
                "failed-published",
                job_id="__invalid__",
                retry_count=0,
                max_retries=self.settings.max_retries,
                queue=queue,
                exchange=exchange,
                error_type=err_type,
                processing_stage="schema-validate",
            )
            await message.ack()
            return

        try:
            job = parse_job_message(message.body)
        except (ValidationError, json.JSONDecodeError, UnicodeDecodeError) as exc:
            job_id = str(payload.get("job_id", "__invalid__"))
            retry_count = int(payload.get("retry_count", 0) or 0)
            max_retries = int(payload.get("max_retries", self.settings.max_retries) or self.settings.max_retries)
            err_type = error_name(exc)
            failed_payload = dict(payload)
            failed_payload.update(
                {
                    "error_type": err_type,
                    "error_message": str(exc),
                    "max_retries": max_retries,
                    "retry_count": retry_count,
                }
            )
            await self._publish_failed(payload=failed_payload)
            self._log_event(
                "failed-published",
                job_id=job_id,
                retry_count=retry_count,
                max_retries=max_retries,
                queue=queue,
                exchange=exchange,
                error_type=err_type,
                processing_stage="schema-validate",
            )
            await message.ack()
            return

        job_id = job.job_id
        retry_count = int(job.retry_count or 0)
        max_retries = int(job.max_retries if job.max_retries is not None else self.settings.max_retries)

        self._log_event(
            "receive",
            job_id=job_id,
            retry_count=retry_count,
            max_retries=max_retries,
            queue=queue,
            exchange=exchange,
            processing_stage="receive",
        )

        claimed = self.job_store.claim_for_processing(
            job_id=job_id,
            retry_count=retry_count,
            max_retries=max_retries,
            payload=payload,
        )
        if not claimed:
            self._log_event(
                "skip-duplicate",
                job_id=job_id,
                retry_count=retry_count,
                max_retries=max_retries,
                queue=queue,
                exchange=exchange,
                processing_stage="idempotency-check",
            )
            await message.ack()
            return

        self._log_event(
            "processing-start",
            job_id=job_id,
            retry_count=retry_count,
            max_retries=max_retries,
            queue=queue,
            exchange=exchange,
            processing_stage="ingest",
        )

        try:
            await self.processor.process(job)
        except Exception as exc:
            failure_kind = self.failure_classifier(exc)
            err_type = error_name(exc)
            err_text = str(exc)

            if failure_kind != FAILURE_PERMANENT:
                next_retry = retry_count + 1
                if next_retry <= max_retries:
                    retry_payload = clone_job_with_retry(job, retry_count=next_retry, max_retries=max_retries)
                    retry_payload["error_type"] = err_type
                    retry_payload["error_message"] = err_text
                    await self._publish_retry(payload=retry_payload)
                    self.job_store.mark_retry_queued(
                        job_id=job_id,
                        retry_count=next_retry,
                        max_retries=max_retries,
                        error_type=err_type,
                        error_message=err_text,
                    )
                    self._log_event(
                        "retry-published",
                        job_id=job_id,
                        retry_count=next_retry,
                        max_retries=max_retries,
                        queue=queue,
                        exchange=self.settings.retry_exchange,
                        error_type=err_type,
                        processing_stage="ingest",
                    )
                    await message.ack()
                    return

            failed_retry_count = retry_count + 1 if failure_kind != FAILURE_PERMANENT else retry_count
            failed_payload = clone_job_with_retry(job, retry_count=failed_retry_count, max_retries=max_retries)
            failed_payload["error_type"] = err_type
            failed_payload["error_message"] = err_text
            await self._publish_failed(payload=failed_payload)
            self.job_store.mark_failed(
                job_id=job_id,
                retry_count=failed_retry_count,
                max_retries=max_retries,
                error_type=err_type,
                error_message=err_text,
            )
            self._log_event(
                "failed-published",
                job_id=job_id,
                retry_count=failed_retry_count,
                max_retries=max_retries,
                queue=queue,
                exchange=self.settings.failed_exchange,
                error_type=err_type,
                processing_stage="ingest",
            )
            await message.ack()
            return

        self.job_store.mark_completed(
            job_id=job_id,
            retry_count=retry_count,
            max_retries=max_retries,
        )
        self._log_event(
            "success",
            job_id=job_id,
            retry_count=retry_count,
            max_retries=max_retries,
            queue=queue,
            exchange=exchange,
            processing_stage="ingest",
        )
        await message.ack()

    async def _declare_topology(self) -> None:
        if self.channel is None:
            raise RuntimeError("RabbitMQ channel is not initialized")
        topology = await declare_topology(self.channel, self.settings)
        self.main_exchange = topology["main_exchange"]
        self.retry_exchange = topology["retry_exchange"]
        self.failed_exchange = topology["failed_exchange"]
        self.main_queue = topology["main_queue"]
        self.retry_queue = topology["retry_queue"]
        self.failed_queue = topology["failed_queue"]

    async def _publish_retry(self, *, payload: Dict[str, Any]) -> None:
        await self._publish_json(
            exchange=self.retry_exchange,
            routing_key=self.settings.retry_routing_key,
            payload=payload,
        )

    async def _publish_failed(self, *, payload: Dict[str, Any]) -> None:
        await self._publish_json(
            exchange=self.failed_exchange,
            routing_key=self.settings.failed_routing_key,
            payload=payload,
        )

    async def _publish_json(self, *, exchange: Any, routing_key: str, payload: Dict[str, Any]) -> None:
        if exchange is None:
            raise RuntimeError("RabbitMQ exchange is not initialized")
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        msg = self._make_message(body)
        await exchange.publish(msg, routing_key=routing_key)

    @staticmethod
    def _make_message(body: bytes) -> Any:
        if aio_pika is None:  # pragma: no cover - lightweight fallback for tests
            return _FallbackMessage(body)
        return aio_pika.Message(
            body=body,
            content_type="application/json",
            delivery_mode=aio_pika.DeliveryMode.PERSISTENT,
        )

    def _log_event(
        self,
        event: str,
        *,
        job_id: str,
        retry_count: int,
        max_retries: int,
        queue: str,
        exchange: str,
        processing_stage: str,
        error_type: str | None = None,
    ) -> None:
        payload = {
            "event": event,
            "job_id": job_id,
            "retry_count": int(retry_count),
            "max_retries": int(max_retries),
            "queue": queue,
            "exchange": exchange,
            "error_type": error_type or "",
            "processing_stage": processing_stage,
        }
        logger.info(json.dumps(payload, ensure_ascii=False, sort_keys=True))
