"""Disposable Temporal cancellation fault test with a real delayed Qdrant write."""

import asyncio
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import threading
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
from unittest.mock import patch
from uuid import uuid4

import pytest
from temporalio import workflow
from temporalio.client import Client, WorkflowFailureError
from temporalio.common import RetryPolicy
from temporalio.worker import Worker, UnsandboxedWorkflowRunner

from hawki_indexer_worker.activities import index as index_activity
from hawki_vector_store.client import QdrantHTTP
from hawki_vector_store.requests import QdrantRequest
from hawki_indexer_worker.indexing.point_identity import deterministic_point_id
from hawki_artifact_store.local import LocalArtifactStore
from hawki_graph_store.graph import Neo4jGraph
from hawki_indexer_worker.adapters.composition import build_ingest_workflow_dependencies
from hawki_indexer_worker.adapters.managed_writer import ManagedWriterRegistration
from hawki_indexer_worker.adapters.neo4j_writer import (
    create_neo4j_writer,
    create_registered_graph_writer,
)
from hawki_indexer_worker.indexing.batch_execution import (
    IndexBatchRequest,
    IndexBatchDependencies,
    execute_index_batches,
)
from hawki_indexer_worker.indexing.managed_deletion import delete_graph
from hawki_indexer_worker.settings import IndexerSettings
from .test_managed_deletion_live import disposable_environment


@workflow.defn
class CancelledManagedWriterWorkflow:
    """Fault harness: the real index activity can outlive workflow cancellation."""

    @workflow.run
    async def run(self, payload: dict) -> None:
        await workflow.execute_activity(
            "ingest_markdown_files",
            payload,
            start_to_close_timeout=timedelta(seconds=30),
            retry_policy=RetryPolicy(maximum_attempts=1),
            cancellation_type=workflow.ActivityCancellationType.TRY_CANCEL,
        )


