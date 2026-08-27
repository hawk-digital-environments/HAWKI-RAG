"""Focused behavior tests for the extracted Neo4j store package."""

from __future__ import annotations

from types import SimpleNamespace
from typing import Any

import pytest
from neo4j.exceptions import (
    AuthError,
    DriverError,
    Neo4jError,
    ServiceUnavailable,
)

from hawki_graph_store.contracts import GraphScope
from hawki_graph_store.graph import Neo4jGraph
from hawki_graph_store.normalization import (
    dedupe_one_way_triplets,
    normalize_relation_label,
)
from hawki_graph_store.requests import (
    Neo4jQueryRequest,
    build_search_structural_query,
    build_triplet_rows,
)
from hawki_graph_store.responses import parse_fact_rows, parse_structural_rows
from hawki_graph_store.transport import Neo4jQueryExecutor
from hawki_graph_store.ports import GraphReader, GraphWriter


def test_neo4j_adapter_preserves_the_graph_ports_and_scope_contract() -> None:
    scope = GraphScope(" dataset-a ", " graph-a ")
    graph = Neo4jGraph(
        dataset_id=scope.dataset_id,
        neo4j_namespace=scope.neo4j_namespace,
        settings=SimpleNamespace(database=None, log_latency=False, perf_log=False),
        query_executor=SimpleNamespace(run_read=None, run_write=None),
    )

    assert scope.dataset_id == "dataset-a"
    assert scope.neo4j_namespace == "graph-a"
    assert isinstance(graph, GraphReader)
    assert isinstance(graph, GraphWriter)
    with pytest.raises(ValueError, match="requires non-empty"):
        GraphScope("", "graph-a")


def test_request_and_response_primitives_enforce_dataset_scope() -> None:
    assert build_triplet_rows([("Subject", "related", "Object")], "doc-1") == []
    rows = build_triplet_rows(
        [("Subject", "related", "Object")],
        "doc-1",
        dataset_id="dataset-a",
        neo4j_namespace="graph-a",
    )
    assert rows == [
        {
            "s": "Subject",
            "s_key": "subject",
            "r": "related",
            "o": "Object",
            "o_key": "object",
            "doc_id": "doc-1",
            "dataset_id": "dataset-a",
            "neo4j_namespace": "graph-a",
        }
    ]
    statement = build_search_structural_query(2, include_rel_match=True)
    for alias in ("s", "o"):
        assert f"{alias}.dataset_id = $dataset_id" in statement
        assert f"{alias}.neo4j_namespace = $neo4j_namespace" in statement
    assert "rel.dataset_id = $dataset_id" in statement
    assert parse_fact_rows(
        [
            {"subject": "A", "relation": "R", "object": "B"},
            {"subject": "B", "relation": "R", "object": "A"},
        ]
    ) == [{"subject": "A", "relation": "R", "object": "B"}]
    assert (
        parse_structural_rows(
            [{"subject": "A", "relation": "R", "object": "B", "hops": "0"}]
        )[0]["hops"]
        == 1
    )


def test_graph_normalization_preserves_cleanup_contracts() -> None:
    assert normalize_relation_label(" equivalent-to, ignored ") == "equivalent"
    assert dedupe_one_way_triplets([("A", "R", "B"), ("B", "R", "A")]) == [
        ("A", "R", "B")
    ]


def test_graph_uses_injected_executor_and_materializes_write_result() -> None:
    materialized = {"write": False, "read": False}

    class WriteResult:
        def consume(self) -> None:
            materialized["write"] = True

    class ReadResult:
        def __iter__(self):
            materialized["read"] = True
            yield {"rel_type": "R", "count": 1}

    class RecordingExecutor:
        def __init__(self) -> None:
            self.requests: list[Neo4jQueryRequest] = []

        def run_read(self, request: Neo4jQueryRequest, callback: Any) -> Any:
            return callback(SimpleNamespace(run=lambda *_args, **_kwargs: ReadResult()))

        def run_write(self, request: Neo4jQueryRequest, callback: Any) -> Any:
            self.requests.append(request)
            return callback(
                SimpleNamespace(run=lambda *_args, **_kwargs: WriteResult())
            )

    executor = RecordingExecutor()
    settings = SimpleNamespace(database=None, log_latency=False, perf_log=False)
    graph = Neo4jGraph(
        dataset_id="dataset-a",
        neo4j_namespace="graph-a",
        settings=settings,
        query_executor=executor,  # type: ignore[arg-type]
    )

    graph.upsert_triplets([("A", "R", "B")], doc_id="doc-1")
    graph.upsert_triplets([("A", "R", "B")], doc_id="doc-1", request_id="job:one")
    assert graph.count_relationships_by_type() == [{"type": "R", "count": 1}]

    assert executor.requests[1].request_id == "job:one"
    assert materialized == {"write": True, "read": True}


