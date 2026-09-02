"""Static ownership guards for the observability package."""

from __future__ import annotations

import ast
from pathlib import Path


PACKAGE_ROOT = Path(__file__).resolve().parents[2]
SOURCE = PACKAGE_ROOT / "src" / "hawki_observability"


def _import_roots(source_root: Path) -> set[str]:
    roots: set[str] = set()
    for path in source_root.rglob("*.py"):
        tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                roots.update(alias.name.split(".", 1)[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                roots.add(node.module.split(".", 1)[0])
    return roots


def test_observability_contains_no_retry_or_transport_dependencies() -> None:
    assert _import_roots(SOURCE).isdisjoint(
        {"httpx", "neo4j", "qdrant_client", "requests", "temporalio"}
    )
    combined = "\n".join(
        path.read_text(encoding="utf-8") for path in SOURCE.rglob("*.py")
    )
    assert "retryable" not in combined.lower()


def test_observability_contains_no_store_specific_event_names() -> None:
    combined = "\n".join(
        path.read_text(encoding="utf-8") for path in SOURCE.rglob("*.py")
    )

    assert "adapter.neo4j" not in combined
    assert "adapter.qdrant" not in combined
