"""Focused behavior tests for the bridge HTTP and application boundaries."""

from __future__ import annotations

import asyncio
import logging
from collections.abc import Callable
from typing import Any, cast

import pytest
from fastapi import APIRouter
from fastapi.routing import APIRoute
from neo4j.exceptions import Neo4jError, ServiceUnavailable
from temporalio.service import RPCError, RPCStatusCode

from hawki_bridge.adapters.neo4j_reader import Neo4jReader
from hawki_bridge.adapters.qdrant_reader import QdrantReader
from hawki_bridge.adapters.temporal_client import (
    TemporalBridgeClient,
    TemporalExecution,
)
from hawki_bridge.application.dependencies import QueryDependencies
from hawki_bridge.application.graph import GraphReadService
from hawki_bridge.domain.errors import (
    AnswerGenerationError,
    BridgeQueryError,
    DatasetVectorStoreNotReadyError,
    EmbeddingGenerationError,
    InvalidQueryError,
    UnsupportedModelProviderError,
)
from hawki_bridge.http.errors import query_error_to_http_exception
from hawki_bridge.http.routers.graph import build_graph_router
from hawki_bridge.http.routers.health import build_health_router
from hawki_bridge.http.routers.query import build_query_router
from hawki_bridge.http.routers.temporal import build_temporal_router
from hawki_bridge.http.schemas import (
    CancelWorkflowRequest,
    DeleteScheduleRequest,
    GraphReadRequest,
    QueryRequest,
    StartIngestWorkflowRequest,
    UpsertIngestScheduleRequest,
)
from hawki_bridge.settings import load_settings
from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope


def _endpoint(router: APIRouter, path: str, method: str) -> Callable[..., Any]:
    for route in router.routes:
        if (
            isinstance(route, APIRoute)
            and route.path == path
            and method in route.methods
        ):
            return cast(Callable[..., Any], route.endpoint)
    raise AssertionError(f"Missing {method} {path}")


def test_qdrant_adapter_translates_missing_scoped_collection() -> None:
    from hawki_vector_store.client import ScopedCollectionNotReadyError

    class MissingCollectionClient:
        def search_with_text(self, *_args, **_kwargs):
            raise ScopedCollectionNotReadyError("missing")

    reader = QdrantReader(client=cast(Any, MissingCollectionClient()))

    with pytest.raises(DatasetVectorStoreNotReadyError, match="missing"):
        reader.search_with_text(
            [0.1],
            top_k=1,
            terms=["fee"],
            fields=["content"],
        )


@pytest.mark.parametrize(
    ("error", "status_code"),
    [
        (InvalidQueryError("invalid"), 400),
        (UnsupportedModelProviderError("missing"), 400),
        (EmbeddingGenerationError("failed"), 500),
        (AnswerGenerationError("failed"), 502),
        (DatasetVectorStoreNotReadyError("missing"), 503),
    ],
)
def test_query_errors_are_translated_only_at_the_http_boundary(
    error: BridgeQueryError,
    status_code: int,
) -> None:
    assert query_error_to_http_exception(error).status_code == status_code


def _scope(*, graph_enabled: bool = False) -> AuthorizedQueryScope:
    return AuthorizedQueryScope(
        dataset_id="dataset-42",
        qdrant_collection="dataset_42_vectors",
        neo4j_namespace="dataset_42_graph" if graph_enabled else None,
        embedding_provider="ollama",
        embedding_model="nomic-embed-text",
        graph_enabled=graph_enabled,
    )


def test_query_model_selection_uses_validated_contract_fields() -> None:
    from hawki_bridge.application.query.model_selection import apply_query_models

    class Provider:
        embed_model = "initial-embedding"
        rag_model = "initial-chat"
        vision_model = "initial-vision"

    request = QueryRequest(
        query="How does model selection work?",
        authorized_scope=AuthorizedQueryScope(
            dataset_id="dataset-42",
            qdrant_collection="dataset_42_vectors",
            embedding_provider="ollama",
            embedding_model="authorized-embedding",
        ),
        provider="ollama",
        chat_model="selected-chat",
        vision_model="selected-vision",
    )
    provider = Provider()

    assert not hasattr(request, "embedding_model")
    assert not hasattr(request, "graph_model")

    apply_query_models(provider, request)

    assert provider.embed_model == "authorized-embedding"
    assert provider.rag_model == "selected-chat"
    assert provider.vision_model == "selected-vision"


