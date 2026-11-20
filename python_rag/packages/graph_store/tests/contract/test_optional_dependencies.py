"""Workspace-wide prohibition of legacy optional dependency guards."""

from __future__ import annotations

from pathlib import Path


ROOT = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)


def test_production_code_has_no_optional_dependency_import_guards() -> None:
    production_roots = [ROOT / "packages", ROOT / "services"]
    violations = [
        str(path.relative_to(ROOT))
        for root in production_roots
        for path in root.rglob("*.py")
        if "tests" not in path.parts
        if "hawki_rag_resilience" in path.read_text(encoding="utf-8")
    ]
    assert violations == []
