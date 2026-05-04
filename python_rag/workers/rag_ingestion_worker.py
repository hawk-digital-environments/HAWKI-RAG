from __future__ import annotations

import asyncio
import json
import logging
import os
import signal
from pathlib import Path
from types import SimpleNamespace
from typing import TYPE_CHECKING, Any, Callable, Dict, Set
from uuid import UUID, uuid4

from common.pipeline_events import (
    DocumentConvertedEvent,
    PipelineFailedEvent,
    ValidationError,
    model_to_dict,
    parse_document_converted_event,
    utc_now,
)
from rabbitmq.client import RabbitMQClient, RabbitMQSettings

from .failure_classifier import (
    FAILURE_PERMANENT,
    PermanentIngestionError,
    classify_failure,
    error_name,
)
from .job_state_store import JobStateStore

logger = logging.getLogger(__name__)

STAGE_RAG_INGESTION = "rag_ingestion"

if TYPE_CHECKING:  # pragma: no cover
    from core.rag_service import RAGService


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


def _int_from_payload(payload: Dict[str, Any], key: str, default: int) -> int:
    raw = payload.get(key, default)
    try:
        return int(raw)
    except Exception:
        return default


def _coerce_bool(value: Any, default: bool = False) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def _safe_uuid(value: Any) -> UUID:
    if isinstance(value, UUID):
        return value
    try:
        return UUID(str(value))
    except Exception:
        return uuid4()


def resolve_shared_markdown_path(converted_path: str, shared_root: str) -> Path:
    root = Path(shared_root).resolve(strict=False)
    candidate = Path(converted_path)
    if not candidate.is_absolute():
        candidate = root / candidate

    try:
        resolved = candidate.resolve(strict=True)
    except FileNotFoundError as exc:
        raise PermanentIngestionError(f"converted file missing: {candidate}") from exc

    if not resolved.is_file():
        raise PermanentIngestionError(f"converted path is not a file: {resolved}")

    try:
        resolved.relative_to(root)
    except Exception as exc:
        raise PermanentIngestionError(f"path outside allowed root: {resolved}") from exc

    return resolved


class ConvertedDocumentIngestor:
    """
    Converts a validated convert.document.completed event into the existing ingest_documents call.
    """

    def __init__(self, *, rag_service: "RAGService" | None = None, public_dir: Path | None = None):
        if rag_service is None:
            from core.rag_service import RAGService

            rag_service = RAGService()
        self.rag_service = rag_service
        self.public_dir = public_dir or (Path(__file__).resolve().parents[2] / "public")

    def _get_provider(self, name: str):
        try:
            return self.rag_service.get_provider(name)
        except ValueError as exc:
            raise PermanentIngestionError(str(exc)) from exc

    @staticmethod
    def _read_markdown(path: Path) -> str:
        text = path.read_text(encoding="utf-8", errors="ignore")
        if not text.strip():
            raise PermanentIngestionError(f"empty converted document: {path}")
        return text

    @staticmethod
    def _title_from_event(event: DocumentConvertedEvent, file_path: Path) -> str:
        payload = event.payload or {}
        title = payload.get("title") if isinstance(payload, dict) else None
        if title and str(title).strip():
            return str(title).strip()
        if event.original_relative_path:
            return Path(event.original_relative_path).stem or file_path.stem
        if event.converted_relative_path:
            return Path(event.converted_relative_path).stem or file_path.stem
        return file_path.stem or str(event.job_id)

    def _to_ingest_body(self, *, event: DocumentConvertedEvent, file_path: Path) -> Any:
        payload_extra = dict(event.payload or {})
        payload = dict(payload_extra)
        payload.setdefault("source_format", "markdown")
        payload.setdefault("source_type", "convert.document.completed")
        payload.setdefault("file_path", str(file_path))
        payload.setdefault("source_url", event.original_url)
        payload.setdefault("page_url", event.original_url)
        payload.setdefault("original_path", event.original_path)
        payload.setdefault("original_relative_path", event.original_relative_path)
        payload.setdefault("converted_path", event.converted_path)
        payload.setdefault("converted_relative_path", event.converted_relative_path)
        payload.setdefault("converter_name", event.converter_name)
        payload.setdefault("converter_version", event.converter_version)
        payload.setdefault("input_checksum_sha256", event.input_checksum_sha256)
        payload.setdefault("output_checksum_sha256", event.output_checksum_sha256)
        payload.setdefault("trace_id", event.trace_id)
        payload.setdefault("job_id", str(event.job_id))
        payload.setdefault("event_id", str(event.event_id))
        payload.setdefault("title", self._title_from_event(event, file_path))

        provider = str(payload_extra.get("provider") or os.environ.get("RAG_DEFAULT_PROVIDER", "ollama")).strip()
        embedding_model = payload_extra.get("embedding_model")
        collection = payload_extra.get("collection")
        neo4j_database = payload_extra.get("neo4j_database")
        graph_enabled = _coerce_bool(payload_extra.get("graph"), default=_bool_env("RAG_INGEST_GRAPH", False))

        return SimpleNamespace(
            docs=[
                SimpleNamespace(
                    id=str(event.job_id),  # deterministic per job_id for idempotent qdrant/graph upserts
                    text=self._read_markdown(file_path),
                    payload=payload,
                )
            ],
            provider=provider,
            embedding_model=embedding_model,
            collection=collection,
            neo4j_database=neo4j_database,
            distance=str(payload_extra.get("distance") or os.environ.get("QDRANT_DISTANCE", "Cosine")),
            chunk_chars=max(1, int(payload_extra.get("chunk_chars") or _int_env("CHUNK_SIZE", 1200))),
            chunk_overlap=max(0, int(payload_extra.get("chunk_overlap") or _int_env("CHUNK_OVERLAP_SIZE", 250))),
            batch_size=max(1, int(payload_extra.get("batch_size") or _int_env("INGEST_BATCH_SIZE", 64))),
            graph=graph_enabled,
            graph_engine=str(payload_extra.get("graph_engine") or os.environ.get("GRAPH_ENGINE", "raganything")),
            graph_only=_coerce_bool(payload_extra.get("graph_only"), default=False),
            dry_run=_coerce_bool(payload_extra.get("dry_run"), default=False),
            dry_include_graph=_coerce_bool(payload_extra.get("dry_include_graph"), default=False),
        )

    async def process(self, event: DocumentConvertedEvent, *, file_path: Path) -> Dict[str, Any]:
        if event.output_format != "markdown":
            raise PermanentIngestionError(f"unsupported output format: {event.output_format}")

        body = self._to_ingest_body(event=event, file_path=file_path)
        from pipeline.ingest_logic import ingest_documents

        return await asyncio.to_thread(
            ingest_documents,
            body,
            rag_service=self.rag_service,
            get_provider=self._get_provider,
            public_dir=self.public_dir,
        )