async def _cancelled_writer_live() -> None:
    token = uuid4().hex
    collection = "test_managed_delete_" + token
    queue = "test-managed-writer-" + token
    source_id = "source-" + token
    doc_id = "doc_" + hashlib.sha256((source_id + "|one.md").encode()).hexdigest()[:40]
    secret = "isolated-writer-test-secret"
    ledger = {}
    started, proceed, wrote = threading.Event(), threading.Event(), threading.Event()

    class Receiver(BaseHTTPRequestHandler):
        def do_POST(self):
            raw = self.rfile.read(int(self.headers["Content-Length"]))
            timestamp = self.headers["X-Hawki-Timestamp"]
            digest = hmac.new(
                secret.encode(), timestamp.encode() + b"." + raw, hashlib.sha256
            ).hexdigest()
            assert self.path == "/api/internal/pipeline/managed-writers"
            assert hmac.compare_digest(
                "v1=" + digest, self.headers["X-Hawki-Signature"]
            )
            event = json.loads(raw)
            key = (
                event["workflow_id"],
                event["run_id"],
                event["activity_id"],
                event["attempt"],
            )
            ledger[key] = "active" if event["action"] == "acquire" else "released"
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(
                json.dumps(
                    {
                        "success": True,
                        "status": ledger[key],
                        "targets": [
                            {
                                "doc_id": target["doc_id"],
                                "source_id": source_id,
                                "dataset_id": "test-" + token,
                                "collection": collection,
                                "neo4j_namespace": None,
                            }
                            for target in event["targets"]
                        ],
                    }
                ).encode()
            )

        def log_message(self, *_args):
            pass

    server = ThreadingHTTPServer(("127.0.0.1", 0), Receiver)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    writer = QdrantHTTP()
    writer.set_collection(collection)
    writer.ensure_collection(2)
    client = await Client.connect(
        os.environ["TEMPORAL_ADDRESS"], namespace=os.environ["TEMPORAL_NAMESPACE"]
    )

    def delayed_sink_write(_input, *, context, dependencies):
        dependencies.target_registrar([{"doc_id": doc_id, "relative_path": "one.md"}])
        started.set()
        if not proceed.wait(timeout=15):
            raise RuntimeError("Fault harness timed out")
        sink = dependencies.workflow_dependencies.vector_writer_factory()
        sink.set_collection(collection)
        sink.upsert_points(
            [
                {
                    "id": deterministic_point_id(doc_id, 0),
                    "vector": [0.1, 0.2],
                    "payload": {
                        "doc_id": doc_id,
                        "source_id": source_id,
                        "dataset_id": "test-" + token,
                    },
                }
            ]
        )
        wrote.set()
        raise RuntimeError("Canceled activity failed after its delayed sink write")

    try:
        callback_env = {
            "HAWKI_RAG_WORKER_CALLBACK_URL": f"http://127.0.0.1:{server.server_port}/api/internal/pipeline/worker-events",
            "HAWKI_RAG_WORKER_CALLBACK_SECRET": secret,
            "HAWKI_RAG_WORKER_CALLBACK_RETRY_ATTEMPTS": "1",
        }
        with (
            patch.dict(os.environ, callback_env),
            patch.object(index_activity, "execute_index_activity", delayed_sink_write),
            patch.object(
                index_activity, "create_graph_extractor", return_value=object()
            ),
            ThreadPoolExecutor(max_workers=2) as executor,
        ):
            async with Worker(
                client,
                task_queue=queue,
                workflows=[CancelledManagedWriterWorkflow],
                workflow_runner=UnsandboxedWorkflowRunner(),
                activities=[index_activity.ingest_markdown_files],
                activity_executor=executor,
            ):
                handle = await client.start_workflow(
                    CancelledManagedWriterWorkflow.run,
                    {
                        "workflow_input": {
                            "source_id": source_id,
                            "job_id": "job-" + token,
                            "managed_document_id": "adoc_" + token,
                            "dataset_id": "test-" + token,
                            "ingestion": {"collection": collection, "graph": False},
                        },
                        "convert_result": {},
                    },
                    id=queue,
                    task_queue=queue,
                )
                assert await asyncio.to_thread(started.wait, 10)
                await handle.cancel()
                with pytest.raises(WorkflowFailureError):
                    await asyncio.wait_for(handle.result(), timeout=10)
                assert (await handle.describe()).status.name == "CANCELED"
                assert list(ledger.values()) == ["active"]
                proceed.set()
                assert await asyncio.to_thread(wrote.wait, 10)
                # A real Qdrant mutation completed after Temporal declared the workflow closed.
                assert writer.count_points_verified({"must": []}) == 1
                assert list(ledger.values()) == ["active"]
                assert next(iter(ledger))[1] == handle.first_execution_run_id
        print(
            "LIVE PASS: delayed Qdrant write after workflow cancellation retains active writer permission; cancellation is not quiescence"
        )
    finally:
        proceed.set()
        server.shutdown()
        server.server_close()
        assert collection == "test_managed_delete_" + token
        writer._gateway.send(
            QdrantRequest(
                "DELETE", f"/collections/{collection}", timeout=30, retryable=False
            )
        ).raise_for_status()


@pytest.mark.integration
def test_cancelled_workflow_does_not_release_a_delayed_sink_writer():
    if os.environ.get("RAWKI_MANAGED_DELETION_LIVE") != "1":
        pytest.skip("Requires explicit disposable live services")
    with patch.dict(os.environ, disposable_environment()):
        asyncio.run(_cancelled_writer_live())


