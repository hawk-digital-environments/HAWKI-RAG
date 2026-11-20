"""Real registration lifecycle with external HTTP and sink boundaries injected."""

from contextlib import nullcontext
from pathlib import Path
from types import SimpleNamespace

import httpx
import pytest

from hawki_indexer_worker.activities import index
from hawki_indexer_worker.adapters import managed_writer
from hawki_indexer_worker.adapters import neo4j_writer
from hawki_indexer_worker.indexing.batch_execution import (
    IndexBatchDependencies,
    IndexBatchRequest,
    execute_index_batches,
)
from hawki_indexer_worker.adapters.composition import build_ingest_workflow_dependencies
from hawki_artifact_store.local import LocalArtifactStore
from hawki_pipeline_callbacks import LaravelCallbackClient
from hawki_rag_contracts.pipeline.ingestion import IndexResult
from hawki_vector_store.client import QdrantHTTP, ScopedCollectionNotReadyError
from hawki_indexer_worker.indexing.point_identity import deterministic_point_id
from temporalio.exceptions import CancelledError
from services.hawki_indexer_worker.tests.unit.activities.managed_writer_test_support import (
    registration,
)


def test_signed_registration_uses_actual_execution_and_sibling_endpoint(
    tmp_path, monkeypatch
):
    import hashlib
    import hmac
    import json

    received = []

    def receive(request):
        raw = request.content
        signature = hmac.new(
            b"test-secret",
            request.headers["X-Hawki-Timestamp"].encode() + b"." + raw,
            hashlib.sha256,
        ).hexdigest()
        assert request.headers["X-Hawki-Signature"] == "v1=" + signature
        assert (
            str(request.url)
            == "http://internal.test/api/internal/pipeline/managed-writers"
        )
        event = json.loads(raw)
        received.append(event)
        return httpx.Response(
            200,
            json={
                "success": True,
                "status": "active" if event["action"] == "acquire" else "released",
                "targets": [
                    {
                        "doc_id": target["doc_id"],
                        "source_id": "source",
                        "dataset_id": "dataset",
                        "collection": "historic",
                        "neo4j_namespace": None,
                    }
                    for target in event["targets"]
                ],
            },
        )

    client = httpx.Client(transport=httpx.MockTransport(receive))
    monkeypatch.setattr(
        managed_writer,
        "LaravelCallbackClient",
        lambda settings: LaravelCallbackClient(settings, client=client),
    )
    writer = registration(tmp_path, attempt=2)
    writer.register_targets([])
    writer.register_targets([{"doc_id": "doc", "relative_path": "one.md"}])
    writer.release()
    assert [
        (event["workflow_id"], event["run_id"], event["activity_id"], event["attempt"])
        for event in received
    ] == [("original", "run-original", "7", 2)] * 3
    assert len({event["event_id"] for event in received}) == 3
    client.close()


@pytest.mark.parametrize(
    "failure, mutation_started, uncertain, released",
    [
        (None, False, False, True),
        (RuntimeError("failure before sink writes"), False, False, True),
        (RuntimeError("partial indexing"), True, False, True),
        (CancelledError("Canceled graph batch"), True, False, True),
        (TimeoutError("collection creation acknowledgement lost"), False, True, False),
        (KeyboardInterrupt(), False, False, True),
    ],
)
def test_index_transport_releases_only_after_acknowledged_sink_drain(
    tmp_path, monkeypatch, failure, mutation_started, uncertain, released
):
    writer = registration(tmp_path)
    calls = []
    monkeypatch.setattr(writer, "send", lambda action, targets: calls.append(action))
    monkeypatch.setattr(
        index.ManagedWriterRegistration,
        "acquire_for",
        lambda *_args: (writer.register_targets([]), writer)[1],
    )
    monkeypatch.setattr(index.IndexerSettings, "from_env", lambda: writer.settings)
    monkeypatch.setattr(index.activity, "info", lambda: writer.info)
    monkeypatch.setattr(index.activity, "shield_thread_cancel_exception", nullcontext)
    monkeypatch.setattr(
        index, "create_callback_sender", lambda *_args: nullcontext(object())
    )
    monkeypatch.setattr(
        index,
        "create_graph_extractor",
        lambda *_args, **_kwargs: calls.append("graph-dependency"),
    )

    def execute(_input, *, context, dependencies):
        assert dependencies.target_registrar == writer.register_targets
        assert dependencies.workflow_dependencies.checkpoint == writer.check_cancelled
        assert (
            dependencies.workflow_dependencies.vector_writer_factory
            == writer.vector_writer
        )
        calls.append("index")
        writer.mutation_started = mutation_started
        writer.uncertain = uncertain
        if failure is not None:
            raise failure
        return IndexResult(source_id="source", status="success")

    monkeypatch.setattr(index, "execute_index_activity", execute)
    payload = {
        "workflow_input": {
            "source_id": "source",
            "job_id": "job",
            "managed_document_id": "adoc_test",
        },
        "convert_result": {},
    }
    if failure is not None:
        with pytest.raises(type(failure)):
            index.ingest_markdown_files(payload)
    else:
        index.ingest_markdown_files(payload)
    assert calls == ["acquire", "graph-dependency", "index"] + (
        ["release"] if released else []
    )


