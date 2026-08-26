"""Packaging and import-boundary assertions for the scraper service."""

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
    assert pyproject["project"]["requires-python"] == "==3.13.14"
    assert pyproject["project"]["dependencies"] == [
        "hawki-artifact-store==0.1.0",
        "hawki-external-jobs==0.1.0",
        "hawki-observability==0.1.0",
        "hawki-pipeline-callbacks==0.1.0",
        "hawki-rag-contracts==0.1.0",
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
