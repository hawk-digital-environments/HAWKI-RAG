"""Focused behavior tests for the extracted Neo4j store package."""

from __future__ import annotations

from types import SimpleNamespace
from typing import Any

from neo4j.exceptions import Neo4jError

from hawki_rag_stores.neo4j.graph import Neo4jGraph
from hawki_rag_stores.neo4j.normalization import (
    dedupe_one_way_triplets,
    normalize_relation_label,
)
from hawki_rag_stores.neo4j.requests import (
    Neo4jQueryRequest,
    build_search_structural_query,
    build_triplet_rows,
)
from hawki_rag_stores.neo4j.responses import parse_fact_rows, parse_structural_rows
from hawki_rag_stores.neo4j.transport import Neo4jQueryExecutor
from hawki_rag_stores.neo4j.traversal import clean_triplets


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
    assert clean_triplets(
        [
            ("University", "located in", "Lübeck"),
            ("Lübeck", "located in", "University"),
            ("page 12", "has title", "image.png"),
        ],
        graph_perf_log=False,
    ) == [("University", "located in", "Lübeck")]


def test_graph_uses_injected_executor_and_idempotent_write_policy() -> None:
    class RecordingExecutor:
        def __init__(self) -> None:
            self.requests: list[Neo4jQueryRequest] = []

        def run_read(self, request: Neo4jQueryRequest, callback: Any) -> Any:
            return callback(SimpleNamespace(run=lambda *_args, **_kwargs: None))

        def run_write(self, request: Neo4jQueryRequest, callback: Any) -> Any:
            self.requests.append(request)
            return callback(SimpleNamespace(run=lambda *_args, **_kwargs: None))

    executor = RecordingExecutor()
    settings = SimpleNamespace(
        database=None, retry_attempts=1, log_latency=False, perf_log=False
    )
    graph = Neo4jGraph(
        dataset_id="dataset-a",
        neo4j_namespace="graph-a",
        settings=settings,
        query_executor=executor,  # type: ignore[arg-type]
    )

    graph.upsert_triplets([("A", "R", "B")], doc_id="doc-1")
    graph.upsert_triplets([("A", "R", "B")], doc_id="doc-1", request_id="job:one")

    assert executor.requests[0].retryable is False
    assert executor.requests[1].retryable is True
    assert executor.requests[1].request_id == "job:one"


def test_query_executor_retries_only_transient_driver_errors() -> None:
    class Session:
        attempts = 0

        def __enter__(self) -> "Session":
            return self

        def __exit__(self, *_args: object) -> None:
            return None

        def execute_read(self, callback: Any) -> str:
            type(self).attempts += 1
            if type(self).attempts == 1:
                raise Neo4jError("transient connection timeout")
            return callback("transaction")

    executor = Neo4jQueryExecutor(
        Session,
        retry_attempts=2,
        backoff_seconds=0,
    )
    query = Neo4jQueryRequest("RETURN 1", {}, operation="neo4j.fetch_related")

    assert executor.run_read(query, callback=lambda tx: str(tx)) == "transaction"
    assert Session.attempts == 2
