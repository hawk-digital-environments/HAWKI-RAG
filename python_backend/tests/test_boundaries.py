"""Boundary tests for the python_backend source tree."""

from __future__ import annotations

import ast
from pathlib import Path

BACKEND_SRC = Path(__file__).resolve().parents[1] / "src"
QUERY_MODULE = BACKEND_SRC / "hawki_backend" / "api" / "v1" / "query.py"
PARITY_DIR = Path(__file__).resolve().parent / "parity"
PARITY_FIXTURE = PARITY_DIR / "fixtures" / "laravel_query_parity.json"

# Fail closed if the source tree moves: an empty scan must not pass vacuously.
assert BACKEND_SRC.is_dir(), f"Expected backend source tree: {BACKEND_SRC}"
assert QUERY_MODULE.is_file(), f"Expected query module: {QUERY_MODULE}"
assert PARITY_DIR.is_dir(), f"Expected parity test package: {PARITY_DIR}"
assert PARITY_FIXTURE.is_file(), f"Expected parity fixture: {PARITY_FIXTURE}"

# The parity fixture is test-only. Production source must never reach for it.
_PARITY_REFERENCES = ("tests/parity", "laravel_query_parity", "parity")

# Names that belong to the shared retrieval implementation. Defining one of
# them in python_backend would mean the use case was copied instead of called.
_RETRIEVAL_NAMES = frozenset(
    {
        "execute_authorized_query",
        "build_scoped_query_filters",
        "rerank_and_filter_hits",
    }
)

# The backend owns `hawki_backend`; the legacy application owns `public`.
_LEGACY_TABLE_REFERENCES = ("public.datasets", "public.dataset_grants")


def _backend_sources() -> list[Path]:
    """Return the backend source files, failing closed on an empty tree."""

    sources = sorted(
        path for path in BACKEND_SRC.rglob("*.py") if "__pycache__" not in path.parts
    )
    assert sources, f"No Python sources found under {BACKEND_SRC}"
    return sources


def _imports(path: Path) -> set[str]:
    """Extract all imported module names from a Python file."""

    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    imports: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            imports.update(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            imports.add(node.module)
    return imports


def test_python_backend_does_not_import_the_bridge_service() -> None:
    """The query API must execute retrieval in-process, not through the bridge."""

    violations: list[str] = []
    for path in _backend_sources():
        for imported in _imports(path):
            if imported == "hawki_bridge" or imported.startswith("hawki_bridge."):
                violations.append(f"{path}: imports {imported}")

    assert not violations, f"python_backend imports hawki_bridge: {violations}"


def test_python_backend_uses_the_shared_retrieval_package() -> None:
    """The query API must call the shared retrieval use case."""

    imports = _imports(QUERY_MODULE)

    assert any(
        name == "hawki_rag_retrieval" or name.startswith("hawki_rag_retrieval.")
        for name in imports
    )


def test_python_backend_does_not_read_legacy_public_tables() -> None:
    """The backend must never address the application's `public` tables."""

    violations = [
        str(path)
        for path in _backend_sources()
        if any(
            reference in path.read_text(encoding="utf-8")
            for reference in _LEGACY_TABLE_REFERENCES
        )
    ]

    assert not violations, (
        f"python_backend references legacy public tables: {violations}"
    )


def test_python_backend_does_not_reimplement_retrieval() -> None:
    """Retrieval logic must be imported from the shared package, not copied."""

    violations: list[str] = []
    for path in _backend_sources():
        tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
        for node in ast.walk(tree):
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
                if node.name in _RETRIEVAL_NAMES:
                    violations.append(f"{path}:{node.lineno} defines {node.name}")

    assert not violations, f"python_backend reimplements retrieval: {violations}"


def test_python_backend_sources_do_not_reference_parity_artifacts() -> None:
    """The parity fixture is test-only and must never be read at request time."""

    violations: list[str] = []
    for path in _backend_sources():
        text = path.read_text(encoding="utf-8").lower()
        for reference in _PARITY_REFERENCES:
            if reference in text:
                violations.append(f"{path}: references {reference}")

    assert not violations, (
        f"python_backend production sources depend on parity artifacts: {violations}"
    )


def test_python_backend_does_not_import_test_modules() -> None:
    """Production code must not import test packages or parity helpers."""

    violations: list[str] = []
    for path in _backend_sources():
        for imported in _imports(path):
            if imported == "tests" or imported.startswith("tests."):
                violations.append(f"{path}: imports {imported}")
            if "parity" in imported:
                violations.append(f"{path}: imports {imported}")

    assert not violations, f"python_backend imports test code: {violations}"


def test_python_backend_does_not_address_legacy_public_dataset_tables() -> None:
    """Both legacy dataset table names are checked independently."""

    for reference in _LEGACY_TABLE_REFERENCES:
        violations = [
            str(path)
            for path in _backend_sources()
            if reference in path.read_text(encoding="utf-8")
        ]

        assert not violations, f"python_backend references {reference}: {violations}"
