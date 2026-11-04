"""Opt-in real Temporal cancellation + guarded Qdrant write + Python fence.

Only the test-only loopback containers on ports 17233/16333 are accepted.
Run in an environment containing both backend and indexer test dependencies.
"""

import asyncio
from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
from datetime import timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import os
import threading
from uuid import uuid4

import httpx
import pytest
from temporalio import workflow
from temporalio.client import Client, WorkflowFailureError
from temporalio.common import RetryPolicy
from temporalio.worker import Worker, UnsandboxedWorkflowRunner

from hawki_backend.main import create_app
from hawki_backend.query.settings import QuerySettings
from hawki_backend.text_ingestion.identity import document_id
from hawki_backend.text_ingestion.service import DeletionFailed
from hawki_backend.text_ingestion.settings import TextIngestionSettings
from hawki_backend.text_ingestion.validation import TextIngestionRequest
from hawki_backend.text_ingestion.vectors import TextVectorDeletion
from hawki_workflow_control.temporal_client import TemporalExecution
from .conftest import payload


@workflow.defn
class CancelledTextWriterProof:
    @workflow.run
    async def run(self, data: dict):
        await workflow.execute_activity(
            "ingest_markdown_files",
            data,
            start_to_close_timeout=timedelta(seconds=40),
            retry_policy=RetryPolicy(maximum_attempts=1),
            cancellation_type=workflow.ActivityCancellationType.TRY_CANCEL,
        )


