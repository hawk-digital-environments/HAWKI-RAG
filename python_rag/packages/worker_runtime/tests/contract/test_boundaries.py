"""Static ownership guards for the worker-runtime package."""

from __future__ import annotations

import ast
from pathlib import Path


PACKAGE_ROOT = Path(__file__).resolve().parents[2]
SOURCE = PACKAGE_ROOT / "src" / "hawki_worker_runtime"


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


def test_worker_runtime_contains_no_http_clients_or_pipeline_protocols() -> None:
    assert _import_roots(SOURCE).isdisjoint({"httpx", "requests"})
    assert not (SOURCE / "callbacks.py").exists()
    assert not (SOURCE / "external_jobs.py").exists()
