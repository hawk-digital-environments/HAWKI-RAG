"""Workspace-wide Python, dependency, and lock ownership contracts."""

from __future__ import annotations

from pathlib import Path
import re
import tomllib


ROOT = Path(__file__).resolve().parents[2]


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
