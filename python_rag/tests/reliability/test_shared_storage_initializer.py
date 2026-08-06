"""Compose contracts for app-owned migrations and shared-storage bootstrap."""

from __future__ import annotations

from pathlib import Path
import re


ROOT = Path(__file__).resolve().parents[3]
COMPOSE_FILE = ROOT / "docker-compose.yml"
COMPOSE_FILES = (
    COMPOSE_FILE,
    ROOT / "docker-compose.local.yml",
    ROOT / "docker-compose-gpu-override.yml",
    ROOT / "docker-compose.ui.yml",
)
ENV_EXAMPLE_FILE = ROOT / ".env.example"
MAKEFILE = ROOT / "Makefile"
LARAVEL_DOCKERFILE = ROOT / "docker" / "laravel.Dockerfile"
APP_ENTRYPOINT = ROOT / "docker" / "rag_app" / "entrypoint" / "entrypoint.sh"
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


def test_app_entrypoint_creates_group_writable_setgid_directories() -> None:
    entrypoint = APP_ENTRYPOINT.read_text(encoding="utf-8")

    for path in ("sources", "logs", "public", "storage/logs"):
        assert f'"$CRAWLED_DATA_ROOT/{path}"' in entrypoint
    assert 'chgrp -R "$SHARED_STORAGE_GID" "$CRAWLED_DATA_ROOT"' in entrypoint
    assert 'chmod -R 775 "$CRAWLED_DATA_ROOT"' in entrypoint
    assert 'find "$CRAWLED_DATA_ROOT" -type d -exec chmod g+s {} +' in entrypoint


def test_app_entrypoint_repairs_acl_access_for_the_configured_uid() -> None:
    entrypoint = APP_ENTRYPOINT.read_text(encoding="utf-8")
    dockerfile = LARAVEL_DOCKERFILE.read_text(encoding="utf-8")
    env_example = ENV_EXAMPLE_FILE.read_text(encoding="utf-8")

    assert re.search(r"(?m)^\s*acl\s*\\$", dockerfile)
    assert "PIPELINE_SHARED_STORAGE_UID=${PUID}" in env_example
    assert (
        'setfacl -R -P -m "u:$SHARED_STORAGE_UID:rwX,m::rwX" '
        '"$CRAWLED_DATA_ROOT"' in entrypoint
    )
    assert '"d:u:$SHARED_STORAGE_UID:rwx,d:g::rwx,d:m::rwx"' in entrypoint


def test_make_runs_migrations_inside_the_laravel_app() -> None:
    makefile = MAKEFILE.read_text(encoding="utf-8")

    assert "up -d postgres temporal hawki_rag_app" in makefile
    assert "exec -T hawki_rag_app php artisan migrate --force" in makefile


def test_app_becomes_healthy_only_after_initialization() -> None:
    app = _service_block("hawki_rag_app")
    entrypoint = APP_ENTRYPOINT.read_text(encoding="utf-8")

    assert '["CMD", "test", "-f", "/tmp/hawki-rag-app-ready"]' in app
    assert 'touch "$APP_READY_MARKER"' in entrypoint
    assert entrypoint.index('touch "$APP_READY_MARKER"') > entrypoint.index("setfacl")


def test_writable_python_roles_wait_for_the_app_and_stay_nonroot() -> None:
    for name in (
        "hawki-rag-temporal-scraper-worker",
        "hawki-rag-temporal-converter-worker",
        "hawki-rag-indexer-worker",
    ):
        service = _service_block(name)
        assert 'user: "10001:10001"' in service
        assert "hawki_rag_app:" in service
        assert "condition: service_healthy" in service
        assert '  - "${PGID}"' in service


def test_compose_files_delegate_runtime_environment_to_dotenv() -> None:
    for compose_file in COMPOSE_FILES:
        compose = compose_file.read_text(encoding="utf-8")
        assert re.search(r"(?m)^\s+environment:\s*$", compose) is None

    base_compose = COMPOSE_FILE.read_text(encoding="utf-8")
    assert "x-service-env: &service_env" in base_compose
    assert "${HAWKI_RAG_COMPOSE_ENV_FILE:-.env}" in base_compose


def test_neo4j_receives_only_its_server_auth_adapter() -> None:
    neo4j = _service_block("hawki_rag_neo4j")
    neo4j_env = NEO4J_ENV_FILE.read_text(encoding="utf-8")

    assert "env_file:\n      - docker/env/neo4j.env" in neo4j
    assert "NEO4J_AUTH=${NEO4J_USER}/${NEO4J_PASSWORD}" in neo4j_env
    assert "NEO4J_HTTP_URL" not in neo4j_env
    assert "NEO4J_URI" not in neo4j_env


def test_legacy_ui_json_artifacts_are_not_configured_or_generated() -> None:
    sources = (
        ENV_EXAMPLE_FILE.read_text(encoding="utf-8"),
        (ROOT / "config" / "config.php").read_text(encoding="utf-8"),
        APP_ENTRYPOINT.read_text(encoding="utf-8"),
        GRAPH_PREPARE.read_text(encoding="utf-8"),
    )
    legacy_names = (
        "HAWKI_RAG_INGEST_STATUS_PATH",
        "HAWKI_RAG_INGEST_STATUS_PATH_NEO4J",
        "HAWKI_RAG_GRAPH_VISUALIZATION_PATH",
        "neo4j_graph_visualization.json",
        "write_graph_visualization",
    )

    for legacy_name in legacy_names:
        assert all(legacy_name not in source for source in sources)
