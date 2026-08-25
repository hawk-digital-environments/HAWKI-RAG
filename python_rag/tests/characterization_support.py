"""Shared test doubles and dependency adapters for characterization scenarios."""

from __future__ import annotations

from types import SimpleNamespace

from hawki_rag_resilience.optional_imports import import_required_module


def fastapi_http_exception_type() -> type[BaseException]:
    """Return FastAPI's HTTP exception without making it an import-time dependency."""
    return import_required_module(
        "fastapi",
        install_hint="Run `make python-deps` to install the pinned test dependencies.",
    ).HTTPException


def fastapi_test_client_class():
    """Return the same-thread ASGI client used by characterization tests."""
    from asgi_client import ASGITestClient

    return ASGITestClient


def requests_http_error_type() -> type[BaseException]:
    """Return Requests' HTTP error through the optional-dependency boundary."""
    return import_required_module(
        "requests",
        install_hint="Run `make python-deps` to install the pinned test dependencies.",
    ).exceptions.HTTPError


def authorized_query_scope(*, graph_enabled: bool = False) -> SimpleNamespace:
    """Build the trusted dataset scope Laravel would pass to Python retrieval."""
    return SimpleNamespace(
        dataset_id="dataset-a",
        qdrant_collection="hawki_dataset_a",
        neo4j_namespace="hawki_dataset_a",
        graph_enabled=graph_enabled,
    )


class ScopedQdrantStub:
    """Record the collection selected by a dataset-scoped query scenario."""

    def __init__(self) -> None:
        self.collection = ""

    def select_scoped_collection(self, collection: str) -> None:
        self.collection = collection
