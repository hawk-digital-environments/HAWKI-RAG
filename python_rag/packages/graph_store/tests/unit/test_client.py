"""Neo4j client executor reuse."""

from __future__ import annotations

from types import SimpleNamespace


def test_neo4j_client_ops_reuse_managed_transaction_executor() -> None:
    from hawki_graph_store.client import ensure_query_executor

    existing = object()
    assert (
        ensure_query_executor(
            existing,
            session_factory=lambda: None,
            settings=SimpleNamespace(log_latency=False),
        )
        is existing
    )