class RagIngestionWorker:
    def __init__(
        self,
        *,
        settings: RabbitMQSettings,
        rabbit_client: RabbitMQClient,
        processor: Any,
        state_store: JobStateStore,
        failure_classifier: Callable[[Exception], str] = classify_failure,
    ):
        self.settings = settings
        self.rabbit_client = rabbit_client
        self.processor = processor
        self.state_store = state_store
        self.failure_classifier = failure_classifier

        self._consumer_tag: str | None = None
        self._stop_event = asyncio.Event()
        self._stopping = False
        self._inflight_tasks: Set[asyncio.Task[Any]] = set()
        self._inflight_drained = asyncio.Event()
        self._inflight_drained.set()

    async def start(self) -> None:
        if not self.settings.communication_enabled:
            raise RuntimeError("COMMUNICATION_ENABLED=false; rag ingestion worker is disabled.")
        if self.settings.communication_method != "rabbitmq":
            raise RuntimeError(
                f"COMMUNICATION_METHOD={self.settings.communication_method}; expected 'rabbitmq' for this worker."
            )

        self.state_store.setup()
        await self.rabbit_client.connect()
        self._consumer_tag = await self.rabbit_client.consume_ingestion_queue(self.on_message)
        logger.info(
            "rag worker started queue=%s exchange=%s retry_exchange=%s failed_exchange=%s prefetch=%s",
            self.settings.rag_ingestion_queue,
            self.settings.events_exchange,
            self.settings.retry_exchange,
            self.settings.failed_exchange,
            self.settings.prefetch_count,
        )

    async def run(self) -> None:
        await self.start()
        await self._stop_event.wait()

    async def stop(self) -> None:
        if self._stopping:
            return
        self._stopping = True

        if self._consumer_tag:
            try:
                await self.rabbit_client.cancel_consumer(self._consumer_tag)
            except Exception:
                logger.exception("rag worker stop: cancel consumer failed")

        if self._inflight_tasks:
            try:
                await asyncio.wait_for(
                    self._inflight_drained.wait(),
                    timeout=max(1, _int_env("RABBITMQ_SHUTDOWN_GRACE_SECONDS", 30)),
                )
            except asyncio.TimeoutError:
                logger.warning("rag worker stop: inflight timeout tasks=%s", len(self._inflight_tasks))

        await self.rabbit_client.close()
        self._stop_event.set()
        logger.info("rag worker stopped")

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
        raw_body = message.body.decode("utf-8", errors="replace")
        try:
            payload = json.loads(raw_body)
        except Exception as exc:
            await self._handle_invalid_payload(message, {"raw_body": raw_body}, exc)
            return

        retry_count = max(0, _int_from_payload(payload, "retry_count", 0))
        max_retries = max(0, _int_from_payload(payload, "max_retries", self.settings.max_retries))

        try:
            event = parse_document_converted_event(payload)
        except (ValidationError, json.JSONDecodeError, UnicodeDecodeError) as exc:
            await self._handle_invalid_payload(message, payload, exc, retry_count=retry_count, max_retries=max_retries)
            return

        job_id = str(event.job_id)
        self._log_event(
            "receive",
            job_id=job_id,
            retry_count=retry_count,
            max_retries=max_retries,
            queue=self.settings.rag_ingestion_queue,
            exchange=self.settings.events_exchange,
            processing_stage="receive",
        )

        try:
            file_path = resolve_shared_markdown_path(event.converted_path, self.settings.shared_storage_root)
        except Exception as exc:
            await self._handle_event_failure(
                message=message,
                event=event,
                payload=payload,
                retry_count=retry_count,
                max_retries=max_retries,
                exc=exc,
            )
            return

        input_checksum = event.output_checksum_sha256 or event.input_checksum_sha256
        claimed = self.state_store.claim_for_processing(
            job_id=job_id,
            stage=STAGE_RAG_INGESTION,
            source=event.source,
            input_path=event.original_path,
            output_path=str(file_path),
            input_checksum=input_checksum,
            retry_count=retry_count,
            max_retries=max_retries,
            trace_id=event.trace_id,
        )
        if not claimed:
            self._log_event(
                "skip-duplicate",
                job_id=job_id,
                retry_count=retry_count,
                max_retries=max_retries,
                queue=self.settings.rag_ingestion_queue,
                exchange=self.settings.events_exchange,
                processing_stage="idempotency-check",
            )
            await message.ack()
            return

        self._log_event(
            "processing-start",
            job_id=job_id,
            retry_count=retry_count,
            max_retries=max_retries,
            queue=self.settings.rag_ingestion_queue,
            exchange=self.settings.events_exchange,
            processing_stage="rag_ingestion",
        )

        try:
            await self.processor.process(event, file_path=file_path)
        except Exception as exc:
            await self._handle_event_failure(
                message=message,
                event=event,
                payload=payload,
                retry_count=retry_count,
                max_retries=max_retries,
                exc=exc,
                input_path=event.original_path,
                output_path=str(file_path),
                input_checksum=input_checksum,
            )
            return

        self.state_store.mark_completed(
            job_id=job_id,
            stage=STAGE_RAG_INGESTION,
            source=event.source,
            input_path=event.original_path,
            output_path=str(file_path),
            input_checksum=input_checksum,
            retry_count=retry_count,
            max_retries=max_retries,
            trace_id=event.trace_id,
        )
        self._log_event(
            "success",
            job_id=job_id,
            retry_count=retry_count,
            max_retries=max_retries,
            queue=self.settings.rag_ingestion_queue,
            exchange=self.settings.events_exchange,
            processing_stage="rag_ingestion",
        )
        await message.ack()

    async def _handle_invalid_payload(
        self,
        message: Any,
        payload: Dict[str, Any],
        exc: Exception,
        *,
        retry_count: int = 0,
        max_retries: int | None = None,
    ) -> None:
        max_retries = self.settings.max_retries if max_retries is None else max_retries
        failed_payload = await self._publish_failed_payload(
            payload=payload,
            exc=exc,
            retry_count=retry_count,
            max_retries=max_retries,
            parent_event_id=payload.get("event_id"),
            job_id=payload.get("job_id"),
            trace_id=payload.get("trace_id"),
            original_event_type=str(payload.get("event_type", "convert.document.completed")),
        )
        self._log_event(
            "failed-published",
            job_id=str(failed_payload["job_id"]),
            retry_count=retry_count,
            max_retries=max_retries,
            queue=self.settings.rag_ingestion_queue,
            exchange=self.settings.failed_exchange,
            error_type=error_name(exc),
            error_message=str(exc),
            processing_stage="schema-validate",
        )
        await message.ack()

    async def _handle_event_failure(
        self,
        *,
        message: Any,
        event: DocumentConvertedEvent,
        payload: Dict[str, Any],
        retry_count: int,
        max_retries: int,
        exc: Exception,
        input_path: str | None = None,
        output_path: str | None = None,
        input_checksum: str | None = None,
    ) -> None:
        failure_kind = self.failure_classifier(exc)
        err_type = error_name(exc)
        err_text = str(exc)
        job_id = str(event.job_id)

        if failure_kind != FAILURE_PERMANENT:
            next_retry = retry_count + 1
            if next_retry <= max_retries:
                retry_payload = dict(payload)
                retry_payload["retry_count"] = int(next_retry)
                retry_payload["max_retries"] = int(max_retries)
                retry_payload["last_error_type"] = err_type
                retry_payload["last_error_message"] = err_text
                await self.rabbit_client.publish_retry(retry_payload)

                self.state_store.mark_received(
                    job_id=job_id,
                    stage=STAGE_RAG_INGESTION,
                    source=event.source,
                    input_path=input_path or event.original_path,
                    output_path=output_path or event.converted_path,
                    input_checksum=input_checksum or event.output_checksum_sha256 or event.input_checksum_sha256,
                    retry_count=next_retry,
                    max_retries=max_retries,
                    trace_id=event.trace_id,
                    error_type=err_type,
                    error_message=err_text,
                )
                self._log_event(
                    "retry-published",
                    job_id=job_id,
                    retry_count=next_retry,
                    max_retries=max_retries,
                    queue=self.settings.rag_ingestion_queue,
                    exchange=self.settings.retry_exchange,
                    error_type=err_type,
                    error_message=err_text,
                    processing_stage="rag_ingestion",
                )
                await message.ack()
                return

        failed_retry_count = retry_count if failure_kind == FAILURE_PERMANENT else retry_count + 1
        failed_payload = await self._publish_failed_payload(
            payload=payload,
            exc=exc,
            retry_count=failed_retry_count,
            max_retries=max_retries,
            parent_event_id=event.event_id,
            job_id=event.job_id,
            trace_id=event.trace_id,
            original_event_type=event.event_type,
        )
        self.state_store.mark_failed(
            job_id=job_id,
            stage=STAGE_RAG_INGESTION,
            source=event.source,
            input_path=input_path or event.original_path,
            output_path=output_path or event.converted_path,
            input_checksum=input_checksum or event.output_checksum_sha256 or event.input_checksum_sha256,
            retry_count=failed_retry_count,
            max_retries=max_retries,
            trace_id=event.trace_id,
            error_type=failed_payload["error_type"],
            error_message=failed_payload["error_message"],
        )
        self._log_event(
            "failed-published",
            job_id=job_id,
            retry_count=failed_retry_count,
            max_retries=max_retries,
            queue=self.settings.rag_ingestion_queue,
            exchange=self.settings.failed_exchange,
            error_type=failed_payload["error_type"],
            error_message=failed_payload["error_message"],
            processing_stage="rag_ingestion",
        )
        await message.ack()

    async def _publish_failed_payload(
        self,
        *,
        payload: Dict[str, Any],
        exc: Exception,
        retry_count: int,
        max_retries: int,
        parent_event_id: Any,
        job_id: Any,
        trace_id: Any,
        original_event_type: str,
    ) -> Dict[str, Any]:
        failed_event = PipelineFailedEvent(
            event_id=uuid4(),
            job_id=_safe_uuid(job_id),
            parent_event_id=_safe_uuid(parent_event_id) if parent_event_id else None,
            schema_version=self.settings.schema_version,
            event_type="pipeline.failed",
            failed_stage="rag_ingestion",
            source="hawki-rag",
            error_type=error_name(exc),
            error_message=str(exc),
            retry_count=int(retry_count),
            max_retries=int(max_retries),
            original_event_type=original_event_type,
            original_event_payload=dict(payload),
            failed_at=utc_now(),
            trace_id=str(trace_id) if trace_id else None,
        )
        out = model_to_dict(failed_event)
        await self.rabbit_client.publish_failed_event(out)
        return out

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
        error_message: str | None = None,
    ) -> None:
        status_map = {
            "receive": "started",
            "processing-start": "started",
            "success": "success",
            "skip-duplicate": "skipped",
            "retry-published": "skipped",
            "failed-published": "failed",
        }
        logger.info(
            json.dumps(
                {
                    "event": event,
                    "stage": "ingest",
                    "status": status_map.get(event, event),
                    "job_id": job_id,
                    "doc_id": job_id,
                    "retry_count": int(retry_count),
                    "max_retries": int(max_retries),
                    "queue": queue,
                    "exchange": exchange,
                    "error_type": error_type or "",
                    "error_message": error_message or "",
                    "processing_stage": processing_stage,
                },
                ensure_ascii=False,
                sort_keys=True,
            )
        )


def _configure_logging() -> None:
    level = os.environ.get("LOG_LEVEL", "INFO").upper()
    logging.basicConfig(level=level, format="%(asctime)s %(levelname)s:%(name)s:%(message)s")


async def _run_worker() -> None:
    settings = RabbitMQSettings.from_env()
    worker = RagIngestionWorker(
        settings=settings,
        rabbit_client=RabbitMQClient(settings),
        processor=ConvertedDocumentIngestor(),
        state_store=JobStateStore(settings.job_db_path),
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
