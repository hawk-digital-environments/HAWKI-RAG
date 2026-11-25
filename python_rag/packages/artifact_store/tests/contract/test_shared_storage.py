"""Python-owned schema, callback and shared-storage deployment contracts."""

from __future__ import annotations

from pathlib import Path
import re


PYTHON_ROOT = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)
ROOT = PYTHON_ROOT.parent
COMPOSE_FILE = ROOT / "docker-compose.yml"
COMPOSE_FILES = (
    COMPOSE_FILE,
    ROOT / "docker-compose.local.yml",
    ROOT / "docker-compose-gpu-override.yml",
)
ENV_EXAMPLE_FILE = ROOT / ".env.example"
MAKEFILE = ROOT / "Makefile"
NEO4J_ENV_FILE = ROOT / "docker" / "env" / "neo4j.env"
GRAPH_PREPARE = (
    ROOT
    / "python_rag"
    / "services"
    / "hawki_indexer_worker"
    / "src"
    / "hawki_indexer_worker"
    / "indexing"
    / "graph_prepare.py"
)
PYTHON_PERMISSIONS_HOOK = (
    ROOT / "docker" / "python_rag" / "80-rawki-runtime-permissions.sh"
)


def _service_block(name: str) -> str:
    compose = COMPOSE_FILE.read_text(encoding="utf-8")
    match = re.search(
        rf"(?ms)^  {re.escape(name)}:\n(?P<body>.*?)(?=^  [a-zA-Z0-9_-]+:\n|^volumes:\n)",
        compose,
    )
    assert match is not None, name
    return match.group("body")


def test_compose_files_do_not_define_a_migrator_container() -> None:
    migrator_name = "hawki_rag_" + "migrator"

    for compose_file in COMPOSE_FILES:
        assert migrator_name not in compose_file.read_text(encoding="utf-8")
    assert migrator_name not in MAKEFILE.read_text(encoding="utf-8")


def test_make_uses_explicit_python_migrations() -> None:
    makefile = MAKEFILE.read_text(encoding="utf-8")

    assert "uv run --frozen alembic upgrade head" in makefile
    assert "php artisan" not in makefile
    assert "migrate-legacy" not in makefile


def test_backend_requires_live_readiness_and_removed_services_cannot_start() -> None:
    backend = _service_block("rag_backend")
    assert '"hawki_backend.readiness", "--live"' in backend
    assert "hawki_rag_app:" not in COMPOSE_FILE.read_text()
    assert "hawki_rag_bridge:" not in COMPOSE_FILE.read_text()
    assert not (ROOT / "docker/laravel.Dockerfile").exists()


def test_writable_python_roles_use_root_bootstrap_and_nonroot_runtime_contract() -> (
    None
):
    runtime_env = ENV_EXAMPLE_FILE.read_text(encoding="utf-8")
    permissions_hook = PYTHON_PERMISSIONS_HOOK.read_text(encoding="utf-8")

    assert re.search(r"(?m)^PUID=[1-9][0-9]*$", runtime_env)
    assert re.search(r"(?m)^PGID=[1-9][0-9]*$", runtime_env)
    assert 'shared_gid="${PIPELINE_SHARED_STORAGE_GID:-}"' in permissions_hook
    assert 'usermod --append --groups "$shared_group" www-data' in permissions_hook
    assert 'chown -R www-data:www-data "$writable_dir"' in permissions_hook

    for name in (
        "scraper_worker",
        "converter_worker",
        "indexer_worker",
    ):
        service = _service_block(name)
        assert "user:" not in service
        assert "dockerfile: python_rag/Dockerfile" in service
        assert "stop_grace_period: 15s" in service
        assert "hawki_rag_app:" not in service
        assert "rag_backend:" in service
        assert "condition: service_healthy" in service
        assert '  - "${PGID}"' in service


def test_compose_keeps_security_settings_server_side() -> None:
    base_compose = COMPOSE_FILE.read_text(encoding="utf-8")
    assert "x-service-env: &service_env" in base_compose
    assert "${RAG_COMPOSE_ENV_FILE:-.env}" in base_compose
    backend = _service_block("rag_backend")
    backend_env = (ROOT / "docker/env/backend.env").read_text()
    assert "./docker/env/backend.env" in backend
    assert "RAG_BACKEND_SERVICE_ONLY=true" in backend_env.splitlines()
    assert "RAG_BACKEND_REQUIRE_READY_SCHEMA=true" in backend_env.splitlines()
    callback_env = (ROOT / "docker/env/worker-callback.env").read_text()
    assert (
        "RAG_WORKER_CALLBACK_URL=http://rag_backend/internal/pipeline/worker-events"
        in callback_env.splitlines()
    )
    for name in (
        "scraper_worker",
        "converter_worker",
        "indexer_worker",
    ):
        assert "./docker/env/worker-callback.env" in _service_block(name)
    assert "ports:" not in backend


def test_neo4j_receives_only_its_server_auth_adapter() -> None:
    neo4j = _service_block("neo4j")
    neo4j_env = NEO4J_ENV_FILE.read_text(encoding="utf-8")

    assert "env_file:\n      - docker/env/neo4j.env" in neo4j
    assert "NEO4J_AUTH=${NEO4J_USER}/${NEO4J_PASSWORD}" in neo4j_env
    assert "NEO4J_HTTP_URL" not in neo4j_env
    assert "NEO4J_URI" not in neo4j_env


def test_legacy_ui_json_artifacts_are_not_configured_or_generated() -> None:
    sources = (
        ENV_EXAMPLE_FILE.read_text(encoding="utf-8"),
        PYTHON_PERMISSIONS_HOOK.read_text(encoding="utf-8"),
        GRAPH_PREPARE.read_text(encoding="utf-8"),
    )
    legacy_names = (
        "RAG_INGEST_STATUS_PATH",
        "RAG_INGEST_STATUS_PATH_NEO4J",
        "RAG_GRAPH_VISUALIZATION_PATH",
        "neo4j_graph_visualization.json",
        "write_graph_visualization",
    )

    for legacy_name in legacy_names:
        assert all(legacy_name not in source for source in sources)
