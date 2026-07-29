"""Shared test doubles and optional dependency adapters for characterization scenarios."""

from __future__ import annotations

import sys
import types
from types import SimpleNamespace

from common.optional_imports import import_required_module


def fastapi_http_exception_type() -> type[BaseException]:
    """Return FastAPI's HTTP exception without making it an import-time dependency."""
    return import_required_module(
        "fastapi",
        install_hint="Install python_rag/requirements.txt to run API characterization tests.",
    ).HTTPException


def fastapi_test_client_class():
    """Return FastAPI's test client with the suite's normal dependency guidance."""
    return import_required_module(
        "fastapi.testclient",
        install_hint="Install python_rag/requirements.txt to run API characterization tests.",
    ).TestClient


def neo4j_exceptions_module():
    """Return Neo4j exceptions through the optional-dependency boundary."""
    return import_required_module(
        "neo4j",
        install_hint="Install python_rag/requirements.txt to run Neo4j characterization tests.",
    ).exceptions


def requests_http_error_type() -> type[BaseException]:
    """Return Requests' HTTP error through the optional-dependency boundary."""
    return import_required_module(
        "requests",
        install_hint="Install python_rag/requirements.txt to run Qdrant characterization tests.",
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


def install_optional_dependency_stubs() -> None:
    """Install the minimal Neo4j surface used by tests when the driver is absent."""
    if "neo4j" in sys.modules:
        return

    neo4j_module = types.ModuleType("neo4j")

    class Neo4jError(Exception):
        """Stand in for the driver's base error during isolated tests."""

    class GraphDatabase:
        """Prevent characterization tests from creating a real Neo4j driver."""

        @staticmethod
        def driver(*args, **kwargs):
            raise RuntimeError("GraphDatabase.driver should not be called in characterization tests")

    neo4j_module.GraphDatabase = GraphDatabase
    neo4j_module.exceptions = types.SimpleNamespace(Neo4jError=Neo4jError)
    sys.modules["neo4j"] = neo4j_module