@pytest.mark.integration
def test_real_cancelled_activity_cannot_outlive_verified_deletion(env, monkeypatch):
    if os.environ.get("HAWKI_EPOCH9_LIVE") != "1":
        pytest.skip("Requires the disposable epoch9 Temporal/Qdrant containers")
    from hawki_indexer_worker.activities import index
    from hawki_indexer_worker.indexing.point_identity import deterministic_point_id
    from hawki_rag_contracts.pipeline.ingestion import IndexResult
    from temporalio import activity

    token = uuid4().hex
    collection, queue = "test_epoch9_" + token, "test_epoch9_" + token
    origin = "http://127.0.0.1:16333"
    scope = env.service._query_settings.datasets["dataset-a"]
    env.service._query_settings = QuerySettings(
        {"dataset-a": replace(scope, qdrant_collection=collection)},
        env.service._query_settings.models,
    )
    cleanup_calls = []
    cleanup_client = httpx.Client(
        event_hooks={
            "request": [lambda request: cleanup_calls.append(request.url.path)]
        }
    )
    env.service._vectors = TextVectorDeletion(
        TextIngestionSettings(qdrant_url=origin), cleanup_client
    )
    app = create_app(
        authenticator=env.authenticator,
        text_ingestion_service=env.service,
        text_ingestion_secret="event-test-secret",
    )
    entered, proceed, released = threading.Event(), threading.Event(), threading.Event()
    trace = []
    sinks = []

    class Receiver(BaseHTTPRequestHandler):
        def do_POST(self):
            raw = self.rfile.read(int(self.headers["Content-Length"]))

            async def relay():
                async with httpx.AsyncClient(
                    transport=httpx.ASGITransport(app=app), base_url="http://test"
                ) as client:
                    return await client.post(
                        "/internal/text-ingestion/writers",
                        content=raw,
                        headers={
                            "X-Hawki-Timestamp": self.headers["X-Hawki-Timestamp"],
                            "X-Hawki-Signature": self.headers["X-Hawki-Signature"],
                        },
                    )

            response = asyncio.run(relay())
            self.send_response(response.status_code)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(response.content)
            if b'"status":"released"' in response.content:
                released.set()

        def log_message(self, *args):
            pass

    server = ThreadingHTTPServer(("127.0.0.1", 0), Receiver)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    monkeypatch.setenv(
        "HAWKI_RAG_WORKER_CALLBACK_URL",
        f"http://127.0.0.1:{server.server_port}/api/internal/pipeline/worker-events",
    )
    monkeypatch.setenv("HAWKI_RAG_WORKER_CALLBACK_SECRET", "event-test-secret")
    monkeypatch.setenv("HAWKI_RAG_WORKER_CALLBACK_RETRY_ATTEMPTS", "1")
    for name, value in {
        "QDRANT_SCHEME": "http",
        "QDRANT_HOST": "127.0.0.1",
        "QDRANT_PORT": "16333",
        "QDRANT_API_KEY": "",
    }.items():
        monkeypatch.setenv(name, value)
    httpx.put(
        origin + "/collections/" + collection,
        json={"vectors": {"size": 2, "distance": "Cosine"}},
    ).raise_for_status()

    def execute(data, *, context, dependencies):
        raw = data.workflow_input
        doc = document_id(raw["source_id"])
        dependencies.target_registrar(
            [{"doc_id": doc, "relative_path": "document.md", "graph": False}]
        )
        sink = dependencies.workflow_dependencies.vector_writer_factory()
        sinks.append(sink)
        send_upsert = sink._gateway.upsert

        def delayed(*args, **kwargs):
            entered.set()
            if not proceed.wait(30):
                raise RuntimeError("fault harness timed out")
            result = send_upsert(*args, **kwargs)
            trace.append("admitted sink write acknowledged after cancellation")
            return result

        sink._gateway.upsert = delayed
        with activity.shield_thread_cancel_exception():
            sink.upsert_points(
                [
                    {
                        "id": deterministic_point_id(doc, 0),
                        "vector": [0.1, 0.2],
                        "payload": {
                            "doc_id": doc,
                            "source_id": raw["source_id"],
                            "dataset_id": raw["dataset_id"],
                        },
                    }
                ]
            )
        return IndexResult(source_id=raw["source_id"], status="success")

    monkeypatch.setattr(index, "execute_index_activity", execute)
    monkeypatch.setattr(
        index, "create_graph_extractor", lambda *args, **kwargs: object()
    )

    async def run():
        client = await Client.connect("127.0.0.1:17233")
        with ThreadPoolExecutor(2) as executor:
            async with Worker(
                client,
                task_queue=queue,
                workflows=[CancelledTextWriterProof],
                activities=[index.ingest_markdown_files],
                workflow_runner=UnsandboxedWorkflowRunner(),
                activity_executor=executor,
            ):
                handles = []

                async def start(**kwargs):
                    handle = await client.start_workflow(
                        CancelledTextWriterProof.run,
                        {
                            "workflow_input": kwargs["workflow_input"],
                            "convert_result": {},
                        },
                        id=kwargs["workflow_id"],
                        task_queue=queue,
                    )
                    handles.append(handle)
                    return TemporalExecution(
                        kwargs["workflow_id"], handle.first_execution_run_id
                    )

                async def cancel(**kwargs):
                    handle = handles[0]
                    await handle.cancel()
                    try:
                        await asyncio.wait_for(handle.result(), 10)
                    except WorkflowFailureError:
                        pass
                    assert (await handle.describe()).status.name == "CANCELED"
                    trace.append("real Temporal workflow canceled")

                env.port.start_text_ingest_workflow.side_effect = start
                env.port.cancel_workflow_and_wait.side_effect = cancel
                first = await env.service.ingest(
                    env.principal,
                    TextIngestionRequest.model_validate(payload()),
                    "key-1",
                )
                assert await asyncio.to_thread(entered.wait, 10)
                with pytest.raises(DeletionFailed):
                    await env.service.delete(env.principal, first.source_id, "delete-1")
                assert (
                    env.repository.find("default", "key-1")["lifecycle_status"]
                    == "delete_failed"
                )
                assert not released.is_set()
                assert cleanup_calls == []
                proceed.set()
                assert await asyncio.to_thread(released.wait, 10)
                assert (
                    httpx.post(
                        origin + "/collections/" + collection + "/points/count",
                        json={"exact": True},
                    ).json()["result"]["count"]
                    == 1
                )
                await env.service.delete(env.principal, first.source_id, "delete-1")
                assert cleanup_calls == [
                    "/collections/" + collection + "/points/delete",
                    "/collections/" + collection + "/points/count",
                ]
                with pytest.raises(RuntimeError, match="closed"):
                    sinks[0].upsert_points(
                        [
                            {
                                "id": deterministic_point_id(
                                    document_id(first.source_id), 0
                                ),
                                "vector": [0.1, 0.2],
                                "payload": {
                                    "doc_id": document_id(first.source_id),
                                    "source_id": first.source_id,
                                    "dataset_id": "dataset-a",
                                },
                            }
                        ]
                    )
                assert (
                    httpx.post(
                        origin + "/collections/" + collection + "/points/count",
                        json={"exact": True},
                    ).json()["result"]["count"]
                    == 0
                )
                assert (
                    env.repository.find("default", "key-1")["lifecycle_status"]
                    == "deleted"
                )
                trace.append("writer release then verified zero then deleted")
                assert trace[0] == "real Temporal workflow canceled"
                print("LIVE PASS:", trace)

    try:
        asyncio.run(run())
    finally:
        proceed.set()
        server.shutdown()
        server.server_close()
        cleanup_client.close()
        httpx.delete(origin + "/collections/" + collection).raise_for_status()
