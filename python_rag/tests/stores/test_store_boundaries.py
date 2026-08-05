"""Static packaging boundaries for the stores workspace member."""

from __future__ import annotations

import ast
from pathlib import Path
import tomllib


STORE_ROOT = Path(__file__).resolve().parents[2] / "packages" / "stores"
SOURCE_ROOT = STORE_ROOT / "src" / "hawki_rag_stores"
BANNED_IMPORT_ROOTS = {
    "api",
    "application",
    "common",
    "domain",
    "infrastructure",
    "services",
    "temporal_rag",
}


def test_expected_store_modules_exist() -> None:
    expected = {
        "qdrant/transport.py",
        "qdrant/requests.py",
        "qdrant/responses.py",
        "qdrant/payloads.py",
        "qdrant/collections.py",
        "qdrant/gateway.py",
        "qdrant/client.py",
        "qdrant/search.py",
        "qdrant/strategies.py",
        "qdrant/interpretation.py",
        "qdrant/settings.py",
        "neo4j/transport.py",
        "neo4j/requests.py",
        "neo4j/responses.py",
        "neo4j/client.py",
        "neo4j/graph.py",
        "neo4j/normalization.py",
        "neo4j/text.py",
        "neo4j/traversal.py",
    }
    actual = {str(path.relative_to(SOURCE_ROOT)) for path in SOURCE_ROOT.rglob("*.py")}
    assert expected <= actual


def test_store_package_does_not_import_legacy_or_service_packages() -> None:
    violations: list[str] = []
    for path in SOURCE_ROOT.rglob("*.py"):
        tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
        for node in ast.walk(tree):
            imported: list[str] = []
            if isinstance(node, ast.Import):
                imported = [alias.name for alias in node.names]
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported = [node.module]
            for module_name in imported:
                if module_name.split(".", 1)[0] in BANNED_IMPORT_ROOTS:
                    violations.append(
                        f"{path.relative_to(SOURCE_ROOT)}:{node.lineno}:{module_name}"
                    )
    assert violations == []


def test_store_sources_respect_line_limit() -> None:
    oversized = {
        str(path.relative_to(SOURCE_ROOT)): len(
            path.read_text(encoding="utf-8").splitlines()
        )
        for path in SOURCE_ROOT.rglob("*.py")
        if len(path.read_text(encoding="utf-8").splitlines()) > 600
    }
    assert oversized == {}


def test_store_manifest_uses_exact_versions() -> None:
    project = tomllib.loads((STORE_ROOT / "pyproject.toml").read_text(encoding="utf-8"))
    assert project["project"]["requires-python"] == "==3.13.11"
    assert project["build-system"]["requires"] == ["uv_build==0.11.26"]
    dependencies = project["project"]["dependencies"]
    assert dependencies == [
        "hawki-rag-resilience==0.1.0",
        "hawki-rag-text==0.1.0",
        "neo4j==5.23.0",
        "requests==2.34.2",
    ]
    assert all("==" in dependency for dependency in dependencies)