def _workflow_input(*, source_id: str) -> dict[str, Any]:
    return {
        "source_id": source_id,
        "source_url": "https://example.test/source",
        "dataset_id": "dataset-42",
        "task_id": "task-42",
        "job_id": "job-42",
        "raw_output_path": f"/shared/sources/{source_id}/raw",
        "markdown_output_path": f"/shared/sources/{source_id}/markdown",
        "storage": {"mode": "shared", "shared_root": "/shared"},
        "ingestion": {
            "provider": "ollama",
            "embedding_model": "nomic-embed-text",
            "graph_model": "llama3.1:8b",
            "vision_model": "qwen2.5vl:7b",
            "collection": "dataset_42_vectors",
        },
    }


def test_query_route_applies_defaults_and_delegates_to_application(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    from hawki_bridge.http.routers import query as query_router_module

    observed: dict[str, Any] = {}

    def fake_execute_authorized_query(body, *, dependencies):
        observed["body"] = body
        observed["dependencies"] = dependencies
        return {"ok": True, "count": 0, "hits": []}

    monkeypatch.setattr(
        query_router_module,
        "execute_authorized_query",
        fake_execute_authorized_query,
    )
    settings = load_settings(
        {
            "RERANKER_MODE": "external",
            "RERANKER_MIX_MODE": "false",
            "RERANKER_MIX_WEIGHT": "0.25",
        }
    )
    dependencies = QueryDependencies(
        vector_search_factory=lambda: cast(Any, object()),
        graph_search=cast(Any, object()),
        resolve_model_provider=lambda _name: cast(Any, object()),
        rerank_hits=lambda **kwargs: kwargs["hits"],
    )
    endpoint = _endpoint(
        build_query_router(
            settings=settings,
            dependencies=dependencies,
        ),
        "/query",
        "POST",
    )
    request = QueryRequest(
        query="What is scoped retrieval?",
        authorized_scope=_scope(),
        provider="ollama",
        chat_model="llama3.1:8b",
        vision_model="qwen2.5vl:7b",
    )

    assert endpoint(request) == {"ok": True, "count": 0, "hits": []}
    configured = observed["body"]
    assert configured.authorized_scope.dataset_id == "dataset-42"
    assert observed["dependencies"] is dependencies
    assert configured.reranker == "external"
    assert configured.mix_mode is False
    assert configured.mix_weight == 0.25
    assert request.reranker == "none"


def test_graph_route_delegates_only_the_authorized_dataset_scope() -> None:
    class Reader:
        def __init__(self) -> None:
            self.calls: list[dict[str, Any]] = []

        def fetch_related_graph(self, terms: list[str], **scope: Any):
            self.calls.append({"terms": terms, **scope})
            return [{"subject": "A", "relation": "uses", "object": "B"}]

    reader = Reader()
    service = GraphReadService(reader)
    endpoint = _endpoint(
        build_graph_router(service=service),
        "/graph/related",
        "POST",
    )
    request = GraphReadRequest(
        authorized_scope=_scope(graph_enabled=True),
        terms=["retrieval", "authorization"],
        limit=17,
    )

    assert endpoint(request) == {
        "facts": [{"subject": "A", "relation": "uses", "object": "B"}]
    }
    assert reader.calls == [
        {
            "terms": ["retrieval", "authorization"],
            "dataset_id": "dataset-42",
            "neo4j_namespace": "dataset_42_graph",
            "limit": 17,
        }
    ]


def test_graph_application_rejects_a_scope_without_graph_authorization() -> None:
    class FailIfCalledReader:
        def fetch_related_graph(self, *_args: Any, **_kwargs: Any):
            raise AssertionError("unauthorized graph read reached the store")

    service = GraphReadService(FailIfCalledReader())

    with pytest.raises(ValueError, match="graph access is not enabled"):
        service.retrieve_related_graph(
            ["forbidden"],
            authorized_scope=_scope(),
            limit=5,
        )


def test_neo4j_adapter_delegates_a_read_without_changing_scope(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    from hawki_bridge.adapters import neo4j_reader as reader_module

    observed: dict[str, Any] = {}

    def fake_fetch_related_graph(terms: list[str], **scope: Any):
        observed.update({"terms": terms, **scope})
        return [{"subject": "A", "relation": "uses", "object": "B"}]

    monkeypatch.setattr(reader_module, "fetch_related_graph", fake_fetch_related_graph)

    result = Neo4jReader().fetch_related_graph(
        ["authorization"],
        dataset_id="dataset-42",
        neo4j_namespace="dataset_42_graph",
        limit=11,
    )

    assert result == [{"subject": "A", "relation": "uses", "object": "B"}]
    assert observed == {
        "terms": ["authorization"],
        "dataset_id": "dataset-42",
        "neo4j_namespace": "dataset_42_graph",
        "limit": 11,
    }


@pytest.mark.parametrize(
    "error",
    [
        Neo4jError._hydrate_neo4j(
            code="Neo.ClientError.Security.Unauthorized",
            message="bad credentials",
        ),
        ValueError("result contract is invalid"),
    ],
)
@pytest.mark.parametrize("operation", ["related", "structural"])
def test_neo4j_adapter_propagates_non_availability_errors(
    monkeypatch: pytest.MonkeyPatch,
    error: BaseException,
    operation: str,
) -> None:
    from hawki_bridge.adapters import neo4j_reader as reader_module

    class FailingGraph:
        def __init__(self, **_kwargs: Any) -> None:
            pass

        def fetch_related(self, *_args: Any, **_kwargs: Any) -> list[dict[str, str]]:
            raise error

        def search_structural(
            self, *_args: Any, **_kwargs: Any
        ) -> list[dict[str, Any]]:
            raise error

        def close(self) -> None:
            pass

    monkeypatch.setattr(reader_module, "Neo4jGraph", FailingGraph)

    with pytest.raises(type(error)):
        if operation == "related":
            reader_module.fetch_related_graph(
                ["term"], dataset_id="dataset-a", neo4j_namespace="graph-a"
            )
        else:
            reader_module.build_structural_hits(
                ["term"],
                dataset_id="dataset-a",
                neo4j_namespace="graph-a",
                limit=10,
                hops=2,
            )


@pytest.mark.parametrize("operation", ["related", "structural"])
def test_neo4j_adapter_degrades_only_unavailability_to_empty(
    monkeypatch: pytest.MonkeyPatch,
    operation: str,
) -> None:
    from hawki_bridge.adapters import neo4j_reader as reader_module

    class UnavailableGraph:
        def __init__(self, **_kwargs: Any) -> None:
            pass

        def fetch_related(self, *_args: Any, **_kwargs: Any) -> list[dict[str, str]]:
            raise ServiceUnavailable("connection details must stay private")

        def search_structural(
            self, *_args: Any, **_kwargs: Any
        ) -> list[dict[str, Any]]:
            raise ServiceUnavailable("connection details must stay private")

        def close(self) -> None:
            pass

    monkeypatch.setattr(reader_module, "Neo4jGraph", UnavailableGraph)

    if operation == "related":
        result = reader_module.fetch_related_graph(
            ["term"], dataset_id="dataset-a", neo4j_namespace="graph-a"
        )
    else:
        result = reader_module.build_structural_hits(
            ["term"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            limit=10,
            hops=2,
        )

    assert result == []


def test_health_route_can_include_or_omit_the_runtime_summary() -> None:
    calls = 0

    def runtime_summary() -> dict[str, str]:
        nonlocal calls
        calls += 1
        return {"role": "bridge", "mode": "read-only"}

    endpoint = _endpoint(
        build_health_router(runtime_summary=runtime_summary),
        "/health",
        "GET",
    )

    assert endpoint(runtime=True) == {
        "ok": True,
        "runtime": {"role": "bridge", "mode": "read-only"},
    }
    assert endpoint(runtime=False) == {"ok": True, "runtime": {}}
    assert calls == 1


def test_temporal_routes_delegate_to_the_injected_client() -> None:
    calls: list[tuple[str, dict[str, Any]]] = []

    class TemporalClient:
        async def start_ingest_workflow(self, **arguments: Any) -> TemporalExecution:
            calls.append(("start", arguments))
            return TemporalExecution(
                workflow_id=arguments["workflow_id"], run_id="run-7"
            )

        async def upsert_ingest_schedule(self, **arguments: Any) -> TemporalExecution:
            calls.append(("upsert", arguments))
            return TemporalExecution(
                workflow_id=arguments["workflow_id"],
                schedule_id=arguments["schedule_id"],
            )

        async def delete_schedule(self, **arguments: Any) -> None:
            calls.append(("delete", arguments))

        async def cancel_workflow(self, **arguments: Any) -> None:
            calls.append(("cancel", arguments))

    settings = load_settings({})
    temporal_client = TemporalClient()

    def client_factory(received_settings):
        assert received_settings is settings
        return temporal_client

    router = build_temporal_router(
        settings=settings,
        logger=logging.getLogger("test.hawki_bridge.temporal"),
        client_factory=client_factory,
    )
    start = _endpoint(router, "/temporal/workflows/ingest", "POST")
    upsert = _endpoint(router, "/temporal/schedules/ingest", "POST")
    delete = _endpoint(router, "/temporal/schedules/delete", "POST")
    cancel = _endpoint(router, "/temporal/workflows/cancel", "POST")
    workflow_input = _workflow_input(source_id="source-9")

    assert asyncio.run(
        start(
            StartIngestWorkflowRequest(
                workflow_id="ingest-source-9",
                workflow_input=workflow_input,
            )
        )
    ) == {
        "workflow_id": "ingest-source-9",
        "run_id": "run-7",
        "schedule_id": None,
    }
    assert asyncio.run(
        upsert(
            UpsertIngestScheduleRequest(
                schedule_id="refresh-source-9",
                workflow_id="ingest-source-9",
                cadence="daily",
                workflow_input=workflow_input,
            )
        )
    ) == {
        "workflow_id": "ingest-source-9",
        "run_id": None,
        "schedule_id": "refresh-source-9",
    }
    assert asyncio.run(
        delete(DeleteScheduleRequest(schedule_id="refresh-source-9"))
    ) == {"ok": True}
    assert asyncio.run(
        cancel(CancelWorkflowRequest(workflow_id="ingest-source-9", run_id="run-7"))
    ) == {"ok": True}
    assert calls == [
        (
            "start",
            {"workflow_id": "ingest-source-9", "workflow_input": workflow_input},
        ),
        (
            "upsert",
            {
                "schedule_id": "refresh-source-9",
                "workflow_id": "ingest-source-9",
                "cadence": "daily",
                "workflow_input": workflow_input,
            },
        ),
        ("delete", {"schedule_id": "refresh-source-9"}),
        ("cancel", {"workflow_id": "ingest-source-9", "run_id": "run-7"}),
    ]


@pytest.mark.parametrize(
    ("status", "raises"),
    [
        (RPCStatusCode.NOT_FOUND, False),
        (RPCStatusCode.UNAVAILABLE, True),
    ],
)
def test_temporal_cancel_is_idempotent_only_when_the_execution_is_absent(
    monkeypatch: pytest.MonkeyPatch,
    status: RPCStatusCode,
    raises: bool,
) -> None:
    class MissingWorkflowHandle:
        async def cancel(self) -> None:
            raise RPCError("workflow execution not found", status, b"")

    class TemporalClient:
        @staticmethod
        def get_workflow_handle(workflow_id: str, *, run_id: str | None = None):
            assert workflow_id == "ingest-source-stale"
            assert run_id == "run-stale"
            return MissingWorkflowHandle()

    async def client(_self):
        return TemporalClient()

    monkeypatch.setattr(TemporalBridgeClient, "connect_temporal", client)
    bridge = TemporalBridgeClient(load_settings({}))
    cancellation = bridge.cancel_workflow(
        workflow_id="ingest-source-stale",
        run_id="run-stale",
    )

    if raises:
        with pytest.raises(RPCError) as captured:
            asyncio.run(cancellation)
        assert captured.value.status == status
    else:
        asyncio.run(cancellation)
