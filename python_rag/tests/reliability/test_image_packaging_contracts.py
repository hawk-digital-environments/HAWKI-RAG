"""Static contracts for the six production Python role images."""

from __future__ import annotations

from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
PYTHON_ROOT = REPOSITORY_ROOT / "python_rag"
SERVICE_NAMES = (
    "hawki_bridge",
    "hawki_workflow_worker",
    "hawki_scraper_worker",
    "hawki_converter_worker",
    "hawki_indexer_worker",
    "hawki_reranker",
)


def _dockerfile(service: str) -> str:
    return (PYTHON_ROOT / "services" / service / "Dockerfile").read_text(
        encoding="utf-8"
    )


def test_exactly_six_service_owned_dockerfiles_use_pinned_multistage_bases() -> None:
    dockerfiles = sorted((PYTHON_ROOT / "services").glob("*/Dockerfile"))

    assert [path.parent.name for path in dockerfiles] == sorted(SERVICE_NAMES)
    for path in dockerfiles:
        source = path.read_text(encoding="utf-8")
        assert "FROM ghcr.io/astral-sh/uv:0.11.26 AS uv" in source
        assert "ghcr.io/astral-sh/uv:0.11.26@sha256:" not in source
        assert source.count("FROM python:3.13.11-slim") == 2
        assert "python:3.13.11-slim@sha256:" not in source
        assert " AS builder" in source
        assert " AS runtime" in source


def test_runtime_copies_cleaned_system_site_packages_not_a_virtualenv() -> None:
    for service in SERVICE_NAMES:
        source = _dockerfile(service)
        runtime = source.split(" AS runtime", maxsplit=1)[1]

        assert "/site-packages /usr/local/lib/python3.13/site-packages" in runtime
        assert "COPY --from=builder" in runtime
        assert "/.venv /" not in runtime
        assert "ENV PATH=" not in runtime
        assert "USER " in runtime
        assert "10001" in runtime or "USER hawki-rag" in runtime
        assert "RUN find /usr/local/lib/python3.13 -depth" in runtime
        for forbidden_name in (
            "__pycache__",
            ".pytest_cache",
            "tests",
            "build",
            "dist",
            "*.egg-info",
            "*.pyc",
            "*.pyo",
        ):
            assert forbidden_name in runtime


def test_builder_install_is_frozen_noneditable_and_strips_forbidden_content() -> None:
    for service in SERVICE_NAMES:
        source = _dockerfile(service)

        assert "uv sync" in source
        assert "--frozen" in source
        assert "--no-dev" in source
        assert "--no-editable" in source
        for forbidden_name in (
            "__pycache__",
            ".pytest_cache",
            "tests",
            "build",
            "dist",
            "*.egg-info",
            "test_*.py",
            "*_test.py",
        ):
            assert forbidden_name in source


def test_dockerfiles_use_allowlisted_workspace_copies() -> None:
    for service in SERVICE_NAMES:
        source = _dockerfile(service)

        assert "COPY python_rag /app" not in source
        assert "COPY python_rag /workspace" not in source
        assert f"COPY python_rag/services/{service}/src" in source
        for other in SERVICE_NAMES:
            if other != service:
                assert f"COPY python_rag/services/{other}/" not in source
