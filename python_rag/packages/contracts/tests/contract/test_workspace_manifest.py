"""Workspace-wide Python, dependency, and lock ownership contracts."""

from __future__ import annotations

import ast
from pathlib import Path
import re
import tomllib


ROOT = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)


def _members() -> list[Path]:
    return sorted((ROOT / "packages").glob("*/pyproject.toml")) + sorted(
        (ROOT / "services").glob("*/pyproject.toml")
    )


def test_workspace_has_one_root_lock_and_no_nested_workspace_metadata() -> None:
    manifests = [ROOT / "pyproject.toml", *_members()]
    workspace_owners = [
        path
        for path in manifests
        if "workspace"
        in tomllib.loads(path.read_text(encoding="utf-8")).get("tool", {}).get("uv", {})
    ]

    assert workspace_owners == [ROOT / "pyproject.toml"]
    assert len(_members()) == 16
    assert list(ROOT.rglob("uv.lock")) == [ROOT / "uv.lock"]
    assert list((ROOT / "packages").rglob(".python-version")) == []
    assert list((ROOT / "services").rglob(".python-version")) == []
    assert (ROOT / ".python-version").read_text(encoding="utf-8") == "3.13.14\n"


def test_every_member_uses_exact_python_build_and_direct_dependency_pins() -> None:
    for path in _members():
        manifest = tomllib.loads(path.read_text(encoding="utf-8"))
        assert manifest["project"]["requires-python"] == "==3.13.14", path
        assert manifest["build-system"] == {
            "requires": ["uv_build==0.11.26"],
            "build-backend": "uv_build",
        }, path

        dependency_sets = [manifest["project"].get("dependencies", [])]
        dependency_sets.extend(manifest.get("dependency-groups", {}).values())
        dependency_sets.extend(
            manifest["project"].get("optional-dependencies", {}).values()
        )
        for dependencies in dependency_sets:
            for dependency in dependencies:
                assert "==" in dependency, (path, dependency)


def test_vcs_source_and_workspace_override_are_immutable() -> None:
    root_manifest = tomllib.loads((ROOT / "pyproject.toml").read_text(encoding="utf-8"))
    assert root_manifest["tool"]["uv"]["override-dependencies"] == [
        "mineru[pipeline]==3.4.4"
    ]

    indexer = tomllib.loads(
        (ROOT / "services" / "hawki_indexer_worker" / "pyproject.toml").read_text(
            encoding="utf-8"
        )
    )
    source = indexer["tool"]["uv"]["sources"]["lightrag-hku"]
    assert source["git"] == "https://github.com/HKUDS/LightRAG.git"
    assert re.fullmatch(r"[0-9a-f]{40}", source["rev"])


def test_package_dependencies_are_limited_to_intentional_observability_edges() -> None:
    package_manifests = sorted((ROOT / "packages").glob("*/pyproject.toml"))
    projects = {
        path: tomllib.loads(path.read_text(encoding="utf-8"))["project"]
        for path in package_manifests
    }
    distribution_names = {project["name"] for project in projects.values()}
    declared_edges = {
        (project["name"], dependency.split("==", 1)[0].split("[", 1)[0])
        for project in projects.values()
        for dependency in project.get("dependencies", [])
        if dependency.split("==", 1)[0].split("[", 1)[0] in distribution_names
    }

    module_owners = {
        module_root.name: project["name"]
        for path, project in projects.items()
        for module_root in (path.parent / "src").iterdir()
        if module_root.is_dir()
    }
    imported_edges: set[tuple[str, str]] = set()
    for path, project in projects.items():
        for source in (path.parent / "src").rglob("*.py"):
            tree = ast.parse(source.read_text(encoding="utf-8"), filename=str(source))
            for node in ast.walk(tree):
                imported_modules: list[str] = []
                if isinstance(node, ast.Import):
                    imported_modules = [alias.name for alias in node.names]
                elif isinstance(node, ast.ImportFrom) and node.module:
                    imported_modules = [node.module]
                for module in imported_modules:
                    imported_owner = module_owners.get(module.split(".", 1)[0])
                    if imported_owner and imported_owner != project["name"]:
                        imported_edges.add((project["name"], imported_owner))

    expected_edges = {
        ("hawki-graph-store", "hawki-observability"),
        ("hawki-vector-store", "hawki-observability"),
    }
    assert declared_edges == expected_edges
    assert imported_edges == expected_edges
