"""Compose contracts for app-owned migrations and shared-storage bootstrap."""

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
    ROOT / "docker-compose.ui.yml",
)
ENV_EXAMPLE_FILE = ROOT / ".env.example"
MAKEFILE = ROOT / "Makefile"
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
PYTHON_RUNTIME_ENV = ROOT / "docker" / "python_rag" / "10-rawki-runtime.env"
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


def test_make_runs_migrations_inside_the_laravel_app() -> None:
    makefile = MAKEFILE.read_text(encoding="utf-8")

    assert "up -d postgres temporal hawki_rag_app" in makefile
    assert "exec -T hawki_rag_app php artisan migrate --force" in makefile


def test_app_becomes_healthy_only_after_initialization() -> None:
    app = _service_block("hawki_rag_app")
    entrypoint = APP_ENTRYPOINT.read_text(encoding="utf-8")

    assert '["CMD", "test", "-f", "/tmp/hawki-rag-app-ready"]' in app
    assert 'touch "$APP_READY_MARKER"' in entrypoint
    assert entrypoint.index('touch "$APP_READY_MARKER"') > entrypoint.index(
        "php artisan package:discover"
    )


def test_writable_python_roles_use_root_bootstrap_and_nonroot_runtime_contract() -> (
    None
):
    runtime_env = PYTHON_RUNTIME_ENV.read_text(encoding="utf-8")
    permissions_hook = PYTHON_PERMISSIONS_HOOK.read_text(encoding="utf-8")

    assert re.search(r"(?m)^PUID=10001$", runtime_env)
    assert re.search(r"(?m)^PGID=10001$", runtime_env)
    assert 'shared_gid="${PIPELINE_SHARED_STORAGE_GID:-}"' in permissions_hook
    assert 'usermod --append --groups "$shared_group" www-data' in permissions_hook
    assert 'chown -R www-data:www-data "$writable_dir"' in permissions_hook

    for name in (
        "hawki-rag-temporal-scraper-worker",
        "hawki-rag-temporal-converter-worker",
        "hawki-rag-indexer-worker",
    ):
        service = _service_block(name)
        assert "user:" not in service
        assert "dockerfile: docker/python_rag.Dockerfile" in service
        assert "stop_grace_period: 15s" in service
        assert "hawki_rag_app:" in service
        assert "condition: service_healthy" in service
        assert '  - "${PGID}"' in service


def test_compose_files_delegate_runtime_environment_to_dotenv() -> None:
    # Override files stay fully delegated to the dotenv.
    for compose_file in COMPOSE_FILES[1:]:
        compose = compose_file.read_text(encoding="utf-8")
        assert re.search(r"(?m)^\s+environment:\s*$", compose) is None

    base_compose = COMPOSE_FILE.read_text(encoding="utf-8")
    assert "x-service-env: &service_env" in base_compose
    assert "${HAWKI_RAG_COMPOSE_ENV_FILE:-.env}" in base_compose

    # The base file may pin fixed, non-secret image settings (HF_HOME) or
    # pass a variable through with a default; every passthrough variable
    # must be declared in the dotenv template so operators keep one
    # source of truth and no secret ever rides an environment: block.
    env_example = ENV_EXAMPLE_FILE.read_text(encoding="utf-8")
    fixed_settings = frozenset({"HF_HOME"})
    passthrough = re.compile(r"^([A-Z][A-Z0-9_]*)=\$\{([A-Z0-9_]+):-[^}]+\}$")
    for block in re.finditer(
        r"(?ms)^ {4}environment:\n((?:[ ]+- [^\n]*\n)+)", base_compose
    ):
        entries = [entry.strip()[2:] for entry in block.group(1).splitlines()]
        assert entries, block.group(0)
        for entry in entries:
            passed = passthrough.match(entry)
            if passed is not None:
                assert passed.group(1) == passed.group(2), entry
                assert re.search(
                    rf"(?m)^#?{re.escape(passed.group(1))}=", env_example
                ), entry
            else:
                assert entry.split("=", 1)[0] in fixed_settings, entry


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
