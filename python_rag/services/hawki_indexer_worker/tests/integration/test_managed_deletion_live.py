"""Opt-in live Qdrant, Neo4j and Temporal deletion proof on unique test scopes.

Run with RAWKI_MANAGED_DELETION_LIVE=1 in an environment that can reach the
configured sinks and Temporal. No collection or namespace outside this test's
UUID prefix is written or removed. Can run directly when pytest is unavailable.
"""

from __future__ import annotations

import asyncio
from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import threading
from uuid import uuid4

from temporalio import activity
from temporalio.client import Client
from temporalio.exceptions import ApplicationError
from temporalio.worker import Replayer, Worker

from hawki_bridge.adapters.temporal_client import TemporalBridgeClient
from hawki_bridge.settings import load_settings
from hawki_graph_store.graph import Neo4jGraph
from hawki_indexer_worker.activities.deletion import (
    delete_managed_graph, delete_managed_vectors, report_managed_deletion,
    verify_managed_deletion_writers,
)
from hawki_indexer_worker.adapters.neo4j_writer import create_neo4j_writer
from hawki_indexer_worker.indexing.managed_deletion import delete_graph, delete_vectors
from hawki_rag_contracts.pipeline.deletion import (
    DELETE_MANAGED_GRAPH_ACTIVITY, DELETE_MANAGED_VECTORS_ACTIVITY,
)
from hawki_vector_store.client import QdrantHTTP
from hawki_workflow_worker.workflows.delete_managed_document import DeleteManagedDocumentWorkflow