@pytest.mark.integration
def test_registered_retry_cleanup_preserves_shared_and_foreign_graph_contributions():
    if os.environ.get("RAWKI_MANAGED_DELETION_LIVE") != "1":
        pytest.skip("Requires explicit disposable live services")
    with patch.dict(os.environ, disposable_environment()):
        token = uuid4().hex
        dataset, foreign = "test-retry-" + token, "test-foreign-" + token
        namespace = "test_managed_delete_" + token
        graph = Neo4jGraph(
            dataset_id=dataset, neo4j_namespace=namespace, allow_database_fallback=False
        )

        def authorize(doc_id, dataset_id, neo4j_namespace):
            assert (doc_id, dataset_id, neo4j_namespace) == (
                "owned",
                dataset,
                namespace,
            )

        uncertain = []
        writer = create_registered_graph_writer(
            lambda: uncertain.append(True),
            dataset_id=dataset,
            neo4j_namespace=namespace,
            authorize=authorize,
        )
        try:
            for owner, scope in [
                ("owned", dataset),
                ("other", dataset),
                ("owned", foreign),
            ]:
                graph.upsert_triplets(
                    [("A", "KNOWS", "B")],
                    doc_id=owner,
                    dataset_id=scope,
                    neo4j_namespace=namespace,
                    request_id=None,
                )
            with graph._session() as session:
                session.run(
                    "CREATE (:Entity {name:'isolated', dataset_id:$dataset, neo4j_namespace:$namespace, doc_ids:['other']}) "
                    "CREATE (a:Entity {name:'legacy-a', dataset_id:$dataset, neo4j_namespace:$namespace, doc_ids:['legacy']}) "
                    "CREATE (b:Entity {name:'legacy-b', dataset_id:$dataset, neo4j_namespace:$namespace, doc_ids:['legacy']}) "
                    "CREATE (a)-[:REL {dataset_id:$dataset, neo4j_namespace:$namespace, doc_id:'legacy'}]->(b)",
                    dataset=dataset,
                    namespace=namespace,
                ).consume()
            proof = writer.delete_by_doc_id("owned", request_id="registered-retry")
            with graph._session() as session:
                rows = list(
                    session.run(
                        "MATCH ()-[r:REL]->() WHERE r.neo4j_namespace=$namespace "
                        "RETURN r.dataset_id AS dataset, r.doc_ids AS docs, r.doc_id AS doc",
                        namespace=namespace,
                    )
                )
                assert len(rows) == 3
                assert any(
                    row["dataset"] == dataset and row["docs"] == ["other"]
                    for row in rows
                )
                assert any(
                    row["dataset"] == foreign and row["docs"] == ["owned"]
                    for row in rows
                )
                assert any(
                    row["dataset"] == dataset and row["doc"] == "legacy" for row in rows
                )
                assert session.run(
                    "MATCH (n:Entity {name:'isolated', dataset_id:$dataset, neo4j_namespace:$namespace}) RETURN n.doc_ids AS docs",
                    dataset=dataset,
                    namespace=namespace,
                ).single()["docs"] == ["other"]
            assert proof["verified"] is True and proof["remaining_contributions"] == 0
            assert uncertain == []
        finally:
            writer.close()
            with graph._session() as session:
                session.run(
                    "MATCH (n:Entity) WHERE n.dataset_id IN $datasets AND n.neo4j_namespace=$namespace DETACH DELETE n",
                    datasets=[dataset, foreign],
                    namespace=namespace,
                ).consume()
            graph.close()


