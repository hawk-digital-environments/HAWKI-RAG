"""Static ownership guards for shared Python packages."""

from __future__ import annotations

import ast
from pathlib import Path

PYTHON_RAG = Path(__file__).resolve().parents[2]
PACKAGES = PYTHON_RAG / "packages"


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
    source = PACKAGES / "worker_runtime" / "src" / "hawki_worker_runtime"
    assert _import_roots(source).isdisjoint({"httpx", "requests"})
    assert not (source / "callbacks.py").exists()
    assert not (source / "external_jobs.py").exists()


def test_observability_contains_no_retry_or_transport_dependencies() -> None:
    source = PACKAGES / "observability" / "src" / "hawki_observability"
    assert _import_roots(source).isdisjoint(
        {"httpx", "neo4j", "qdrant_client", "requests", "temporalio"}
    )
    combined = "\n".join(
        path.read_text(encoding="utf-8") for path in source.rglob("*.py")
    )
    assert "retryable" not in combined.lower()


def test_text_processing_has_no_model_or_legacy_preprocessing_module() -> None:
    source = PACKAGES / "text_processing" / "src" / "hawki_rag_text"
    assert _import_roots(source).isdisjoint(
        {"hawki_model_providers", "httpx", "requests"}
    )
    assert not (source / "preprocessing.py").exists()


def test_model_provider_configuration_has_no_request_or_authorization_objects() -> None:
    source = PACKAGES / "model_providers" / "src" / "hawki_model_providers"
    assert not (source / "overrides.py").exists()
    configuration = (source / "configuration.py").read_text(encoding="utf-8")
    assert "authorized_scope" not in configuration
    assert "workflow_input" not in configuration


def test_legacy_resilience_dependency_hub_is_removed() -> None:
    assert not (PACKAGES / "resilience" / "pyproject.toml").exists()
    violations = [
        str(path.relative_to(PYTHON_RAG))
        for root in (PACKAGES, PYTHON_RAG / "services")
        for path in root.rglob("*.py")
        if "hawki_rag_resilience" in path.read_text(encoding="utf-8")
    ]
    assert violations == []
