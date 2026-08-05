"""Compose contracts for the Laravel migration and storage bootstrap role."""

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
LARAVEL_DOCKERFILE = ROOT / "docker" / "laravel.Dockerfile"


def _service_block(name: str) -> str:
    compose = COMPOSE_FILE.read_text(encoding="utf-8")
    match = re.search(
        rf"(?ms)^  {re.escape(name)}:\n(?P<body>.*?)(?=^  [a-zA-Z0-9_-]+:\n|^volumes:\n)",
        compose,
    )
    assert match is not None, name
    return match.group("body")


def test_migrator_is_a_one_shot_laravel_role() -> None:
    migrator = _service_block("hawki_rag_migrator")

    assert "image: hawki-rag-app:local" in migrator
    assert "dockerfile: docker/laravel.Dockerfile" in migrator
    assert 'user: "0:0"' in migrator
    assert 'restart: "no"' in migrator
    assert "exec php artisan migrate --force" in migrator


def test_migrator_creates_group_writable_setgid_directories() -> None:
    command = _service_block("hawki_rag_migrator")

    for path in ("sources", "logs", "public", "storage/logs"):
        assert f"/shared/{path}" in command
    assert 'chgrp -R "$${PIPELINE_SHARED_STORAGE_GID}" /shared' in command
    assert "chmod -R g+rwX /shared" in command
    assert "find /shared -type d -exec chmod g+s {} +" in command


def test_migrator_repairs_acl_access_for_the_laravel_uid() -> None:
    migrator = _service_block("hawki_rag_migrator")
    dockerfile = LARAVEL_DOCKERFILE.read_text(encoding="utf-8")
    env_example = ENV_EXAMPLE_FILE.read_text(encoding="utf-8")

    assert re.search(r"(?m)^\s*acl\s*\\$", dockerfile)
    assert "<<: *service_env" in migrator
    assert "PIPELINE_SHARED_STORAGE_UID=${PUID}" in env_example
    assert (
        'setfacl -R -P -m "u:$${PIPELINE_SHARED_STORAGE_UID}:rwX,m::rwX" /shared'
        in migrator
    )
    assert (
        'setfacl -m "d:u:$${PIPELINE_SHARED_STORAGE_UID}:rwx,d:g::rwx,d:m::rwx"'
        in migrator
    )


def test_writable_python_roles_wait_for_migration_and_stay_nonroot() -> None:
    for name in (
        "hawki-rag-temporal-scraper-worker",
        "hawki-rag-temporal-converter-worker",
        "hawki-rag-indexer-worker",
    ):
        service = _service_block(name)
        assert 'user: "10001:10001"' in service
        assert "hawki_rag_migrator:" in service
        assert "condition: service_completed_successfully" in service
        assert '  - "${PGID}"' in service


def test_compose_files_delegate_runtime_environment_to_dotenv() -> None:
    for compose_file in COMPOSE_FILES:
        compose = compose_file.read_text(encoding="utf-8")
        assert re.search(r"(?m)^\s+environment:\s*$", compose) is None

    base_compose = COMPOSE_FILE.read_text(encoding="utf-8")
    assert "x-service-env: &service_env" in base_compose
    assert "${HAWKI_RAG_COMPOSE_ENV_FILE:-.env}" in base_compose