@pytest.mark.parametrize("method", ["run_read", "run_write"])
def test_query_executor_delegates_retry_ownership_to_managed_transaction(
    method: str,
) -> None:
    class Session:
        attempts = 0

        def __enter__(self) -> "Session":
            return self

        def __exit__(self, *_args: object) -> None:
            return None

        def execute_read(self, callback: Any) -> str:
            type(self).attempts += 1
            raise ServiceUnavailable("managed retry window exhausted")

        execute_write = execute_read

    executor = Neo4jQueryExecutor(Session)
    query = Neo4jQueryRequest("RETURN 1", {}, operation="neo4j.fetch_related")

    with pytest.raises(ServiceUnavailable):
        getattr(executor, method)(query, callback=lambda tx: str(tx))
    assert Session.attempts == 1


def test_graph_configures_driver_managed_retry_window(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    captured: dict[str, Any] = {}

    class Driver:
        def close(self) -> None:
            captured["closed"] = True

    def create_driver(uri: str, **kwargs: Any) -> Driver:
        captured.update(uri=uri, **kwargs)
        return Driver()

    monkeypatch.setattr("hawki_graph_store.graph.GraphDatabase.driver", create_driver)
    graph = Neo4jGraph(
        settings=SimpleNamespace(
            uri="bolt://graph:7687",
            user="neo4j",
            password="secret",
            database=None,
            max_transaction_retry_time=12.5,
            log_latency=False,
            perf_log=False,
        )
    )

    assert captured["max_transaction_retry_time"] == 12.5
    graph.close()
    assert captured["closed"] is True


def test_neo4j_settings_parse_the_driver_managed_retry_window(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    from hawki_graph_store.settings import load_neo4j_settings

    monkeypatch.setenv("NEO4J_MAX_TRANSACTION_RETRY_TIME", "8.5")
    assert load_neo4j_settings().max_transaction_retry_time == 8.5

    monkeypatch.setenv("NEO4J_MAX_TRANSACTION_RETRY_TIME", "invalid")
    assert load_neo4j_settings().max_transaction_retry_time == 30.0


def test_graph_falls_back_only_for_database_not_found(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    missing = Neo4jError._hydrate_neo4j(
        code="Neo.ClientError.Database.DatabaseNotFound", message="missing"
    )

    class Session:
        def __enter__(self) -> "Session":
            return self

        def __exit__(self, *_args: object) -> None:
            return None

        def run(self, _statement: str) -> Any:
            raise missing

    class Driver:
        closed = False

        def session(self, **_kwargs: Any) -> Session:
            return Session()

        def close(self) -> None:
            self.closed = True

    driver = Driver()
    monkeypatch.setattr(
        "hawki_graph_store.graph.GraphDatabase.driver",
        lambda *_args, **_kwargs: driver,
    )
    graph = Neo4jGraph(
        settings=SimpleNamespace(
            uri="bolt://graph:7687",
            user="neo4j",
            password="secret",
            database="missing",
            max_transaction_retry_time=30.0,
            log_latency=False,
            perf_log=False,
        )
    )

    assert graph._database is None
    assert driver.closed is False


def test_graph_does_not_fallback_for_authentication_errors(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    unauthorized = Neo4jError._hydrate_neo4j(
        code="Neo.ClientError.Security.Unauthorized", message="unauthorized"
    )

    class Session:
        def __enter__(self) -> "Session":
            return self

        def __exit__(self, *_args: object) -> None:
            return None

        def run(self, _statement: str) -> Any:
            raise unauthorized

    class Driver:
        closed = False

        def session(self, **_kwargs: Any) -> Session:
            return Session()

        def close(self) -> None:
            self.closed = True
            raise DriverError("close failed")

    driver = Driver()
    monkeypatch.setattr(
        "hawki_graph_store.graph.GraphDatabase.driver",
        lambda *_args, **_kwargs: driver,
    )

    with pytest.raises(AuthError):
        Neo4jGraph(
            settings=SimpleNamespace(
                uri="bolt://graph:7687",
                user="neo4j",
                password="wrong",
                database="neo4j",
                max_transaction_retry_time=30.0,
                log_latency=False,
                perf_log=False,
            )
        )
    assert driver.closed is True