def test_missing_ingestion_collection_has_no_existing_pages_but_queries_fail_closed(
    tmp_path, monkeypatch
):
    writer = registration(tmp_path)
    writer.targets["owned"] = None
    sink = writer.vector_writer()
    missing = SimpleNamespace(status_code=404)
    monkeypatch.setattr(sink._gateway, "scroll", lambda *_args, **_kwargs: missing)
    monkeypatch.setattr(
        sink, "_pick_default_collection", lambda: pytest.fail("unscoped fallback")
    )
    assert sink.find_points_by_payload({"doc_id": "owned"}) == []
    assert sink.collection == "historic"
    assert not writer.mutation_started
    created = []
    monkeypatch.setattr(sink._gateway, "get_collection", lambda: missing)
    monkeypatch.setattr(
        sink._gateway,
        "ensure_collection",
        lambda **kwargs: (
            created.append(kwargs),
            SimpleNamespace(raise_for_status=lambda: None),
        )[1],
    )
    sink.ensure_collection(2)
    assert created == [{"vector_size": 2, "distance": "Cosine"}]

    query = QdrantHTTP()
    query.select_scoped_collection("historic")
    monkeypatch.setattr(query._gateway, "scroll", lambda *_args, **_kwargs: missing)
    with pytest.raises(ScopedCollectionNotReadyError):
        query.find_points_by_payload({"doc_id": "owned"})


def test_unacknowledged_collection_creation_keeps_writer_uncertain(
    tmp_path, monkeypatch
):
    writer = registration(tmp_path)
    writer.targets["owned"] = None
    sink = writer.vector_writer()
    monkeypatch.setattr(
        QdrantHTTP,
        "ensure_collection",
        lambda *_args, **_kwargs: (_ for _ in ()).throw(TimeoutError("lost ack")),
    )
    with pytest.raises(TimeoutError):
        sink.ensure_collection(2)
    assert writer.uncertain
    assert not writer.mutation_started
    with pytest.raises(RuntimeError, match="uncertain"):
        writer.release()


@pytest.mark.parametrize("sink", ["vector", "graph"])
def test_canceled_managed_activity_cannot_begin_another_sink_mutation(
    tmp_path, monkeypatch, sink
):
    writer = registration(tmp_path)
    writer.targets["owned"] = "historic"
    monkeypatch.setattr(index.activity, "in_activity", lambda: True)
    monkeypatch.setattr(index.activity, "heartbeat", lambda *_args: None)
    monkeypatch.setattr(index.activity, "is_cancelled", lambda: True)
    with pytest.raises(CancelledError):
        if sink == "vector":
            writer.authorize_vector(
                {"doc_id": "owned", "source_id": "source", "dataset_id": "dataset"}
            )
        else:
            writer.authorize_graph("owned", "dataset", "historic")
    assert not writer.mutation_started


def test_unacknowledged_acquisition_cannot_start_indexing(tmp_path, monkeypatch):
    writer = registration(tmp_path)
    monkeypatch.setattr(
        writer, "send", lambda *_args: (_ for _ in ()).throw(RuntimeError("ack lost"))
    )
    monkeypatch.setattr(
        index.ManagedWriterRegistration,
        "acquire_for",
        lambda *_args: (writer.register_targets([]), writer)[1],
    )
    monkeypatch.setattr(index.IndexerSettings, "from_env", lambda: writer.settings)
    monkeypatch.setattr(index.activity, "info", lambda: writer.info)
    monkeypatch.setattr(index.activity, "shield_thread_cancel_exception", nullcontext)
    monkeypatch.setattr(
        index,
        "create_graph_extractor",
        lambda *_args, **_kwargs: pytest.fail("dependency before acquisition"),
    )
    with pytest.raises(RuntimeError, match="ack lost"):
        index.ingest_markdown_files(
            {
                "workflow_input": {
                    "source_id": "source",
                    "managed_document_id": "adoc_test",
                },
                "convert_result": {},
            }
        )


