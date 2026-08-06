"""Production boundaries for the read-only bridge service."""

from __future__ import annotations

import ast
from pathlib import Path
from types import SimpleNamespace
import tomllib

from hawki_bridge.factory import build_app
from hawki_bridge.settings import load_settings


PYTHON_RAG = Path(__file__).resolve().parents[2]
SERVICE = PYTHON_RAG / "services" / "hawki_bridge"
SOURCE = SERVICE / "src" / "hawki_bridge"


def test_bridge_registers_only_query_graph_read_health_config_and_temporal_routes() -> (
    None
):
    service = SimpleNamespace(runtime_summary=lambda: {"status": "ready"})
    app = build_app(settings=load_settings({}), service=service)
    routes = {
        (method.upper(), path)
        for path, operations in app.openapi()["paths"].items()
        for method in operations
    }

    assert ("POST", "/query") in routes
    assert ("POST", "/graph/related") in routes
    assert ("GET", "/health") in routes
    assert ("GET", "/config") in routes
    assert ("POST", "/temporal/workflows/ingest") in routes
    assert ("POST", "/temporal/schedules/ingest") in routes
    assert ("POST", "/temporal/schedules/delete") in routes
    assert ("POST", "/temporal/workflows/cancel") in routes
    assert not any(path == "/ingest" for _method, path in routes)
    assert not any(path.startswith("/documents/") for _method, path in routes)
    assert not any(path == "/graph/from-text" for _method, path in routes)


def test_bridge_has_no_indexer_database_or_cross_service_imports() -> None:
    forbidden_roots = {
        "api",
        "application",
        "common",
        "domain",
        "infrastructure",
        "psycopg",
        "hawki_scraper_worker",
        "hawki_converter_worker",
        "hawki_indexer_worker",
        "hawki_reranker",
        "hawki_workflow_worker",
    }
    violations: list[str] = []
    for path in sorted(SOURCE.rglob("*.py")):
        tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
        for node in ast.walk(tree):
            names: list[str] = []
            if isinstance(node, ast.Import):
                names = [alias.name for alias in node.names]
            elif isinstance(node, ast.ImportFrom) and node.module:
                names = [node.module]
            for name in names:
                if name.split(".", 1)[0] in forbidden_roots:
                    violations.append(f"{path}:{node.lineno}: {name}")
    assert violations == []


def test_bridge_manifest_and_dockerfile_are_production_pinned() -> None:
    manifest = tomllib.loads((SERVICE / "pyproject.toml").read_text(encoding="utf-8"))
    assert manifest["project"]["requires-python"] == "==3.13.14"
    assert all("==" in dependency for dependency in manifest["project"]["dependencies"])
    assert manifest["build-system"]["requires"] == ["uv_build==0.11.26"]

    dockerfile = (PYTHON_RAG / "Dockerfile").read_text(encoding="utf-8")
    assert dockerfile.count("FROM ") >= 3
    assert (
        "neunerlei/python-nginx:3.13@sha256:"
        "05b581371d0d9faef2f160079acd0a4e18503b99d47b995825205e71fd13c136"
    ) in dockerfile
    assert "AS bridge-builder" in dockerfile
    assert "AS bridge-runtime" in dockerfile
    assert "UV_PYTHON=/usr/local/bin/python3.13" in dockerfile
    assert "UV_PYTHON_DOWNLOADS=never" in dockerfile
    assert "--frozen --no-dev --no-editable" in dockerfile
    assert "COPY python_rag /app" not in dockerfile
    assert "UV_PROJECT_ENVIRONMENT=/opt/venv" not in dockerfile
    assert (
        "/workspace/python_rag/.venv/lib/python3.13/site-packages/ "
        "/opt/venv/lib/python3.13/site-packages/"
    ) in dockerfile
    bridge_runtime = dockerfile.split("AS bridge-runtime", maxsplit=1)[1]
    assert "PYTHON_APP_MODULE=hawki_bridge.main:app" in bridge_runtime
    assert "GUNICORN_WORKER_CLASS=uvicorn.workers.UvicornWorker" in bridge_runtime
    assert "GUNICORN_WORKERS=1" in bridge_runtime
    assert "USER " not in bridge_runtime
    assert "tests" not in "\n".join(
        line for line in dockerfile.splitlines() if line.startswith("COPY ")
    )


def test_bridge_source_respects_the_handwritten_line_limit() -> None:
    assert {
        str(path.relative_to(PYTHON_RAG)): len(
            path.read_text(encoding="utf-8").splitlines()
        )
        for path in SOURCE.rglob("*.py")
        if len(path.read_text(encoding="utf-8").splitlines()) >= 600
    } == {}