async def run_live() -> None:
    token = uuid4().hex
    collection = "test_managed_delete_" + token
    namespace = "test_managed_delete_" + token
    dataset_a, dataset_b = "test-a-" + token, "test-b-" + token
    queue = "test-managed-delete-" + token
    writer = QdrantHTTP()
    writer.set_collection(collection)
    graph_a = Neo4jGraph(dataset_id=dataset_a, neo4j_namespace=namespace)
    graph_b = Neo4jGraph(dataset_id=dataset_b, neo4j_namespace=namespace)
    graph_other = Neo4jGraph(dataset_id=dataset_a, neo4j_namespace=namespace + "-other")
    receipts = []
    attempts = {"vectors": 0, "graph": 0, "callback": 0}
    secret = "isolated-live-test-secret"

    class Receiver(BaseHTTPRequestHandler):
        def do_POST(self):
            raw = self.rfile.read(int(self.headers["Content-Length"]))
            timestamp = self.headers["X-Hawki-Timestamp"]
            signature = "v1=" + hmac.new(secret.encode(), timestamp.encode() + b"." + raw, hashlib.sha256).hexdigest()
            assert hmac.compare_digest(signature, self.headers["X-Hawki-Signature"])
            assert self.path == "/api/internal/pipeline/managed-deletion-events"
            attempts["callback"] += 1
            if attempts["callback"] == 1:  # Lose the first callback acknowledgement.
                self.send_response(503)
                self.end_headers()
                return
            receipts.append(json.loads(raw))
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(b'{"success":true}')

        def log_message(self, *_args):
            pass

    server = ThreadingHTTPServer(("127.0.0.1", 0), Receiver)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    os.environ["HAWKI_RAG_WORKER_CALLBACK_URL"] = f"http://127.0.0.1:{server.server_port}/api/internal/pipeline/worker-events"
    os.environ["HAWKI_RAG_WORKER_CALLBACK_SECRET"] = secret
    os.environ["HAWKI_RAG_WORKER_CALLBACK_RETRY_ATTEMPTS"] = "1"
    settings = replace(load_settings(), workflow_task_queue=queue, control_plane_secret=secret)
    client = await Client.connect(settings.temporal_address, namespace=settings.temporal_namespace)
    bridge = TemporalBridgeClient(settings)
    task_input = {
        "operation_id": "delete_" + hashlib.sha256(token.encode()).hexdigest(),
        "managed_document_id": "adoc_live_" + token, "dataset_id": dataset_a,
        "targets": [{"output_id": 1, "doc_id": "same-doc", "source_id": "source-a", "collection": collection, "neo4j_namespace": namespace}],
        "writers": [], "task_queues": {"indexer": queue + "-indexer"},
    }
    scoped = {"operation_id": task_input["operation_id"], "dataset_id": dataset_a, "target": task_input["targets"][0]}
    workflow_id = "managed-" + task_input["operation_id"]
    vector_done = asyncio.Event()

    @activity.defn(name=DELETE_MANAGED_VECTORS_ACTIVITY)
    async def vectors(payload):
        attempts["vectors"] += 1
        return await asyncio.to_thread(delete_managed_vectors, payload)

    @activity.defn(name=DELETE_MANAGED_GRAPH_ACTIVITY)
    async def graph(payload):
        attempts["graph"] += 1
        result = await asyncio.to_thread(delete_managed_graph, payload)
        if attempts["graph"] == 1:
            # Transaction committed, transport acknowledgement lost; Temporal repeats cleanup.
            raise RuntimeError("Injected lost graph acknowledgement after commit")
        return result

    @activity.defn(name=DELETE_MANAGED_VECTORS_ACTIVITY)
    async def first_vectors(payload):
        result = await asyncio.to_thread(delete_managed_vectors, payload)
        attempts["vectors"] += 1
        vector_done.set()
        return result

    @activity.defn(name=DELETE_MANAGED_GRAPH_ACTIVITY)
    async def first_graph(_payload):
        # Drain this worker after an acknowledged vector activity, before graph mutation.
        await asyncio.Event().wait()

    try:
        writer.ensure_collection(2)
        points = [
            {"id": index, "vector": [0.1, 0.2], "payload": {"doc_id": doc, "source_id": source, "dataset_id": dataset}}
            for index, (doc, source, dataset) in enumerate([
                ("same-doc", "source-a", dataset_a),
                ("same-doc", "source-b", dataset_a),
                ("same-doc", "source-a", dataset_b),
                ("other-doc", "source-a", dataset_a),
            ], 1)
        ]
        writer.upsert_points(points)
        triplet = ("Alice", "KNOWS", "Bob")
        graph_a.upsert_triplets([triplet], doc_id="same-doc")
        graph_a.upsert_triplets([triplet], doc_id="other-doc")
        graph_a.upsert_triplets([("Alice", "OWNS", "OnlyTarget")], doc_id="same-doc")
        graph_b.upsert_triplets([triplet], doc_id="same-doc")
        graph_other.upsert_triplets([triplet], doc_id="same-doc")
        # Keep a legacy orphan unrelated to the target; scoped cleanup must not delete it.
        with graph_a._session() as session:
            session.run("MATCH (a:Entity {dataset_id:$dataset, neo4j_namespace:$namespace, name:'Alice'}), (b:Entity {dataset_id:$dataset, neo4j_namespace:$namespace, name:'Bob'}) CREATE (a)-[:REL {dataset_id:$dataset, neo4j_namespace:$namespace, relation:'UNRELATED_ORPHAN'}]->(b)", dataset=dataset_a, namespace=namespace).consume()

        with ThreadPoolExecutor(max_workers=4) as executor:
            async with Worker(client, task_queue=queue, workflows=[DeleteManagedDocumentWorkflow]):
                # Start receipt is intentionally ignored, then the same ID is redispatched.
                async with Worker(client, task_queue=queue + "-indexer", activities=[verify_managed_deletion_writers, first_vectors, first_graph, report_managed_deletion], activity_executor=executor, graceful_shutdown_timeout=__import__('datetime').timedelta(seconds=1)):
                    first = await bridge.start_managed_deletion(workflow_id=workflow_id, workflow_input=task_input)
                    await asyncio.wait_for(vector_done.wait(), timeout=30)
                    assert first["status"] == "pending"
                # Fresh worker resumes recorded history and repeats an interrupted graph activity.
                async with Worker(client, task_queue=queue + "-indexer", activities=[verify_managed_deletion_writers, vectors, graph, report_managed_deletion], activity_executor=executor):
                    repeated = await bridge.start_managed_deletion(workflow_id=workflow_id, workflow_input=task_input)
                    assert repeated["run_id"] == first["run_id"]
                    handle = client.get_workflow_handle(workflow_id, run_id=first["run_id"])
                    result = await asyncio.wait_for(handle.result(), timeout=90)
                    assert result["status"] == "completed"
                    after = await bridge.start_managed_deletion(workflow_id=workflow_id, workflow_input=task_input)
                    assert after["status"] == "completed" and after["run_id"] == first["run_id"]
                    history = await handle.fetch_history()
                    await Replayer(workflows=[DeleteManagedDocumentWorkflow]).replay_workflow(history)
        assert attempts["vectors"] == 1, attempts  # Recorded success survives worker restart.
        assert attempts["graph"] == 2, attempts
        assert attempts["callback"] == 2, attempts
        assert len(receipts) == 1 and receipts[0]["status"] == "completed"
        assert writer.count_points_verified({"must": []}) == 3
        assert delete_vectors(scoped, writer)["deleted_points"] == 0
        assert delete_graph(scoped, create_neo4j_writer)["remaining_contributions"] == 0
        with graph_a._session() as session:
            rows = list(session.run("MATCH ()-[r:REL]->() WHERE r.neo4j_namespace STARTS WITH $namespace RETURN r.dataset_id AS dataset, r.neo4j_namespace AS namespace, r.doc_ids AS docs, r.doc_id AS doc, r.relation AS relation", namespace=namespace))
            assert any(row["dataset"] == dataset_a and row["namespace"] == namespace and row["docs"] == ["other-doc"] for row in rows)
            assert any(row["dataset"] == dataset_b and "same-doc" in row["docs"] for row in rows)
            assert any(row["namespace"] == namespace + "-other" and "same-doc" in row["docs"] for row in rows)
            assert any(row["relation"] == "UNRELATED_ORPHAN" for row in rows)
            assert session.run("MATCH (n:Entity {dataset_id:$dataset, neo4j_namespace:$namespace, name:'OnlyTarget'}) RETURN count(n) AS c", dataset=dataset_a, namespace=namespace).single()["c"] == 0
        print("LIVE PASS: scoped Qdrant (three untouched points); shared Neo4j provenance, dataset and namespace isolation; Temporal worker restart, lost sink/callback acknowledgements, duplicate start/completion and history replay")

        # Force a terminal sink failure, then restart under the same deterministic workflow ID.
        @activity.defn(name=DELETE_MANAGED_GRAPH_ACTIVITY)
        async def fail_graph(_payload):
            raise ApplicationError("Injected terminal graph failure", non_retryable=True)

        failure_input = {**task_input, "operation_id": "delete_" + hashlib.sha256((token + "retry").encode()).hexdigest()}
        failure_id = "managed-" + failure_input["operation_id"]
        with ThreadPoolExecutor(max_workers=4) as executor:
            async with Worker(client, task_queue=queue, workflows=[DeleteManagedDocumentWorkflow]):
                async with Worker(client, task_queue=queue + "-indexer", activities=[verify_managed_deletion_writers, vectors, fail_graph, report_managed_deletion], activity_executor=executor):
                    failed_start = await bridge.start_managed_deletion(workflow_id=failure_id, workflow_input=failure_input)
                    try:
                        await client.get_workflow_handle(failure_id).result()
                        raise AssertionError("Graph failure must fail the workflow")
                    except __import__('temporalio.client', fromlist=['WorkflowFailureError']).WorkflowFailureError:
                        pass
                async with Worker(client, task_queue=queue + "-indexer", activities=[verify_managed_deletion_writers, vectors, graph, report_managed_deletion], activity_executor=executor):
                    retry_start = await bridge.start_managed_deletion(workflow_id=failure_id, workflow_input=failure_input)
                    assert retry_start["run_id"] != failed_start["run_id"]
                    result = await client.get_workflow_handle(failure_id).result()
                    assert result["status"] == "completed"
        assert receipts[-2]["status"] == "failed" and receipts[-1]["status"] == "completed"
        print("LIVE PASS: terminal failure reports failure; retry uses same workflow ID and a new run; already-cleaned sinks verify successfully")
    finally:
        server.shutdown()
        server.server_close()
        with graph_a._session() as session:
            session.run("MATCH (n) WHERE n.neo4j_namespace IN $namespaces DETACH DELETE n", namespaces=[namespace, namespace + "-other"]).consume()
        for graph_client in (graph_a, graph_b, graph_other):
            graph_client.close()
        writer._gateway.delete_collection(collection, timeout=30).raise_for_status()


def test_managed_deletion_live():
    import pytest
    if os.environ.get("RAWKI_MANAGED_DELETION_LIVE") != "1":
        pytest.skip("Set RAWKI_MANAGED_DELETION_LIVE=1 to use disposable live scopes")
    asyncio.run(run_live())


if __name__ == "__main__":
    asyncio.run(run_live())
