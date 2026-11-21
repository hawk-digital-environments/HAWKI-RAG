"""The default runtime has no PHP dependency or public callback ingress."""

from pathlib import Path

import yaml

from hawki_backend.deployment import configuration_checks
from test_epoch15c_readiness import environment

ROOT = Path(__file__).resolve().parents[2]


def test_default_compose_runtime_is_independent_of_laravel():
    services = yaml.safe_load((ROOT / "docker-compose.yml").read_text())["services"]
    assert services["hawki_rag_app"]["profiles"] == ["legacy-laravel"]
    for name, service in services.items():
        if name == "hawki_rag_app":
            continue
        assert "hawki_rag_app" not in service.get("depends_on", {})
    for name in (
        "hawki-rag-temporal-scraper-worker",
        "hawki-rag-temporal-converter-worker",
        "hawki-rag-indexer-worker",
    ):
        assert (
            services[name]["environment"]["HAWKI_RAG_WORKER_CALLBACK_URL"]
            == "http://hawki_backend/internal/pipeline/worker-events"
        )
    assert (
        services["hawki_backend"]["environment"]["HAWKI_BACKEND_SERVICE_ONLY"] == "true"
    )
    assert (
        services["hawki_backend"]["environment"]["HAWKI_BACKEND_REQUIRE_READY_SCHEMA"]
        == "true"
    )


def test_service_readiness_does_not_require_laravel_signing_keys():
    env = environment()
    for key in list(env):
        if key.startswith("HAWKI_QUERY_BFF_"):
            del env[key]
    env.update(
        {
            "HAWKI_BACKEND_LARAVEL_OFF": "true",
            "HAWKI_BACKEND_SERVICE_ONLY": "true",
            "HAWKI_BACKEND_AUTH_CLIENTS": '{"hawki-service":"service","rag-management":"service"}',
            "HAWKI_RAG_WORKER_CALLBACK_URL": "http://hawki_backend/internal/pipeline/worker-events",
        }
    )
    assert set(configuration_checks(env).values()) == {"PASS"}
    env["HAWKI_BACKEND_AUTH_CLIENTS"] = '{"hawki-bff":"user"}'
    assert configuration_checks(env)["service_clients"] == "FAIL"
    env["HAWKI_RAG_WORKER_CALLBACK_URL"] = (
        "http://hawki_rag_app/api/internal/pipeline/worker-events"
    )
    assert configuration_checks(env)["callback_url"] == "FAIL"


def test_nginx_blocks_internal_callbacks_and_php():
    config = (ROOT / "docker/frontend/nginx.conf").read_text()
    assert "location ^~ /internal/ { return 404; }" in config
    assert "proxy_pass http://hawki_backend;" in config
    assert "proxy_pass http://hawki_rag_ui_gateway;" in config
    assert "fastcgi" not in config


def test_default_make_startup_does_not_run_php_migrations():
    makefile = (ROOT / "Makefile").read_text()
    startup = makefile.split("_up-core: network", 1)[1].split(
        "up-core: COMPOSE_FILE_LIST", 1
    )[0]
    assert "migrate-core" not in startup
    assert "hawki_rag_app" not in startup
    assert "up -d --wait" in startup


def test_only_backend_receives_the_ignored_cutover_environment():
    compose = yaml.safe_load((ROOT / "docker-compose.yml").read_text())
    cutover = "./docker/env/epoch15c.cutover.env"
    assert compose["x-service-env"]["env_file"] == [
        "${HAWKI_RAG_COMPOSE_ENV_FILE:-.env}"
    ]
    assert compose["services"]["hawki_backend"]["env_file"] == [
        "${HAWKI_RAG_COMPOSE_ENV_FILE:-.env}",
        cutover,
    ]
    for name, service in compose["services"].items():
        if name != "hawki_backend":
            assert cutover not in service.get("env_file", [])
    assert (
        "/docker/env/epoch15c.cutover.env"
        in (ROOT / ".gitignore").read_text().splitlines()
    )
    assert (
        "docker/env/epoch15c.cutover.env"
        in (ROOT / ".dockerignore").read_text().splitlines()
    )


def test_service_jwks_probe_does_not_require_a_laravel_key_id(monkeypatch):
    from unittest.mock import Mock
    from hawki_backend import readiness

    env = environment()
    env["HAWKI_BACKEND_LARAVEL_OFF"] = "true"
    env["HAWKI_BACKEND_READINESS_JWKS_KEY_ID"] = "service-key"
    env.pop("HAWKI_QUERY_BFF_KEY_ID")
    provider = Mock()
    monkeypatch.setattr("hawki_backend.auth.jwks.JwksKeyProvider", lambda _: provider)
    readiness._jwks(env)
    provider.get_key.assert_called_once_with("service-key", "RS256")
    provider.close.assert_called_once()
