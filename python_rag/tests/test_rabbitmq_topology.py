import os
import sys
import unittest
from types import SimpleNamespace


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)

from rabbitmq.topology import declare_topology


class FakeExchange:
    def __init__(self, name: str):
        self.name = name


class FakeQueue:
    def __init__(self, name: str):
        self.name = name
        self.bindings = []

    async def bind(self, exchange, routing_key: str):
        self.bindings.append((exchange.name, routing_key))


class FakeChannel:
    def __init__(self):
        self.exchanges = []
        self.queues = []

    async def declare_exchange(self, name, exchange_type, durable=True):
        self.exchanges.append((name, str(exchange_type), durable))
        return FakeExchange(name)

    async def declare_queue(self, name, durable=True, arguments=None):
        self.queues.append((name, durable, arguments or {}))
        return FakeQueue(name)


class RabbitMQTopologyTests(unittest.IsolatedAsyncioTestCase):
    async def test_topology_declaration_and_retry_dlx(self):
        settings = SimpleNamespace(
            events_exchange="pipeline.events",
            events_exchange_type="direct",
            retry_exchange="pipeline.retry",
            retry_exchange_type="direct",
            failed_exchange="pipeline.failed",
            failed_exchange_type="direct",
            rag_ingestion_queue="rag_ingestion_jobs",
            rag_ingestion_retry_queue="rag_ingestion_jobs_retry",
            failed_queue="failed_jobs",
            document_converted_routing_key="convert.document.completed",
            rag_ingestion_retry_routing_key="convert.document.completed.retry",
            failed_routing_key="pipeline.failed",
            retry_delay_ms=5000,
            queue_type="quorum",
        )
        channel = FakeChannel()

        topology = await declare_topology(channel, settings)

        self.assertEqual(topology["events_exchange"].name, "pipeline.events")
        self.assertEqual(topology["retry_exchange"].name, "pipeline.retry")
        self.assertEqual(topology["failed_exchange"].name, "pipeline.failed")

        queue_args = {name: args for name, _durable, args in channel.queues}
        self.assertEqual(queue_args["rag_ingestion_jobs"].get("x-queue-type"), "quorum")
        self.assertEqual(queue_args["failed_jobs"].get("x-queue-type"), "quorum")
        self.assertEqual(queue_args["rag_ingestion_jobs_retry"].get("x-message-ttl"), 5000)
        self.assertEqual(queue_args["rag_ingestion_jobs_retry"].get("x-dead-letter-exchange"), "pipeline.events")
        self.assertEqual(
            queue_args["rag_ingestion_jobs_retry"].get("x-dead-letter-routing-key"),
            "convert.document.completed",
        )


if __name__ == "__main__":  # pragma: no cover
    unittest.main()

