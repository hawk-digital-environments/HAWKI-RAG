"""Contract tests for hawki-rag-retrieval package importability."""

from __future__ import annotations

import inspect

from fastapi import FastAPI


def test_retrieval_package_imports_successfully() -> None:
    """The retrieval package must be importable without side effects."""
    import hawki_rag_retrieval

    assert hawki_rag_retrieval.__version__ == "0.1.0"


def test_retrieval_public_api_exists() -> None:
    """The public retrieval callable and dependencies must be accessible."""
    from hawki_rag_retrieval import (
        QueryDependencies,
        execute_authorized_query,
    )
    from hawki_rag_retrieval.adapters import (
        Neo4jReader,
        QdrantReader,
        rerank_hits,
    )
    from hawki_rag_retrieval.ports import (
        GraphSearchPort,
        ModelProvider,
        RerankHitsPort,
        VectorSearchPort,
    )

    # Verify the main callable exists and is callable
    assert callable(execute_authorized_query)
    assert inspect.signature(execute_authorized_query).parameters.keys() >= {
        "request",
        "dependencies",
    }

    # Verify QueryDependencies exists
    assert QueryDependencies is not None

    # Verify key types are re-exported
    assert ModelProvider is not None
    assert VectorSearchPort is not None
    assert GraphSearchPort is not None
    assert RerankHitsPort is not None

    # Verify adapter classes exist
    assert QdrantReader is not None
    assert Neo4jReader is not None
    assert callable(rerank_hits)


def test_retrieval_import_registers_no_fastapi_routes() -> None:
    """Importing the retrieval package must not register any FastAPI routes."""
    # Create a fresh FastAPI app
    app = FastAPI()

    # Count routes before import
    routes_before = len(app.routes)

    # Import the retrieval package
    import hawki_rag_retrieval  # noqa: F401

    # Count routes after import
    routes_after = len(app.routes)

    # The retrieval package must not add any routes to the app
    assert routes_after == routes_before, (
        "Importing hawki_rag_retrieval must not register FastAPI routes"
    )


def test_openapi_remains_health_only() -> None:
    """The backend OpenAPI schema must remain exactly /v1/health (no query route)."""
    from hawki_backend.main import app

    openapi = app.openapi()
    paths = set(openapi["paths"].keys())

    # Only health endpoint should exist
    assert paths == {"/v1/health"}, f"Unexpected paths: {paths}"

    # Verify no query route exists
    assert "/query" not in str(paths), "Query route must not exist in OpenAPI"
    assert "/v1/query" not in str(paths), "Query route must not exist in OpenAPI"
