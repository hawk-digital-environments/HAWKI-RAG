"""Read-only bridge graph scenarios replacing retired graph-write routes."""

from __future__ import annotations

import pytest
from pydantic import ValidationError

from hawki_bridge.application.graph import GraphReadService
from hawki_bridge.factory import build_app
from hawki_bridge.http.routers.graph import build_graph_router
from hawki_bridge.http.schemas import GraphReadRequest
from hawki_bridge.settings import load_settings


def _scope(*, graph_enabled: bool = True) -> dict[str, object]:
    return {
        "dataset_id": "dataset-a",
        "qdrant_collection": "hawki_dataset_a",
        "neo4j_namespace": "hawki_dataset_a",
        "embedding_provider": "ollama",
        "embedding_model": "bge-m3",
        "graph_enabled": graph_enabled,
    }


def _related_endpoint(reader):
    router = build_graph_router(service=GraphReadService(reader))
    return next(
        route.endpoint for route in router.routes if route.path == "/graph/related"
    )


class TestReadOnlyGraphApiFlow:
    """Prove graph HTTP access is scoped retrieval, never extraction or mutation."""

    def test_authorized_read_delegates_with_server_derived_graph_scope(self) -> None:
        calls: list[dict[str, object]] = []

        class Reader:
            def fetch_related_graph(
                self,
                terms: list[str],
                *,
                dataset_id: str,
                neo4j_namespace: str,
                limit: int,
            ) -> list[dict[str, str]]:
                calls.append(
                    {
                        "terms": terms,
                        "dataset_id": dataset_id,
                        "neo4j_namespace": neo4j_namespace,
                        "limit": limit,
                    }
                )
                return [{"subject": "fee", "predicate": "costs", "object": "320"}]

        request = GraphReadRequest(
            authorized_scope=_scope(),
            terms=["fee"],
            limit=12,
        )

        response = _related_endpoint(Reader())(request)

        assert response == {
            "facts": [{"subject": "fee", "predicate": "costs", "object": "320"}]
        }
        assert calls == [
            {
                "terms": ["fee"],
                "dataset_id": "dataset-a",
                "neo4j_namespace": "hawki_dataset_a",
                "limit": 12,
            }
        ]

    def test_graph_disabled_scope_is_rejected_before_store_access(self) -> None:
        with pytest.raises(ValidationError, match="graph access is not enabled"):
            GraphReadRequest(
                authorized_scope=_scope(graph_enabled=False),
                terms=["fee"],
            )

    def test_bridge_registers_graph_read_but_no_graph_write_routes(self) -> None:
        app = build_app(
            settings=load_settings({}),
            runtime_summary=lambda: {"role": "bridge", "mode": "read-only"},
            graph_reader=object(),
            logger_name="test.read_only_graph_api",
        )
        operations = app.openapi()["paths"]

        assert set(operations["/graph/related"]) == {"post"}
        assert "/graph/from-text" not in operations
        assert "/graph/cache/clear" not in operations
