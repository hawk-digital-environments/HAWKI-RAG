"""Packaging, image, and import-boundary assertions for the scraper service."""

from __future__ import annotations

import ast
from pathlib import Path
import tomllib

from temporalio import activity

from hawki_scraper_worker.activities.scrape import scrape_source


PYTHON_RAG = Path(__file__).resolve().parents[2]
SERVICE_ROOT = PYTHON_RAG / "services" / "hawki_scraper_worker"


def test_scrape_activity_keeps_its_registered_temporal_name() -> None:
    definition = activity._Definition.must_from_callable(scrape_source)
    assert definition.name == "scrape_source"


def test_service_is_exactly_pinned_and_imports_no_legacy_or_other_service() -> None:
    pyproject = tomllib.loads(
        (SERVICE_ROOT / "pyproject.toml").read_text(encoding="utf-8")
    )
    assert pyproject["project"]["requires-python"] == "==3.13.11"
    assert pyproject["project"]["dependencies"] == [
        "hawki-artifact-store==0.1.0",
        "hawki-rag-contracts==0.1.0",
        "hawki-rag-resilience==0.1.0",
        "hawki-worker-runtime==0.1.0",
        "temporalio==1.30.0",
    ]
    assert pyproject["build-system"]["requires"] == ["uv_build==0.11.26"]

    forbidden_roots = {
        "api",
        "application",
        "fastapi",
        "hawki_bridge",
        "hawki_converter_worker",
        "hawki_indexer_worker",
        "hawki_reranker",
        "hawki_workflow_worker",
        "infrastructure",
        "psycopg",
        "temporal_rag",
    }
    source_root = SERVICE_ROOT / "src" / "hawki_scraper_worker"
    for source in source_root.rglob("*.py"):
        contents = source.read_text(encoding="utf-8")
        imports: set[str] = set()
        for node in ast.walk(ast.parse(contents)):
            if isinstance(node, ast.Import):
                imports.update(alias.name for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module)
        roots = {name.split(".", 1)[0] for name in imports}
        assert not roots & forbidden_roots, source
        assert len(contents.splitlines()) < 600, source


def test_dockerfile_uses_exact_multistage_nonroot_allowlisted_build() -> None:
    dockerfile = (SERVICE_ROOT / "Dockerfile").read_text(encoding="utf-8")

    assert dockerfile.count("FROM python:3.13.11-slim") == 2
    assert "python:3.13.11-slim@sha256:" not in dockerfile
    assert "FROM ghcr.io/astral-sh/uv:0.11.26 AS uv" in dockerfile
    assert "ghcr.io/astral-sh/uv:0.11.26@sha256:" not in dockerfile
    assert "uv sync" in dockerfile
    assert "--frozen" in dockerfile
    assert "--no-dev" in dockerfile
    assert "--no-editable" in dockerfile
    assert "--package hawki-scraper-worker" in dockerfile
    assert "COPY python_rag /app" not in dockerfile
    assert "COPY python_rag/tests" not in dockerfile
    assert "USER hawki-rag" in dockerfile
