"""Static contracts for the six production Python role images."""

from __future__ import annotations

from pathlib import Path
import re


PYTHON_ROOT = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)
REPOSITORY_ROOT = PYTHON_ROOT.parent
DOCKERFILE = PYTHON_ROOT / "Dockerfile"
PINNED_PYTHON_NGINX = (
    "neunerlei/python-nginx:3.13@sha256:"
    "05b581371d0d9faef2f160079acd0a4e18503b99d47b995825205e71fd13c136"
)
SERVICES = {
    "hawki_bridge": ("bridge", "hawki-bridge", "hawki_bridge.main:app"),
    "hawki_workflow_worker": (
        "workflow",
        "hawki-workflow-worker",
        "python -m hawki_workflow_worker.main",
    ),
    "hawki_scraper_worker": (
        "scraper",
        "hawki-scraper-worker",
        "python -m hawki_scraper_worker.main",
    ),
    "hawki_converter_worker": (
        "converter",
        "hawki-converter-worker",
        "python -m hawki_converter_worker.main",
    ),
    "hawki_indexer_worker": (
        "indexer",
        "hawki-indexer-worker",
        "python -m hawki_indexer_worker.main",
    ),
    "hawki_reranker": (
        "reranker",
        "hawki-reranker",
        "hawki_reranker.main:app",
    ),
}


def _source() -> str:
    return DOCKERFILE.read_text(encoding="utf-8")


def _stage(source: str, name: str) -> str:
    match = re.search(
        rf"(?ms)^FROM .* AS {re.escape(name)}\n(?P<body>.*?)(?=^FROM |\Z)",
        source,
    )
    assert match is not None, name
    return match.group("body")


def test_unified_dockerfile_pins_the_verified_base_and_uv_toolchain() -> None:
    source = _source()

    assert PINNED_PYTHON_NGINX in source
    assert "FROM ghcr.io/astral-sh/uv:0.11.26 AS uv" in source
    assert "UV_PYTHON=/usr/local/bin/python3.13" in source
    assert "UV_PYTHON_DOWNLOADS=never" in source
    assert "UV_PROJECT_ENVIRONMENT=/opt/venv" not in source


def test_each_role_has_an_isolated_frozen_builder_and_runtime_target() -> None:
    source = _source()

    for service, (target, package, _entrypoint) in SERVICES.items():
        builder = _stage(source, f"{target}-builder")
        runtime = _stage(source, f"{target}-runtime")

        assert f"--package {package}" in builder
        assert "uv sync --frozen --no-dev --no-editable" in builder
        assert f"COPY python_rag/services/{service}/src" in builder
        for other_service in SERVICES:
            if other_service != service:
                assert f"COPY python_rag/services/{other_service}/" not in builder

        assert f"COPY --from={target}-builder" in runtime
        assert (
            "/workspace/python_rag/.venv/lib/python3.13/site-packages/ "
            "/opt/venv/lib/python3.13/site-packages/"
        ) in runtime
        assert "USER " not in runtime


def test_store_consumers_copy_the_independent_store_packages() -> None:
    source = _source()

    assert "python_rag/packages/stores" not in source
    for target in ("bridge-builder", "indexer-builder"):
        builder = _stage(source, target)
        assert "python_rag/packages/graph_store/pyproject.toml" in builder
        assert "python_rag/packages/graph_store/src" in builder
        assert "python_rag/packages/vector_store/pyproject.toml" in builder
        assert "python_rag/packages/vector_store/src" in builder


def test_builders_strip_nonruntime_content_before_copying_site_packages() -> None:
    source = _source()

    for target, _package, _entrypoint in SERVICES.values():
        builder = _stage(source, f"{target}-builder")
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
            assert forbidden_name in builder

        assert "-name 'test_*.py'" not in builder
        assert "-name '*_test.py'" not in builder


def test_web_targets_use_one_asgi_worker_behind_nginx() -> None:
    source = _source()

    for target, module in (
        ("bridge", "hawki_bridge.main:app"),
        ("reranker", "hawki_reranker.main:app"),
    ):
        runtime = _stage(source, f"{target}-runtime")
        assert f"PYTHON_APP_MODULE={module}" in runtime
        assert "GUNICORN_WORKER_CLASS=uvicorn.workers.UvicornWorker" in runtime
        assert "GUNICORN_WORKERS=1" in runtime
        assert "EXPOSE 80" in runtime


def test_temporal_targets_preserve_module_commands_and_stop_window() -> None:
    source = _source()
    worker_base = _stage(source, "worker-runtime-base")

    assert "PYTHON_WORKER_PROCESS_COUNT=1" in worker_base
    assert "PYTHON_WORKER_STOP_WAIT_SECS=10" in worker_base
    for target, _package, entrypoint in SERVICES.values():
        if not entrypoint.startswith("python -m "):
            continue
        runtime = _stage(source, f"{target}-runtime")
        assert f'PYTHON_WORKER_COMMAND="{entrypoint}"' in runtime


def test_torch_environments_and_indexer_system_dependencies_stay_separate() -> None:
    source = _source()
    indexer_builder = _stage(source, "indexer-builder")
    indexer_runtime = _stage(source, "indexer-runtime")
    reranker_builder = _stage(source, "reranker-builder")
    reranker_runtime = _stage(source, "reranker-runtime")

    assert '--package hawki-indexer-worker --extra "$TORCH_VARIANT"' in indexer_builder
    assert '--package hawki-reranker --extra "$TORCH_VARIANT"' in reranker_builder
    assert "git ca-certificates" in indexer_builder
    assert "ffmpeg libgl1 libglib2.0-0" in indexer_runtime
    assert "ca-certificates libgomp1" in reranker_runtime