def test_swallowed_sink_failure_keeps_writer_uncertain_and_disables_transport_retries(
    tmp_path, monkeypatch
):
    writer = registration(tmp_path)
    writer.targets["owned"] = "historic"
    sink = writer.vector_writer()
    assert sink._transport._default_attempts == 1
    assert all(value == 1 for value in sink._transport._operation_attempts.values())
    monkeypatch.setattr(
        QdrantHTTP,
        "upsert",
        lambda *_args, **_kwargs: (_ for _ in ()).throw(TimeoutError("lost sink ack")),
    )
    with pytest.raises(TimeoutError):
        sink.upsert_points(
            [
                {
                    "id": deterministic_point_id("owned", 0),
                    "payload": {
                        "doc_id": "owned",
                        "source_id": "source",
                        "dataset_id": "dataset",
                    },
                }
            ]
        )
    monkeypatch.setattr(
        writer, "send", lambda *_args: pytest.fail("uncertain writer must not release")
    )
    with pytest.raises(RuntimeError, match="uncertain"):
        writer.release()


def test_vector_only_passthrough_registers_effective_graph_before_canonical_write(
    tmp_path, monkeypatch
):
    from hawki_indexer_worker.indexing import artifact_documents

    writer = registration(tmp_path)
    markdown = tmp_path / "markdown"
    markdown.mkdir()
    source = markdown / "one.md"
    source.write_text("# Passthrough artifact")
    monkeypatch.setattr(
        artifact_documents,
        "load_passthrough_metadata",
        lambda *_args, **_kwargs: {"converter_fallback": "raganything_passthrough"},
    )
    events = []

    def grant(action, targets):
        assert action == "acquire"
        events.extend(targets)
        return {
            "targets": [
                {
                    "doc_id": target["doc_id"],
                    "dataset_id": "dataset",
                    "source_id": "source",
                    "collection": "historic",
                    "neo4j_namespace": "historic",
                }
                for target in targets
            ]
        }

    monkeypatch.setattr(writer, "send", grant)
    canonical = SimpleNamespace(
        upsert_triplets=lambda *_args, **kwargs: events.append(
            ("graph-write", kwargs["doc_id"])
        ),
        close=lambda: None,
    )
    monkeypatch.setattr(neo4j_writer, "Neo4jGraph", lambda **_kwargs: canonical)

    def ingest(request, **_kwargs):
        assert request.graph is True
        assert events[0]["graph"] is True
        assert writer.targets[request.docs[0].id] == "historic"
        graph = writer.graph_writer(dataset_id="dataset", neo4j_namespace="historic")
        graph.upsert_triplets(
            [("A", "KNOWS", "B")],
            doc_id=request.docs[0].id,
            request_id="test",
            dataset_id="dataset",
            neo4j_namespace="historic",
        )
        return {"summary": {"documents": {"processed_docs": 1}}}

    execute_index_batches(
        IndexBatchRequest(
            files=[str(source)],
            workflow_input=writer.workflow_input,
            markdown_dir=str(markdown),
            manifest_path="",
            artifacts_by_path={},
        ),
        IndexBatchDependencies(
            artifact_store=LocalArtifactStore(tmp_path),
            graph_service=object(),
            provider_resolver=lambda _name: object(),
            workflow_dependencies=build_ingest_workflow_dependencies(),
            ingest_documents=ingest,
            target_registrar=writer.register_targets,
        ),
    )
    assert events[1] == ("graph-write", events[0]["doc_id"])


@pytest.mark.parametrize(
    "field", ["doc_id", "dataset_id", "source_id", "collection", "neo4j_namespace"]
)
def test_registration_rejects_grant_that_changes_trusted_scope(
    tmp_path, monkeypatch, field
):
    writer = registration(tmp_path)
    grant = {
        "doc_id": "owned",
        "dataset_id": "dataset",
        "source_id": "source",
        "collection": "historic",
        "neo4j_namespace": "historic",
    }
    grant[field] = "foreign"
    monkeypatch.setattr(writer, "send", lambda *_args: {"targets": [grant]})
    with pytest.raises(RuntimeError, match="grant conflicts"):
        writer.register_targets(
            [{"doc_id": "owned", "relative_path": "one.md", "graph": True}]
        )
    assert writer.targets == {}


