"""Contract tests for hawki-rag-retrieval package boundaries."""

from __future__ import annotations

import ast
from pathlib import Path


# Stable repository roots derived from this test file's location.
PYTHON_RAG = Path(__file__).resolve().parents[3]
REPO_ROOT = PYTHON_RAG.parent
RETRIEVAL_SRC = PYTHON_RAG / "packages" / "retrieval" / "src" / "hawki_rag_retrieval"
BRIDGE_SRC = PYTHON_RAG / "services" / "hawki_bridge" / "src" / "hawki_bridge"
BACKEND_SRC = REPO_ROOT / "python_backend" / "src"

# Fail closed: both source trees must exist
assert BRIDGE_SRC.exists(), f"Expected bridge source tree: {BRIDGE_SRC}"
assert BACKEND_SRC.exists(), f"Expected backend source tree: {BACKEND_SRC}"


def _imports_from_file(path: Path) -> set[str]:
    """Extract all import module names from a Python file."""
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    imports: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            imports.update(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            imports.add(node.module)
    return imports


def test_retrieval_imports_no_forbidden_frameworks() -> None:
    """The retrieval package must not import HTTP/framework dependencies."""
    forbidden = {"fastapi", "starlette", "psycopg", "sqlalchemy", "alembic", "django"}
    found: dict[str, list[tuple[Path, int]]] = {}

    for py_file in RETRIEVAL_SRC.rglob("*.py"):
        if "__pycache__" in str(py_file):
            continue
        for imp in _imports_from_file(py_file):
            root = imp.split(".", 1)[0]
            if root in forbidden:
                found.setdefault(root, []).append((py_file, 0))

    assert not found, f"Forbidden imports found: {found}"


def test_retrieval_imports_no_service_packages() -> None:
    """The retrieval package must not import other service packages."""
    forbidden = {
        "hawki_bridge",
        "hawki_indexer_worker",
        "hawki_workflow_worker",
        "hawki_converter_worker",
        "hawki_scraper_worker",
        "hawki_reranker",
    }
    found: dict[str, list[Path]] = {}

    for py_file in RETRIEVAL_SRC.rglob("*.py"):
        if "__pycache__" in str(py_file):
            continue
        for imp in _imports_from_file(py_file):
            root = imp.split(".", 1)[0]
            if root in forbidden:
                found.setdefault(root, []).append(py_file)

    assert not found, f"Service package imports found: {found}"


def test_retrieval_app_code_imports_no_store_packages_directly() -> None:
    """Application/query code must not directly import store packages.

    Those may only appear in adapters/composition.
    """
    store_packages = {"hawki_vector_store", "hawki_graph_store", "neo4j"}
    violations: list[str] = []

    # Scan ALL non-adapter modules in the retrieval package.
    for py_file in RETRIEVAL_SRC.rglob("*.py"):
        if "__pycache__" in str(py_file):
            continue
        # Skip adapter directory entirely.
        try:
            if py_file.is_relative_to(RETRIEVAL_SRC / "adapters"):
                continue
        except ValueError:
            pass

        for imp in _imports_from_file(py_file):
            root = imp.split(".", 1)[0]
            if root in store_packages:
                violations.append(f"{py_file}: imports {imp}")

    assert not violations, (
        f"Direct store package imports in non-adapter code: {violations}"
    )


def test_hawki_rag_text_terms_only_through_lexical_seam() -> None:
    """hawki_rag_text.terms must only be imported through the lexical seam."""
    violations: list[str] = []
    lexical_seam = RETRIEVAL_SRC / "query" / "lexical.py"

    for py_file in RETRIEVAL_SRC.rglob("*.py"):
        if py_file == lexical_seam:
            continue
        for imp in _imports_from_file(py_file):
            if imp == "hawki_rag_text.terms" or imp.startswith("hawki_rag_text.terms."):
                violations.append(f"{py_file}: imports {imp}")

    assert not violations, (
        f"Direct hawki_rag_text.terms imports outside lexical seam: {violations}"
    )


def test_retrieval_source_files_under_line_limit() -> None:
    """Retrieval source files must respect the repo's source-file line limit."""
    limit = 600
    violations: list[str] = []

    for py_file in RETRIEVAL_SRC.rglob("*.py"):
        if "__pycache__" in str(py_file):
            continue
        line_count = len(py_file.read_text(encoding="utf-8").splitlines())
        if line_count >= limit:
            violations.append(f"{py_file}: {line_count} lines (limit: {limit})")

    assert not violations, f"Files exceeding line limit: {violations}"


def test_single_implementation_per_retrieval_adapter() -> None:
    """Exactly one implementation of each retrieval adapter must exist."""
    adapters_dir = RETRIEVAL_SRC / "adapters"
    assert adapters_dir.exists(), (
        f"Expected adapters directory not found: {adapters_dir}"
    )

    expected = {
        "qdrant_reader.py",
        "qdrant_search_policy.py",
        "neo4j_reader.py",
        "reranker_client.py",
    }
    actual = {
        f.name for f in adapters_dir.iterdir() if f.is_file() and f.suffix == ".py"
    }
    actual.discard("__init__.py")

    missing = expected - actual
    extra = actual - expected

    assert not missing, f"Missing adapter implementations: {missing}"
    assert not extra, f"Unexpected adapter files: {extra}"


def test_no_duplicate_adapters_in_bridge() -> None:
    """No duplicate retrieval adapter implementations may remain under hawki_bridge."""
    violations: list[str] = []
    for py_file in BRIDGE_SRC.rglob("*.py"):
        if py_file.name in {
            "qdrant_reader.py",
            "qdrant_search_policy.py",
            "neo4j_reader.py",
            "reranker_client.py",
        }:
            violations.append(f"Duplicate adapter in bridge: {py_file}")

    assert not violations, f"Duplicate adapters found in bridge: {violations}"


def test_no_duplicate_adapters_in_python_backend() -> None:
    """No duplicate retrieval adapter implementations may remain under python_backend."""
    violations: list[str] = []
    for py_file in BACKEND_SRC.rglob("*.py"):
        if py_file.name in {
            "qdrant_reader.py",
            "qdrant_search_policy.py",
            "neo4j_reader.py",
            "reranker_client.py",
        }:
            violations.append(f"Duplicate adapter in python_backend: {py_file}")

    assert not violations, f"Duplicate adapters found in python_backend: {violations}"


def test_retrieval_module_imports_runtime() -> None:
    """Runtime import test - the retrieval package must be importable."""
    import hawki_rag_retrieval
    import hawki_rag_retrieval.query
    import hawki_rag_retrieval.adapters
    import hawki_rag_retrieval.ports
    import hawki_rag_retrieval.dependencies
    import hawki_rag_retrieval.errors

    # Verify key exports
    assert hasattr(hawki_rag_retrieval, "execute_authorized_query")
    assert hasattr(hawki_rag_retrieval, "QueryDependencies")
    assert hasattr(hawki_rag_retrieval.adapters, "QdrantReader")
    assert hasattr(hawki_rag_retrieval.adapters, "Neo4jReader")
    assert hasattr(hawki_rag_retrieval.adapters, "rerank_hits")
    assert hasattr(hawki_rag_retrieval, "__all__")


def test_historical_logger_string_allowed() -> None:
    """The historical logger STRING containing 'hawki_bridge' is allowed.

    It must not be an actual import of hawki_bridge.
    """
    # Import all moved modules that have historical logger names
    import hawki_rag_retrieval.query.execution as exec_mod
    import hawki_rag_retrieval.query.fallback as fallback_mod
    import hawki_rag_retrieval.query.rewrite as rewrite_mod
    import hawki_rag_retrieval.adapters.qdrant_reader as qdrant_reader_mod
    import hawki_rag_retrieval.adapters.qdrant_search_policy as qdrant_search_mod
    import hawki_rag_retrieval.adapters.neo4j_reader as neo4j_mod
    import hawki_rag_retrieval.adapters.reranker_client as reranker_mod

    # These are the historical logger names - they contain "hawki_bridge" as a STRING
    assert exec_mod.logger.name == "hawki_bridge.application.query.execution"
    assert fallback_mod.logger.name == "hawki_bridge.application.query.fallback"
    assert rewrite_mod.logger.name == "hawki_bridge.application.query.rewrite"
    assert qdrant_reader_mod.logger.name == "hawki_bridge.adapters.qdrant_reader"
    assert qdrant_search_mod.logger.name == "hawki_bridge.adapters.qdrant_search_policy"
    assert neo4j_mod.logger.name == "hawki_bridge.adapters.neo4j_reader"
    assert reranker_mod.logger.name == "hawki_bridge.adapters.reranker_client"


def test_bridge_source_imports_no_store_packages_directly() -> None:
    """The bridge must not directly import store packages.

    Storage implementations must remain behind the shared retrieval package.
    """
    store_packages = {"hawki_vector_store", "hawki_graph_store", "neo4j"}
    violations: list[str] = []

    for py_file in BRIDGE_SRC.rglob("*.py"):
        if "__pycache__" in str(py_file):
            continue
        for imp in _imports_from_file(py_file):
            root = imp.split(".", 1)[0]
            if root in store_packages:
                violations.append(f"{py_file}: imports {imp}")

    assert not violations, f"Bridge directly imports store packages: {violations}"


if __name__ == "__main__":
    import pytest

    pytest.main([__file__, "-v"])
