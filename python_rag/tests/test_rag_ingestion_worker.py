import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from uuid import uuid4


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)

from rabbitmq.client import RabbitMQSettings
from workers.failure_classifier import PermanentIngestionError
from workers.job_state_store import STATUS_COMPLETED, STATUS_FAILED, STATUS_RECEIVED, JobStateStore
from workers.rag_ingestion_worker import (
    STAGE_RAG_INGESTION,
    RagIngestionWorker,
    resolve_shared_markdown_path,
)


class FakeMessage:
    def __init__(self, payload: dict):
        self.body = json.dumps(payload).encode("utf-8")
        self.ack_calls = 0

    async def ack(self):
        self.ack_calls += 1


class FakeRabbitClient:
    def __init__(self):
        self.retry_messages = []
        self.failed_messages = []

    async def publish_retry(self, payload):
        self.retry_messages.append(payload)

    async def publish_failed_event(self, payload):
        self.failed_messages.append(payload)

    async def connect(self):
        return None

    async def close(self):
        return None

    async def consume_ingestion_queue(self, callback):
        return "fake-consumer"

    async def cancel_consumer(self, consumer_tag: str):
        return None


class RecordingProcessor:
    def __init__(self, exc: Exception | None = None):
        self.exc = exc
        self.calls = 0
        self.paths = []

    async def process(self, event, *, file_path: Path):
        self.calls += 1
        self.paths.append(str(file_path))
        if self.exc is not None:
            raise self.exc
        return {"ok": True}


def _settings_for(tmp_dir: str, shared_root: str) -> RabbitMQSettings:
    return RabbitMQSettings(
        communication_enabled=True,
        communication_method="rabbitmq",
        rabbitmq_host="rabbitmq",
        rabbitmq_port=5672,
        rabbitmq_user="guest",
        rabbitmq_password="guest",
        rabbitmq_vhost="/",
        rabbitmq_heartbeat=30,
        rabbitmq_connection_timeout=30,
        rabbitmq_url="amqp://guest:guest@rabbitmq:5672/%2F",
        events_exchange="pipeline.events",
        events_exchange_type="direct",
        retry_exchange="pipeline.retry",
        retry_exchange_type="direct",
        failed_exchange="pipeline.failed",
        failed_exchange_type="direct",
        rag_ingestion_queue="rag_ingestion_jobs",
        document_converted_routing_key="convert.document.completed",
        rag_ingestion_retry_queue="rag_ingestion_jobs_retry",
        rag_ingestion_retry_routing_key="convert.document.completed.retry",
        failed_queue="failed_jobs",
        failed_routing_key="pipeline.failed",
        retry_delay_ms=5000,
        prefetch_count=1,
        max_retries=2,
        queue_type="quorum",
        publisher_confirms=True,
        persistent_messages=True,
        job_db_path=str(Path(tmp_dir) / "job_state.sqlite"),
        shared_storage_root=shared_root,
        schema_version="1",
        service_name="hawki-rag",
    )


def _event_payload(converted_path: str, **overrides):
    payload = {
        "event_id": str(uuid4()),
        "job_id": str(uuid4()),
        "parent_event_id": str(uuid4()),
        "schema_version": "1",
        "event_type": "convert.document.completed",
        "source": "file-converter",
        "original_url": "https://example.org/file.pdf",
        "original_path": "/app/shared/source/file.pdf",
        "original_relative_path": "source/file.pdf",
        "converted_path": converted_path,
        "converted_relative_path": "converted/file.md",
        "output_format": "markdown",
        "converter_name": "mineru",
        "converter_version": "1.0.0",
        "input_checksum_sha256": "a" * 64,
        "output_checksum_sha256": "b" * 64,
        "converted_at": "2026-04-27T12:00:00+00:00",
        "trace_id": "trace-1",
        "payload": {"graph": False},
        "retry_count": 0,
        "max_retries": 2,
    }
    payload.update(overrides)
    return payload


class RagIngestionWorkerBehaviorTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.shared_root = Path(self.tmp.name) / "shared"
        self.shared_root.mkdir(parents=True, exist_ok=True)
        self.doc_path = self.shared_root / "converted" / "file.md"
        self.doc_path.parent.mkdir(parents=True, exist_ok=True)
        self.doc_path.write_text("# Title\n\nBody", encoding="utf-8")
        self.settings = _settings_for(self.tmp.name, str(self.shared_root))

    async def asyncTearDown(self):
        self.tmp.cleanup()

    def _make_worker(self, processor: RecordingProcessor) -> RagIngestionWorker:
        store = JobStateStore(self.settings.job_db_path)
        client = FakeRabbitClient()
        return RagIngestionWorker(
            settings=self.settings,
            rabbit_client=client,
            processor=processor,
            state_store=store,
        )

    async def test_success_ack_and_mark_completed(self):
        processor = RecordingProcessor()
        worker = self._make_worker(processor)
        msg = FakeMessage(_event_payload(str(self.doc_path)))

        await worker._handle_message(msg)

        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(processor.calls, 1)
        rec = worker.state_store.get(job_id=json.loads(msg.body)["job_id"], stage=STAGE_RAG_INGESTION)
        self.assertIsNotNone(rec)
        self.assertEqual(rec.status, STATUS_COMPLETED)
        self.assertEqual(worker.rabbit_client.retry_messages, [])
        self.assertEqual(worker.rabbit_client.failed_messages, [])

    async def test_duplicate_event_idempotency_skip(self):
        processor = RecordingProcessor()
        worker = self._make_worker(processor)
        payload = _event_payload(str(self.doc_path))

        await worker._handle_message(FakeMessage(payload))
        await worker._handle_message(FakeMessage(payload))

        rec = worker.state_store.get(job_id=payload["job_id"], stage=STAGE_RAG_INGESTION)
        self.assertIsNotNone(rec)
        self.assertEqual(rec.status, STATUS_COMPLETED)
        self.assertEqual(processor.calls, 1)

    async def test_retry_flow_on_transient_failure(self):
        processor = RecordingProcessor(exc=TimeoutError("temporary qdrant failure"))
        worker = self._make_worker(processor)
        payload = _event_payload(str(self.doc_path), retry_count=0, max_retries=2)
        msg = FakeMessage(payload)

        await worker._handle_message(msg)

        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(len(worker.rabbit_client.retry_messages), 1)
        self.assertEqual(worker.rabbit_client.retry_messages[0]["retry_count"], 1)
        self.assertEqual(worker.rabbit_client.failed_messages, [])
        rec = worker.state_store.get(job_id=payload["job_id"], stage=STAGE_RAG_INGESTION)
        self.assertIsNotNone(rec)
        self.assertEqual(rec.status, STATUS_RECEIVED)
        self.assertEqual(rec.retry_count, 1)

    async def test_failed_flow_on_permanent_failure(self):
        processor = RecordingProcessor(exc=PermanentIngestionError("unsupported output format"))
        worker = self._make_worker(processor)
        payload = _event_payload(str(self.doc_path), retry_count=0, max_retries=2)
        msg = FakeMessage(payload)

        await worker._handle_message(msg)

        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(worker.rabbit_client.retry_messages, [])
        self.assertEqual(len(worker.rabbit_client.failed_messages), 1)
        failed = worker.rabbit_client.failed_messages[0]
        self.assertEqual(failed["event_type"], "pipeline.failed")
        self.assertEqual(failed["failed_stage"], "rag_ingestion")
        rec = worker.state_store.get(job_id=payload["job_id"], stage=STAGE_RAG_INGESTION)
        self.assertIsNotNone(rec)
        self.assertEqual(rec.status, STATUS_FAILED)

    async def test_invalid_schema_routes_to_failed(self):
        processor = RecordingProcessor()
        worker = self._make_worker(processor)
        bad_payload = {"event_type": "convert.document.completed"}  # missing required fields
        msg = FakeMessage(bad_payload)

        await worker._handle_message(msg)

        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(processor.calls, 0)
        self.assertEqual(worker.rabbit_client.retry_messages, [])
        self.assertEqual(len(worker.rabbit_client.failed_messages), 1)
        self.assertEqual(worker.rabbit_client.failed_messages[0]["event_type"], "pipeline.failed")


class PathSafetyTests(unittest.TestCase):
    def test_path_outside_root_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "shared"
            root.mkdir(parents=True, exist_ok=True)
            outside = Path(tmp) / "outside.md"
            outside.write_text("x", encoding="utf-8")
            with self.assertRaises(PermanentIngestionError):
                resolve_shared_markdown_path(str(outside), str(root))


class ManualIngestionCompatibilityTests(unittest.TestCase):
    def test_manual_ingestion_entrypoints_unchanged(self):
        try:
            from app import ingest as ingest_entrypoints
            from pipeline import ingest_logic
        except ModuleNotFoundError as exc:
            self.skipTest(f"manual ingestion import dependencies unavailable: {exc}")

        self.assertIs(ingest_entrypoints.ingest_documents, ingest_logic.ingest_documents)


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
