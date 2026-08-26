"""Static ownership boundaries for vector and graph store packages."""

from __future__ import annotations

import ast
from pathlib import Path
import tomllib


PACKAGES_ROOT = Path(__file__).resolve().parents[2] / "packages"
VECTOR_ROOT = PACKAGES_ROOT / "vector_store"
GRAPH_ROOT = PACKAGES_ROOT / "graph_store"
VECTOR_SOURCE = VECTOR_ROOT / "src" / "hawki_vector_store"
GRAPH_SOURCE = GRAPH_ROOT / "src" / "hawki_graph_store"
BANNED_IMPORT_ROOTS = {
    "api",
    "application",
    "common",
    "domain",
    "infrastructure",
    "services",
    "temporal_rag",
}


def _imports_under(source_root: Path) -> list[tuple[Path, int, str]]:
    imports: list[tuple[Path, int, str]] = []
    for path in source_root.rglob("*.py"):
        tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
        for node in ast.walk(tree):
            module_names: list[str] = []
            if isinstance(node, ast.Import):
                module_names = [alias.name for alias in node.names]
            elif isinstance(node, ast.ImportFrom) and node.module:
                module_names = [node.module]
            imports.extend((path, node.lineno, name) for name in module_names)
    return imports


def test_vector_and_graph_packages_own_distinct_public_modules() -> None:
    vector_expected = {
        "contracts.py",
        "ports.py",
        "transport.py",
        "requests.py",
        "responses.py",
        "payloads.py",
        "collections.py",
        "gateway.py",
        "client.py",
        "search.py",
        "interpretation.py",
        "settings.py",
    }
    graph_expected = {
        "contracts.py",
        "ports.py",
        "transport.py",
        "requests.py",
        "responses.py",
        "client.py",
        "graph.py",
        "normalization.py",
        "settings.py",
        "errors.py",
    }

    vector_actual = {
        str(path.relative_to(VECTOR_SOURCE)) for path in VECTOR_SOURCE.rglob("*.py")
    }
    graph_actual = {
        str(path.relative_to(GRAPH_SOURCE)) for path in GRAPH_SOURCE.rglob("*.py")
    }

    assert vector_expected <= vector_actual
    assert graph_expected <= graph_actual


def test_store_packages_do_not_depend_on_each_other_or_service_code() -> None:
    violations: list[str] = []
    for source_root, other_store in (
        (VECTOR_SOURCE, "hawki_graph_store"),
        (GRAPH_SOURCE, "hawki_vector_store"),
    ):
        for path, line, module_name in _imports_under(source_root):
            root = module_name.split(".", 1)[0]
            if root in BANNED_IMPORT_ROOTS or root == other_store:
                violations.append(
                    f"{path.relative_to(PACKAGES_ROOT)}:{line}:{module_name}"
                )
    assert violations == []


def test_legacy_combined_store_package_has_been_removed() -> None:
    assert not (PACKAGES_ROOT / "stores" / "pyproject.toml").exists()
    production_roots = [
        PACKAGES_ROOT,
        Path(__file__).resolve().parents[2] / "services",
    ]
    violations: list[str] = []
    for root in production_roots:
        for path in root.rglob("*.py"):
            if "__pycache__" in path.parts:
                continue
            source = path.read_text(encoding="utf-8")
            if "hawki_rag_stores" in source:
                violations.append(str(path.relative_to(PACKAGES_ROOT.parent)))
    assert violations == []


def test_store_sources_respect_line_limit() -> None:
    oversized = {
        str(path.relative_to(PACKAGES_ROOT)): len(
            path.read_text(encoding="utf-8").splitlines()
        )
        for source_root in (VECTOR_SOURCE, GRAPH_SOURCE)
        for path in source_root.rglob("*.py")
        if len(path.read_text(encoding="utf-8").splitlines()) > 600
    }
    assert oversized == {}


def test_store_manifests_have_independent_exact_dependencies() -> None:
    vector_project = tomllib.loads(
        (VECTOR_ROOT / "pyproject.toml").read_text(encoding="utf-8")
    )["project"]
    graph_project = tomllib.loads(
        (GRAPH_ROOT / "pyproject.toml").read_text(encoding="utf-8")
    )["project"]

    assert vector_project["name"] == "hawki-vector-store"
    assert vector_project["dependencies"] == [
        "hawki-observability==0.1.0",
        "requests==2.34.2",
    ]
    assert graph_project["name"] == "hawki-graph-store"
    assert graph_project["dependencies"] == [
        "hawki-observability==0.1.0",
        "neo4j==6.2.0",
    ]
    assert "hawki-graph-store==0.1.0" not in vector_project["dependencies"]
    assert "hawki-vector-store==0.1.0" not in graph_project["dependencies"]
    assert vector_project["requires-python"] == "==3.13.14"
    assert graph_project["requires-python"] == "==3.13.14"
    assert all("==" in item for item in vector_project["dependencies"])
    assert all("==" in item for item in graph_project["dependencies"])