@pytest.mark.parametrize(
    "violation",
    ["doc", "dataset", "source", "point", "collection", "delete", "payload"],
)
def test_registered_vector_rejects_mutation_outside_grant(
    tmp_path, monkeypatch, violation
):
    writer = registration(tmp_path)
    writer.targets["owned"] = "historic"
    sink = writer.vector_writer()
    calls = []
    monkeypatch.setattr(
        QdrantHTTP, "upsert", lambda *_args, **_kwargs: calls.append("upsert")
    )
    monkeypatch.setattr(
        QdrantHTTP, "delete_by_filter", lambda *_args, **_kwargs: calls.append("delete")
    )
    monkeypatch.setattr(
        QdrantHTTP, "set_payload", lambda *_args, **_kwargs: calls.append("payload")
    )
    payload = {
        "doc_id": "owned",
        "source_id": "source",
        "dataset_id": "dataset",
        "chunk_index": 0,
    }
    point_id = deterministic_point_id("owned", 0)
    with pytest.raises(RuntimeError):
        if violation == "collection":
            sink.set_collection("foreign")
        elif violation == "delete":
            sink.delete_by_filter(
                {"must": [{"key": "doc_id", "match": {"value": "owned"}}]}
            )
        elif violation == "payload":
            monkeypatch.setattr(
                sink,
                "scroll_by_filter",
                lambda *_args, **_kwargs: [{"id": point_id, "payload": payload}],
            )
            sink.set_payload([point_id], {"dataset_id": "foreign"})
        else:
            if violation != "point":
                payload[
                    {"doc": "doc_id", "source": "source_id", "dataset": "dataset_id"}[
                        violation
                    ]
                ] = "foreign"
            sink.upsert_points(
                [
                    {
                        "id": "foreign" if violation == "point" else point_id,
                        "payload": payload,
                    }
                ]
            )
    assert calls == []


@pytest.mark.parametrize(
    "violation", ["doc", "dataset", "namespace", "delete", "ungranted_graph"]
)
def test_registered_graph_rejects_mutation_outside_grant(
    tmp_path, monkeypatch, violation
):
    writer = registration(tmp_path)
    writer.targets["owned"] = None if violation == "ungranted_graph" else "historic"
    calls = []
    client = SimpleNamespace(
        upsert_triplets=lambda *_args, **_kwargs: calls.append("upsert"),
        delete_by_doc_id=lambda *_args, **_kwargs: calls.append("delete"),
    )
    graph = neo4j_writer.RegisteredGraphWriter(
        client,
        writer.failed_write,
        authorize=writer.authorize_graph,
        dataset_id="dataset",
        neo4j_namespace="historic",
    )
    with pytest.raises(RuntimeError):
        if violation == "delete":
            graph.delete_by_doc_id("foreign")
        else:
            graph.upsert_triplets(
                [("A", "KNOWS", "B")],
                doc_id="foreign" if violation == "doc" else "owned",
                request_id="test",
                dataset_id="foreign" if violation == "dataset" else "dataset",
                neo4j_namespace="foreign" if violation == "namespace" else "historic",
            )
    assert calls == []


def test_registered_targets_precede_partial_sink_output_and_manifest_creation(
    tmp_path: Path,
):
    markdown = tmp_path / "markdown"
    markdown.mkdir()
    source = markdown / "one.md"
    source.write_text("# Owned document")
    calls = []

    def ingest(request, **_kwargs):
        assert calls == [(str(request.docs[0].id), "one.md")]
        raise RuntimeError("Qdrant committed, graph failed")

    dependencies = IndexBatchDependencies(
        artifact_store=LocalArtifactStore(tmp_path),
        graph_service=object(),
        provider_resolver=lambda _name: object(),
        workflow_dependencies=build_ingest_workflow_dependencies(),
        ingest_documents=ingest,
        target_registrar=lambda targets: calls.extend(
            (target["doc_id"], target["relative_path"]) for target in targets
        ),
    )
    with pytest.raises(RuntimeError, match="graph failed"):
        execute_index_batches(
            IndexBatchRequest(
                files=[str(source)],
                workflow_input={
                    "source_id": "source",
                    "job_id": "job",
                    "dataset_id": "dataset",
                    "ingestion": {"collection": "historic", "graph": False},
                },
                markdown_dir=str(markdown),
                manifest_path=str(tmp_path / "manifest.json"),
                artifacts_by_path={},
            ),
            dependencies,
        )
    assert len(calls) == 1
    assert not (tmp_path / "manifest.json").exists()


def test_managed_graph_disables_driver_retries_and_database_fallback(
    tmp_path, monkeypatch
):
    captured = {}

    def create(**kwargs):
        captured.update(kwargs)
        return SimpleNamespace()

    monkeypatch.setattr(neo4j_writer, "Neo4jGraph", create)
    writer = registration(tmp_path)
    writer.targets["owned"] = "historic"
    writer.graph_writer(dataset_id="dataset", neo4j_namespace="historic")
    assert captured["allow_database_fallback"] is False
    assert captured["settings"].max_transaction_retry_time == 0
    assert captured["dataset_id"] == "dataset"
    assert captured["neo4j_namespace"] == "historic"
