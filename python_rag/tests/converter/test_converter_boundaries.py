"""Architecture boundaries for the converter worker."""

from __future__ import annotations

import ast
import inspect
from pathlib import Path


PYTHON_RAG = Path(__file__).resolve().parents[2]
SOURCE = (
    PYTHON_RAG
    / "services"
    / "hawki_converter_worker"
    / "src"
    / "hawki_converter_worker"
)


def _imports(path: Path) -> list[tuple[int, str]]:
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    imports: list[tuple[int, str]] = []
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            imports.extend((node.lineno, alias.name) for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            imports.append((node.lineno, node.module))
    return imports


def test_converter_core_depends_on_ports_not_transport_or_temporal_adapters() -> None:
    forbidden_roots = {"requests", "temporalio", "hawki_worker_runtime"}
    violations: list[str] = []
    for layer_name in ("application", "conversion", "domain"):
        for path in sorted((SOURCE / layer_name).rglob("*.py")):
            for line, name in _imports(path):
                if (
                    name.split(".", 1)[0] in forbidden_roots
                    or name == "hawki_artifact_store.local"
                    or name.startswith("hawki_converter_worker.adapters")
                ):
                    violations.append(f"{path}:{line}: {name}")
    assert violations == []


def test_converter_transport_dependencies_are_isolated_to_named_adapters() -> None:
    allowed = {
        SOURCE / "adapters" / "direct_extract_client.py": {"requests"},
        SOURCE / "adapters" / "status_callback.py": {"requests"},
    }
    violations: list[str] = []
    for path in sorted(SOURCE.rglob("*.py")):
        for line, name in _imports(path):
            root = name.split(".", 1)[0]
            if root != "requests":
                continue
            if root not in allowed.get(path, set()):
                violations.append(f"{path}:{line}: {name}")
    assert violations == []


def test_converter_has_no_cross_service_imports() -> None:
    forbidden_roots = {
        "hawki_bridge",
        "hawki_indexer_worker",
        "hawki_reranker",
        "hawki_scraper_worker",
        "hawki_workflow_worker",
    }
    violations = [
        f"{path}:{line}: {name}"
        for path in sorted(SOURCE.rglob("*.py"))
        for line, name in _imports(path)
        if name.split(".", 1)[0] in forbidden_roots
    ]
    assert violations == []


def test_converter_has_one_typed_use_case_without_private_forwarding_modules() -> None:
    from hawki_converter_worker.application.source_conversion import (
        execute_source_conversion,
    )

    signature = inspect.signature(execute_source_conversion)
    assert list(signature.parameters) == ["request", "settings", "dependencies"]
    assert signature.return_annotation == "ConvertResult"
    assert not (SOURCE / "conversion" / "service.py").exists()
    assert not (SOURCE / "conversion" / "inspection.py").exists()
    assert not (SOURCE / "conversion" / "markdown.py").exists()
    assert not (SOURCE / "application" / "execution.py").exists()
    assert not (SOURCE / "adapters" / "converter_client.py").exists()


def test_converter_modules_remain_small_enough_to_explain_by_ownership() -> None:
    oversized = {
        str(path.relative_to(PYTHON_RAG)): len(
            path.read_text(encoding="utf-8").splitlines()
        )
        for path in SOURCE.rglob("*.py")
        if len(path.read_text(encoding="utf-8").splitlines()) >= 300
    }
    assert oversized == {}
