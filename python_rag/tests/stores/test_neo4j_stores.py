"""Focused behavior tests for the extracted Neo4j store package."""

from __future__ import annotations

from types import SimpleNamespace
from typing import Any

import pytest
from neo4j.exceptions import (
    AuthConfigurationError,
    AuthError,
    BrokenRecordError,
    CertificateConfigurationError,
    ClientError,
    ConfigurationError,
    ConnectionAcquisitionTimeoutError,
    ConnectionPoolError,
    ConstraintError,
    CypherSyntaxError,
    CypherTypeError,
    DatabaseError,
    DatabaseUnavailable,
    DriverError,
    Forbidden,
    ForbiddenOnReadOnlyDatabase,
    IncompleteCommit,
    Neo4jError,
    NotALeader,
    ReadServiceUnavailable,
    ResultConsumedError,
    ResultError,
    ResultFailedError,
    ResultNotSingleError,
    RoutingServiceUnavailable,
    ServiceUnavailable,
    SessionError,
    SessionExpired,
    TokenExpired,
    TransactionError,
    TransactionNestingError,
    TransientError,
    UnsupportedServerProduct,
    WriteServiceUnavailable,
)

from hawki_rag_stores.neo4j.errors import (
    Neo4jFailureFamily,
    classify_neo4j_error,
    is_database_not_found_error,
)
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


def test_graph_uses_injected_executor_and_materializes_write_result() -> None:
    class RecordingExecutor:
        def __init__(self) -> None:
            self.requests: list[Neo4jQueryRequest] = []

        def run_read(self, request: Neo4jQueryRequest, callback: Any) -> Any:
            return callback(SimpleNamespace(run=lambda *_args, **_kwargs: None))

        def run_write(self, request: Neo4jQueryRequest, callback: Any) -> Any:
            self.requests.append(request)
            result = SimpleNamespace(consume=lambda: None)
            return callback(SimpleNamespace(run=lambda *_args, **_kwargs: result))

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

    assert executor.requests[1].request_id == "job:one"


def test_query_executor_delegates_retry_ownership_to_managed_transaction(
    caplog: pytest.LogCaptureFixture,
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

    executor = Neo4jQueryExecutor(Session)
    query = Neo4jQueryRequest("RETURN 1", {}, operation="neo4j.fetch_related")

    with caplog.at_level("WARNING", logger="hawki_rag_stores.neo4j.transport"):
        with pytest.raises(ServiceUnavailable):
            executor.run_read(query, callback=lambda tx: str(tx))
    assert Session.attempts == 1
    assert "retry_owner=neo4j_driver_managed_transaction" in caplog.text


@pytest.mark.parametrize(
    ("error_type", "family"),
    [
        (Neo4jError, Neo4jFailureFamily.SERVER_OTHER),
        (ClientError, Neo4jFailureFamily.SERVER_CLIENT),
        (CypherSyntaxError, Neo4jFailureFamily.SERVER_CLIENT),
        (CypherTypeError, Neo4jFailureFamily.SERVER_CLIENT),
        (ConstraintError, Neo4jFailureFamily.SERVER_CLIENT),
        (AuthError, Neo4jFailureFamily.SERVER_CLIENT),
        (TokenExpired, Neo4jFailureFamily.SERVER_CLIENT),
        (Forbidden, Neo4jFailureFamily.SERVER_CLIENT),
        (DatabaseError, Neo4jFailureFamily.SERVER_DATABASE),
        (TransientError, Neo4jFailureFamily.SERVER_TRANSIENT),
        (DatabaseUnavailable, Neo4jFailureFamily.SERVER_TRANSIENT),
        (NotALeader, Neo4jFailureFamily.SERVER_TRANSIENT),
        (ForbiddenOnReadOnlyDatabase, Neo4jFailureFamily.SERVER_TRANSIENT),
        (DriverError, Neo4jFailureFamily.DRIVER_OTHER),
        (SessionError, Neo4jFailureFamily.DRIVER_SESSION),
        (SessionExpired, Neo4jFailureFamily.DRIVER_SESSION),
        (TransactionError, Neo4jFailureFamily.DRIVER_TRANSACTION),
        (TransactionNestingError, Neo4jFailureFamily.DRIVER_TRANSACTION),
        (ResultError, Neo4jFailureFamily.DRIVER_RESULT),
        (ResultFailedError, Neo4jFailureFamily.DRIVER_RESULT),
        (ResultConsumedError, Neo4jFailureFamily.DRIVER_RESULT),
        (ResultNotSingleError, Neo4jFailureFamily.DRIVER_RESULT),
        (BrokenRecordError, Neo4jFailureFamily.DRIVER_BROKEN_RECORD),
        (ServiceUnavailable, Neo4jFailureFamily.DRIVER_SERVICE),
        (RoutingServiceUnavailable, Neo4jFailureFamily.DRIVER_SERVICE),
        (WriteServiceUnavailable, Neo4jFailureFamily.DRIVER_SERVICE),
        (ReadServiceUnavailable, Neo4jFailureFamily.DRIVER_SERVICE),
        (IncompleteCommit, Neo4jFailureFamily.DRIVER_INCOMPLETE_COMMIT),
        (ConfigurationError, Neo4jFailureFamily.DRIVER_CONFIGURATION),
        (AuthConfigurationError, Neo4jFailureFamily.DRIVER_CONFIGURATION),
        (CertificateConfigurationError, Neo4jFailureFamily.DRIVER_CONFIGURATION),
        (UnsupportedServerProduct, Neo4jFailureFamily.DRIVER_CONFIGURATION),
        (ConnectionPoolError, Neo4jFailureFamily.DRIVER_CONNECTION_POOL),
        (
            ConnectionAcquisitionTimeoutError,
            Neo4jFailureFamily.DRIVER_CONNECTION_POOL,
        ),
    ],
)
def test_driver_6_public_exception_hierarchy_has_an_explicit_policy(
    error_type: type[Neo4jError] | type[DriverError],
    family: Neo4jFailureFamily,
) -> None:
    policy = classify_neo4j_error(error_type("test failure"))

    assert policy.family is family
    assert policy.retryable is error_type("test failure").is_retryable()
    assert policy.commit_outcome_unknown is (error_type is IncompleteCommit)


def test_database_fallback_is_limited_to_database_not_found() -> None:
    missing = Neo4jError._hydrate_neo4j(
        code="Neo.ClientError.Database.DatabaseNotFound",
        message="missing",
    )
    unauthorized = Neo4jError._hydrate_neo4j(
        code="Neo.ClientError.Security.Unauthorized",
        message="bad credentials",
    )

    assert isinstance(missing, ClientError)
    assert is_database_not_found_error(missing)
    assert isinstance(unauthorized, ClientError)
    assert not is_database_not_found_error(unauthorized)


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

    monkeypatch.setattr(
        "hawki_rag_stores.neo4j.graph.GraphDatabase.driver", create_driver
    )
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
    from hawki_rag_stores.neo4j.settings import load_neo4j_settings

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
        "hawki_rag_stores.neo4j.graph.GraphDatabase.driver",
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

    driver = Driver()
    monkeypatch.setattr(
        "hawki_rag_stores.neo4j.graph.GraphDatabase.driver",
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
