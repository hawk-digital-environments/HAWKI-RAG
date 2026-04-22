import json
import os
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)

from rabbit_worker.bootstrap import RabbitWorker
from rabbit_worker.config import WorkerSettings
from rabbit_worker.failure_classifier import (
    FAILURE_PERMANENT,
    FAILURE_TRANSIENT,
    PermanentJobError,
    classify_failure,
)
from rabbit_worker.job_store import JobStore, STATUS_COMPLETED, STATUS_FAILED, STATUS_RETRY_QUEUED


class FakeMessage:
    def __init__(self, payload: dict):
        self.body = json.dumps(payload).encode("utf-8")
        self.ack_calls = 0

    async def ack(self):
        self.ack_calls += 1


class FakeExchange:
    def __init__(self):
        self.published = []

    async def publish(self, message, routing_key: str):
        self.published.append(
            {
                "routing_key": routing_key,
                "payload": json.loads(message.body.decode("utf-8")),
            }
        )


class RecordingProcessor:
    def __init__(self, exc: Exception | None = None):
        self.exc = exc
        self.calls = 0

    async def process(self, job):
        self.calls += 1
        if self.exc is not None:
            raise self.exc
        return {"ok": True}


def _base_payload(**overrides):
    payload = {
        "job_id": "job-1",
        "retry_count": 0,
        "max_retries": 2,
        "docs": [
            {"id": "doc-1", "text": "hello world", "payload": {}},
        ],
        "graph": False,
    }
    payload.update(overrides)
    return payload


def _settings_for_db(path: str) -> WorkerSettings:
    return WorkerSettings(
        rabbitmq_url="amqp://guest:guest@localhost/",
        exchange="ex.main",
        retry_exchange="ex.retry",
        failed_exchange="ex.failed",
        main_queue="q.main",
        retry_queue="q.retry",
        failed_queue="q.failed",
        main_routing_key="rk.main",
        retry_routing_key="rk.retry",
        failed_routing_key="rk.failed",
        queue_type="classic",
        retry_queue_type="classic",
        retry_delay_ms=1000,
        prefetch_count=1,
        max_retries=2,
        shutdown_grace_seconds=1,
        idempotency_db_path=path,
    )


class RabbitWorkerBehaviorTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.db_path = str(Path(self.tmp.name) / "jobs.sqlite")
        self.settings = _settings_for_db(self.db_path)

    async def asyncTearDown(self):
        self.tmp.cleanup()

    def _make_worker(self, processor: RecordingProcessor) -> RabbitWorker:
        store = JobStore(self.db_path)
        worker = RabbitWorker(settings=self.settings, processor=processor, job_store=store)
        worker.retry_exchange = FakeExchange()
        worker.failed_exchange = FakeExchange()
        return worker

    async def test_success_ack_and_mark_completed(self):
        processor = RecordingProcessor()
        worker = self._make_worker(processor)
        msg = FakeMessage(_base_payload())

        await worker._handle_message(msg)

        rec = worker.job_store.get("job-1")
        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(processor.calls, 1)
        self.assertIsNotNone(rec)
        self.assertEqual(rec.status, STATUS_COMPLETED)
        self.assertEqual(worker.retry_exchange.published, [])
        self.assertEqual(worker.failed_exchange.published, [])

    async def test_duplicate_delivery_skip(self):
        processor = RecordingProcessor()
        worker = self._make_worker(processor)

        await worker._handle_message(FakeMessage(_base_payload()))
        await worker._handle_message(FakeMessage(_base_payload()))

        rec = worker.job_store.get("job-1")
        self.assertEqual(processor.calls, 1)
        self.assertIsNotNone(rec)
        self.assertEqual(rec.status, STATUS_COMPLETED)

    async def test_retry_republish_on_transient_failure(self):
        processor = RecordingProcessor(exc=TimeoutError("timeout"))
        worker = self._make_worker(processor)
        msg = FakeMessage(_base_payload(retry_count=0, max_retries=2))

        await worker._handle_message(msg)

        rec = worker.job_store.get("job-1")
        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(rec.status, STATUS_RETRY_QUEUED)
        self.assertEqual(rec.retry_count, 1)
        self.assertEqual(len(worker.retry_exchange.published), 1)
        self.assertEqual(worker.retry_exchange.published[0]["routing_key"], "rk.retry")
        self.assertEqual(worker.retry_exchange.published[0]["payload"]["retry_count"], 1)
        self.assertEqual(worker.failed_exchange.published, [])

    async def test_failed_queue_routing_after_max_retries(self):
        processor = RecordingProcessor(exc=TimeoutError("timeout"))
        worker = self._make_worker(processor)
        msg = FakeMessage(_base_payload(retry_count=2, max_retries=2))

        await worker._handle_message(msg)

        rec = worker.job_store.get("job-1")
        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(rec.status, STATUS_FAILED)
        self.assertEqual(rec.retry_count, 3)
        self.assertEqual(len(worker.failed_exchange.published), 1)
        self.assertEqual(worker.failed_exchange.published[0]["routing_key"], "rk.failed")
        self.assertEqual(worker.failed_exchange.published[0]["payload"]["retry_count"], 3)
        self.assertEqual(worker.retry_exchange.published, [])

    async def test_permanent_failure_routes_directly_to_failed_queue(self):
        processor = RecordingProcessor(exc=PermanentJobError("unsupported input"))
        worker = self._make_worker(processor)
        msg = FakeMessage(_base_payload(retry_count=0, max_retries=2))

        await worker._handle_message(msg)

        rec = worker.job_store.get("job-1")
        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(rec.status, STATUS_FAILED)
        self.assertEqual(len(worker.failed_exchange.published), 1)
        self.assertEqual(worker.retry_exchange.published, [])

    async def test_invalid_schema_routes_to_failed_queue(self):
        processor = RecordingProcessor()
        worker = self._make_worker(processor)
        msg = FakeMessage({"retry_count": 0, "docs": []})  # missing job_id

        await worker._handle_message(msg)

        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(processor.calls, 0)
        self.assertEqual(len(worker.failed_exchange.published), 1)
        self.assertEqual(worker.retry_exchange.published, [])

    async def test_idempotent_downstream_write_skip_for_completed_job(self):
        processor = RecordingProcessor()
        worker = self._make_worker(processor)
        worker.job_store.setup()
        worker.job_store.mark_completed(job_id="job-1", retry_count=0, max_retries=2)

        msg = FakeMessage(_base_payload())
        await worker._handle_message(msg)

        self.assertEqual(msg.ack_calls, 1)
        self.assertEqual(processor.calls, 0)
        self.assertEqual(worker.retry_exchange.published, [])
        self.assertEqual(worker.failed_exchange.published, [])


class FailureClassifierTests(unittest.TestCase):
    def test_permanent_and_transient_classification(self):
        self.assertEqual(classify_failure(PermanentJobError("unsupported input")), FAILURE_PERMANENT)
        self.assertEqual(classify_failure(TimeoutError("timeout")), FAILURE_TRANSIENT)
        self.assertEqual(classify_failure(ValueError("unsupported input format")), FAILURE_PERMANENT)


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
