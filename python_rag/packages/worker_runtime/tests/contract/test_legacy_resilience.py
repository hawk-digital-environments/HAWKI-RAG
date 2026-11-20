"""Cross-workspace ownership guards for retired shared packages."""

from __future__ import annotations

from pathlib import Path

PYTHON_RAG = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)
PACKAGES = PYTHON_RAG / "packages"


def test_legacy_resilience_dependency_hub_is_removed() -> None:
    assert not (PACKAGES / "resilience" / "pyproject.toml").exists()
    violations = [
        str(path.relative_to(PYTHON_RAG))
        for root in (PACKAGES, PYTHON_RAG / "services")
        for path in root.rglob("*.py")
        if "tests" not in path.parts
        if "hawki_rag_resilience" in path.read_text(encoding="utf-8")
    ]
    assert violations == []