@pytest.mark.integration
def test_vector_only_passthrough_grant_covers_real_canonical_graph_cleanup():
    """Real batch preparation, signed grant and Neo4j write/delete; no model calls."""
    if os.environ.get("RAWKI_MANAGED_DELETION_LIVE") != "1":
        pytest.skip("Requires explicit disposable live services")
    from hawki_indexer_worker.indexing import artifact_documents

    with (
        patch.dict(os.environ, disposable_environment()),
        TemporaryDirectory() as directory,
    ):
        token = uuid4().hex
        dataset, namespace = "test-effective-" + token, "test_managed_delete_" + token
        source_id, collection = "source-" + token, "test_managed_delete_" + token
        targets = []
        secret = "isolated-effective-graph-secret"

        class Receiver(BaseHTTPRequestHandler):
            def do_POST(self):
                raw = self.rfile.read(int(self.headers["Content-Length"]))
                digest = hmac.new(
                    secret.encode(),
                    self.headers["X-Hawki-Timestamp"].encode() + b"." + raw,
                    hashlib.sha256,
                ).hexdigest()
                assert self.path == "/api/internal/pipeline/managed-writers"
                assert hmac.compare_digest(
                    "v1=" + digest, self.headers["X-Hawki-Signature"]
                )
                event = json.loads(raw)
                grants = []
                for target in event["targets"]:
                    assert target["graph"] is True
                    grant = {
                        "doc_id": target["doc_id"],
                        "dataset_id": dataset,
                        "source_id": source_id,
                        "collection": collection,
                        "neo4j_namespace": namespace,
                    }
                    targets.append(grant)
                    grants.append(grant)
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(
                    json.dumps(
                        {
                            "success": True,
                            "status": "released"
                            if event["action"] == "release"
                            else "active",
                            "targets": grants,
                        }
                    ).encode()
                )

            def log_message(self, *_args):
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), Receiver)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        graph = Neo4jGraph(
            dataset_id=dataset, neo4j_namespace=namespace, allow_database_fallback=False
        )
        try:
            callback_env = {
                "HAWKI_RAG_WORKER_CALLBACK_URL": f"http://127.0.0.1:{server.server_port}/api/internal/pipeline/worker-events",
                "HAWKI_RAG_WORKER_CALLBACK_SECRET": secret,
            }
            with (
                patch.dict(os.environ, callback_env),
                patch.object(
                    artifact_documents,
                    "load_passthrough_metadata",
                    return_value={"converter_fallback": "raganything_passthrough"},
                ),
            ):
                workflow_input = {
                    "source_id": source_id,
                    "dataset_id": dataset,
                    "job_id": "job-" + token,
                    "managed_document_id": "adoc_" + token,
                    "ingestion": {
                        "graph": False,
                        "collection": collection,
                        "neo4j_namespace": namespace,
                    },
                }
                info = SimpleNamespace(
                    workflow_id="test-" + token,
                    workflow_run_id="run-" + token,
                    activity_id="1",
                    attempt=1,
                )
                registration = ManagedWriterRegistration.acquire_for(
                    IndexerSettings.from_env(), workflow_input, info
                )
                markdown = Path(directory) / "one.md"
                markdown.write_text("# Passthrough")

                def canonical_index(request, *, dependencies, **_kwargs):
                    assert request.graph is True and len(targets) == 1
                    sink = dependencies.graph_writer_factory(
                        dataset_id=dataset, neo4j_namespace=namespace
                    )
                    try:
                        sink.upsert_triplets(
                            [("Alice", "KNOWS", "Bob")],
                            doc_id=request.docs[0].id,
                            request_id="test-" + token,
                            dataset_id=dataset,
                            neo4j_namespace=namespace,
                        )
                    finally:
                        sink.close()
                    return {"summary": {"documents": {"processed_docs": 1}}}

                execute_index_batches(
                    IndexBatchRequest(
                        files=[str(markdown)],
                        workflow_input=workflow_input,
                        markdown_dir=directory,
                        manifest_path="",
                        artifacts_by_path={},
                    ),
                    IndexBatchDependencies(
                        artifact_store=LocalArtifactStore(directory),
                        graph_service=object(),
                        provider_resolver=lambda _name: object(),
                        workflow_dependencies=registration.bind_dependencies(
                            build_ingest_workflow_dependencies()
                        ),
                        ingest_documents=canonical_index,
                        target_registrar=registration.register_targets,
                    ),
                )
                registration.release()
                doc_id = targets[0]["doc_id"]
                graph.upsert_triplets(
                    [("Alice", "KNOWS", "Bob")], doc_id="other-owned-test-doc"
                )
                with graph._session() as session:
                    assert (
                        session.run(
                            "MATCH ()-[r:REL]->() WHERE r.dataset_id=$dataset AND r.neo4j_namespace=$namespace AND $doc IN r.doc_ids RETURN count(r) AS c",
                            dataset=dataset,
                            namespace=namespace,
                            doc=doc_id,
                        ).single()["c"]
                        > 0
                    )
                proof = delete_graph(
                    {
                        "operation_id": "delete_"
                        + hashlib.sha256(token.encode()).hexdigest(),
                        "dataset_id": dataset,
                        "target": {
                            "output_id": 1,
                            "source_id": source_id,
                            "doc_id": doc_id,
                            "collection": collection,
                            "neo4j_namespace": targets[0]["neo4j_namespace"],
                        },
                    },
                    create_neo4j_writer,
                )
                assert (
                    proof["required"] is True and proof["remaining_contributions"] == 0
                )
                with graph._session() as session:
                    rows = list(
                        session.run(
                            "MATCH ()-[r:REL]->() WHERE r.dataset_id=$dataset AND r.neo4j_namespace=$namespace RETURN r.doc_ids AS docs",
                            dataset=dataset,
                            namespace=namespace,
                        )
                    )
                    assert rows and all(doc_id not in row["docs"] for row in rows)
                    assert any("other-owned-test-doc" in row["docs"] for row in rows)
        finally:
            server.shutdown()
            server.server_close()
            with graph._session() as session:
                session.run(
                    "MATCH (n:Entity) WHERE n.dataset_id=$dataset AND n.neo4j_namespace=$namespace DETACH DELETE n",
                    dataset=dataset,
                    namespace=namespace,
                ).consume()
            graph.close()
