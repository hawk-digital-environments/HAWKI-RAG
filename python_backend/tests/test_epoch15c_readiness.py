"""Deployment mistakes must fail before an operator enables traffic."""

import importlib.util
import json
from pathlib import Path
from unittest.mock import MagicMock

import pytest

from hawki_backend import readiness
from hawki_backend.deployment import ENABLE_FLAGS, configuration_checks


def environment():
    return {
        **dict.fromkeys(ENABLE_FLAGS, "true"),
        "HAWKI_BACKEND_SERVICE_ONLY": "true",
        "HAWKI_BACKEND_READINESS_JWKS_KEY_ID": "service-key",
        "HAWKI_BACKEND_DATASET_CLEANUP_ENABLED": "false",
        "HAWKI_BACKEND_DB_DSN": "postgresql+psycopg://runtime:placeholder@postgres/database",
        "HAWKI_BACKEND_QUERY_DATASET_SOURCE": "database",
        "HAWKI_BACKEND_QUERY_MODELS": json.dumps(
            {"ollama": {"chat_model": "chat", "vision_model": "vision"}}
        ),
        "HAWKI_BACKEND_AUTH_ISSUER": "https://issuer.example.invalid",
        "HAWKI_BACKEND_AUTH_AUDIENCE": "hawki-backend",
        "HAWKI_BACKEND_AUTH_JWKS_URL": "https://issuer.example.invalid/jwks",
        "HAWKI_BACKEND_AUTH_CLIENTS": '{"rag-service":"service"}',
        "HAWKI_BACKEND_AUTH_ALGORITHMS": "RS256",
        "HAWKI_RAG_WORKER_CALLBACK_SECRET": "placeholder-secret",
        "HAWKI_RAG_WORKER_CALLBACK_MAX_AGE_SECONDS": "300",
        "HAWKI_RAG_WORKER_CALLBACK_URL": "http://hawki_backend/internal/pipeline/worker-events",
        "HAWKI_WORKFLOW_CONTROL_URL": "http://hawki_backend",
        "HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT": "false",
    }


def test_complete_configuration_still_needs_live_evidence():
    assert set(configuration_checks(environment()).values()) == {"PASS"}
    value = readiness.report(environment())
    assert (
        value["status"] == "FAIL"
        and value["checks"]["runtime_dependencies"] == "NOT EXECUTED"
    )


@pytest.mark.parametrize(
    "key",
    [
        "HAWKI_BACKEND_DB_DSN",
        "HAWKI_BACKEND_AUTH_ISSUER",
        "HAWKI_BACKEND_AUTH_JWKS_URL",
        "HAWKI_BACKEND_AUTH_CLIENTS",
        "HAWKI_BACKEND_QUERY_MODELS",
        "HAWKI_RAG_WORKER_CALLBACK_SECRET",
        "HAWKI_RAG_WORKER_CALLBACK_MAX_AGE_SECONDS",
        *ENABLE_FLAGS,
    ],
)
def test_missing_configuration_fails(key):
    env = environment()
    del env[key]


@pytest.mark.parametrize("value", ["{}", "", '{"dataset":{}}'])
def test_database_scope_forbids_even_empty_environment_registry(value):
    env = environment()
    env["HAWKI_BACKEND_QUERY_DATASETS"] = value
    assert configuration_checks(env)["database_dataset_source"] == "FAIL"


@pytest.mark.parametrize("revision", ["0007", "0001", None, "0009"])
def test_old_missing_or_unknown_revision_fails_readiness(monkeypatch, revision):
    engine = MagicMock()
    connection = engine.connect.return_value.__enter__.return_value
    connection.execute.return_value.scalars.return_value.all.return_value = (
        [revision] if revision else []
    )
    monkeypatch.setattr(readiness, "build_engine", lambda _: engine)
    with pytest.raises(ValueError):
        readiness.check_database(environment())
    engine.dispose.assert_called_once()


@pytest.mark.parametrize("unsafe", ["superuser", "public_reads", None])
def test_database_probe_is_read_only_and_checks_runtime_role(monkeypatch, unsafe):
    engine = MagicMock()
    connection = engine.connect.return_value.__enter__.return_value

    def execute(statement, parameters=None):
        result = MagicMock()
        sql = str(statement)
        assert not sql.lower().startswith(
            ("insert", "update", "delete", "create", "alter", "drop")
        )
        result.scalars.return_value.all.return_value = ["0009"]
        result.scalar_one.return_value = (
            unsafe == "superuser"
            if "rolsuper" in sql
            else (
                unsafe == "public_reads" if "pg_class" in sql else "CREATE" not in sql
            )
        )
        return result

    connection.execute.side_effect = execute
    monkeypatch.setattr(readiness, "build_engine", lambda _: engine)
    if unsafe:
        with pytest.raises(ValueError):
            readiness.check_database(environment())
    else:
        readiness.check_database(environment())
    assert "SET TRANSACTION READ ONLY" in str(
        connection.execute.call_args_list[0].args[0]
    )


def test_readiness_masks_secrets_and_does_not_replace_liveness(monkeypatch):
    def failure(*_):
        raise ValueError("sensitive-credential")

    monkeypatch.setattr(readiness, "check_database", failure)
    monkeypatch.setattr(readiness, "_control", failure)
    monkeypatch.setattr(readiness, "_graph", failure)
    monkeypatch.setattr(readiness, "_storage", failure)
    monkeypatch.setattr(readiness, "_crawler", failure)
    monkeypatch.setattr(readiness, "_jwks", failure)
    monkeypatch.setattr(readiness, "_models", failure)
    monkeypatch.setattr(readiness, "_http", failure)

    async def temporal(*_):
        raise ValueError("sensitive-credential")

    monkeypatch.setattr(readiness, "_temporal", temporal)
    value = readiness.report(environment(), live=True)
    assert value["status"] == "FAIL"
    assert "sensitive-credential" not in json.dumps(value)


def test_worker_callback_secret_and_url_must_agree():
    path = Path(__file__).resolve().parents[1] / "scripts/cutover_readiness.py"
    spec = importlib.util.spec_from_file_location("epoch15c_cutover_readiness", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    env = environment()
    assert set(module.replica_checks(env, env, [env, env]).values()) == {"PASS"}
    changed = {
        **env,
        "HAWKI_RAG_WORKER_CALLBACK_URL": "http://wrong/api/internal/pipeline/worker-events",
    }
    assert (
        module.replica_checks(env, env, [changed])["worker_callback_targets"] == "FAIL"
    )
    changed = {**env, "HAWKI_RAG_WORKER_CALLBACK_SECRET": "different"}
    assert (
        module.replica_checks(env, env, [changed])[
            "HAWKI_RAG_WORKER_CALLBACK_SECRET_agreement"
        ]
        == "FAIL"
    )


def test_schema_guard_refuses_startup_before_any_cleanup(monkeypatch):
    import asyncio
    from hawki_backend.main import create_app

    monkeypatch.setenv("HAWKI_BACKEND_REQUIRE_READY_SCHEMA", "true")

    def unavailable(_):
        raise RuntimeError("sensitive driver details")

    monkeypatch.setattr(readiness, "check_database", unavailable)
    app = create_app()

    async def start():
        async with app.router.lifespan_context(app):
            pytest.fail("Startup must refuse an unready schema")

    with pytest.raises(RuntimeError, match="Backend schema readiness failed") as error:
        asyncio.run(start())
    assert error.value.__cause__ is None


@pytest.mark.parametrize("enabled", [False, True])
def test_staging_can_inspect_archived_imports_without_starting_cleanup(
    monkeypatch, enabled
):
    import asyncio
    from unittest.mock import AsyncMock
    from types import SimpleNamespace
    from hawki_backend.main import create_app

    monkeypatch.setenv("HAWKI_BACKEND_DATASET_CLEANUP_ENABLED", str(enabled).lower())
    service = SimpleNamespace(reconcile_cleanup=AsyncMock(return_value=0))
    app = create_app(dataset_service=service)

    async def start():
        async with app.router.lifespan_context(app):
            assert service.reconcile_cleanup.await_count == int(enabled)

    asyncio.run(start())


@pytest.mark.parametrize("missing", ["INSERT", "UPDATE", "USAGE"])
def test_write_privilege_missing_fails_without_mutation(missing):
    connection = MagicMock()

    def execute(statement, params=None):
        sql = str(statement)
        assert sql.startswith("SELECT")
        value = True
        if "CREATE" in sql:
            value = False
        elif params and params.get("privilege") == missing:
            value = False
        elif missing == "USAGE" and "schema_privilege" in sql:
            value = False
        return MagicMock(scalar_one=lambda: value)

    connection.execute.side_effect = execute
    with pytest.raises(ValueError):
        readiness.verify_privileges(connection, environment())


def test_disabled_features_have_no_tables_or_network_requirements(monkeypatch):
    env = environment()
    env.update(dict.fromkeys(ENABLE_FLAGS, "false"))
    names = {table.name for table in readiness.required_tables(env)}
    assert names == {
        "datasets",
        "dataset_grants",
        "graph_snapshots",
        "dataset_grant_tokens",
        "workflow_operations",
        "ingest_text_operations",
        "managed_documents",
        "managed_document_revisions",
        "url_ingestion_sources",
        "url_ingestion_generations",
    }
    monkeypatch.setattr(readiness, "graph_required", lambda _: False)
    probes = []
    for name in (
        "check_database",
        "_qdrant",
        "_graph",
        "_jwks",
        "_models",
        "_reranker",
    ):
        monkeypatch.setattr(readiness, name, lambda *_, n=name: probes.append(n))
    for name in ("_control", "_crawler", "_storage"):
        monkeypatch.setattr(
            readiness, name, lambda _: pytest.fail("Disabled dependency called")
        )
    assert set(readiness.live_checks(env).values()) == {"PASS"}
    assert "FAIL" not in configuration_checks(env).values()


@pytest.mark.parametrize(
    "mode,passes",
    [
        ("none", True),
        ("cosine", True),
        ("external", False),
        ("jina", False),
        ("future", False),
    ],
)
def test_reranker_probe_is_explicit(mode, passes):
    env = environment() | {"HAWKI_BACKEND_QUERY_RERANKER": mode}
    if passes:
        readiness._reranker(env)
    else:
        with pytest.raises(
            ValueError, match="unsupported_reranker|Unsupported external"
        ):
            readiness._reranker(env)


@pytest.mark.parametrize("provider", ["ollama", "qdrant"])
def test_probe_uses_runtime_destination_even_if_alternate_is_healthy(
    monkeypatch, provider
):
    env = environment() | {
        "OLLAMA_API_URL": "http://broken/api",
        "QDRANT_HOST": "broken",
        "QDRANT_PORT": "7000",
        "QDRANT_HTTP_URL": "http://broken:7000",
        "HAWKI_BACKEND_TEXT_INGESTION_QDRANT_URL": "http://broken:7000",
    }
    seen = []

    def http(_, url, headers=None):
        seen.append(url)
        raise ConnectionError()

    monkeypatch.setattr(readiness, "_http", http)
    if provider == "qdrant":
        import requests

        monkeypatch.setattr(
            requests.Session,
            "request",
            lambda self, method, url, **kw: http(env, url, kw.get("headers")),
        )
    with pytest.raises(ConnectionError):
        (readiness._models if provider == "ollama" else readiness._qdrant)(env)
    assert seen == (
        ["http://broken/api/tags"]
        if provider == "ollama"
        else ["http://broken:7000/collections"]
    )


def test_qdrant_conflicting_runtime_destinations_refuse_before_probe(monkeypatch):
    env = environment() | {
        "QDRANT_HOST": "broken",
        "QDRANT_HTTP_URL": "http://healthy:6333",
    }
    monkeypatch.setattr(
        readiness, "_http", lambda *_: pytest.fail("Conflicting destination probed")
    )
    with pytest.raises(ValueError, match="conflict"):
        readiness._qdrant(env)


@pytest.mark.parametrize("value", ["FAIL", "UNKNOWN", "ERROR", "PASS"])
def test_component_results_preserve_application_failure(monkeypatch, value):
    from types import SimpleNamespace

    path = Path(__file__).resolve().parents[1] / "scripts/cutover_readiness.py"
    spec = importlib.util.spec_from_file_location("cutover_aggregation", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    env = environment()
    container = {
        "Id": "fixture",
        "State": {"Running": True},
        "Config": {"Labels": {"com.docker.compose.project": "test"}},
    }
    monkeypatch.setattr(module, "inspect", lambda _: (container, env))
    monkeypatch.setattr(module, "configuration_checks", lambda *_: {"auth": value})
    families = dict.fromkeys(
        [
            "callback_auth",
            "bff_identity",
            "ingress.worker-events",
            "ingress.managed-writers",
            "ingress.managed-deletion-events",
            "relay.text_ingestion",
            "relay.url_ingestion",
            "relay.managed_document",
        ],
        "PASS",
    )

    def run(command, **_):
        if "hawki_backend.readiness" in command:
            return SimpleNamespace(
                returncode=0,
                stdout=json.dumps({"status": "PASS", "checks": {"auth": "PASS"}}),
            )
        if "rag:cutover-relay-readiness" in command:
            return SimpleNamespace(
                returncode=0, stdout=json.dumps({"checks": families})
            )
        return SimpleNamespace(returncode=0)

    monkeypatch.setattr(module.subprocess, "run", run)
    report = module.deployment_report(live=True)
    assert report["checks"]["configuration.auth"] == value
    assert report["checks"]["backend.auth"] == "PASS"
    assert report["status"] == ("PASS" if value == "PASS" else "FAIL")


@pytest.mark.parametrize(
    "fault", ["capability", "future", "empty", "generation", "type"]
)
def test_crawler_requires_advertised_typed_response_contract(fault):
    spec = importlib.util.spec_from_file_location(
        "review_crawler_fixture",
        Path(__file__).parent / "url_ingestion/crawler_fixtures.py",
    )
    fixture = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(fixture)
    api_schema = fixture.api_schema
    from hawki_rag_contracts.pipeline.crawler import require_crawler_api

    schema = api_schema()
    if fault == "capability":
        del schema["x-hawki-crawler-contract"]
    elif fault == "future":
        schema["x-hawki-crawler-contract"] = "permanent-job-v3"
    else:
        response = schema["paths"]["/status/{job_id}"]["get"]["responses"]["200"][
            "content"
        ]["application/json"]
        if fault == "empty":
            response["schema"] = {}
        elif fault == "generation":
            del response["schema"]["properties"]["metadata"]["properties"]["generation"]
        else:
            response["schema"]["properties"]["metadata"]["properties"]["generation"][
                "type"
            ] = "string"
    with pytest.raises(ValueError):
        require_crawler_api(schema)


def test_sequence_privileges_are_checked_without_writes(monkeypatch):
    import sqlalchemy as sa

    table = sa.Table(
        "serial_fixture",
        sa.MetaData(),
        sa.Column("id", sa.Integer, primary_key=True),
        schema="hawki_backend",
    )
    monkeypatch.setattr(readiness, "required_tables", lambda _: {table: {"INSERT"}})
    for allowed in (False, True):

        def execute(statement, params=None):
            sql = str(statement)
            assert sql.startswith("SELECT")
            value = (
                "hawki_backend.fixture_seq"
                if "pg_get_serial" in sql
                else (
                    allowed if "has_sequence_privilege" in sql else "CREATE" not in sql
                )
            )
            return MagicMock(scalar_one=lambda: value)

        connection = MagicMock()
        connection.execute.side_effect = execute
        if allowed:
            readiness.verify_privileges(connection, environment())
        else:
            with pytest.raises(ValueError, match="sequence"):
                readiness.verify_privileges(connection, environment())


def test_current_template_requires_services_without_migration_selectors():
    root = Path(__file__).resolve().parents[2]
    template = dict(
        line.split("=", 1)
        for line in (root / "docker/env/epoch15c.cutover.env.example")
        .read_text()
        .splitlines()
        if line and not line.startswith("#")
    )
    assert all(template[name] == "true" for name in ENABLE_FLAGS)
    assert template["HAWKI_BACKEND_QUERY_DATASET_SOURCE"] == "database"
    assert "HAWKI_BACKEND_QUERY_DATASETS" not in template
    assert template["HAWKI_BACKEND_SERVICE_ONLY"] == "true"
    assert set(json.loads(template["HAWKI_BACKEND_AUTH_CLIENTS"]).values()) == {
        "service"
    }
    assert (
        template["HAWKI_BACKEND_DB_DSN"]
        == template["HAWKI_RAG_WORKER_CALLBACK_SECRET"]
        == ""
    )
    assert template["HAWKI_BACKEND_DATASET_CLEANUP_ENABLED"] == "false"
    assert not any(name.startswith("HAWKI_QUERY_BFF_") for name in template)
    assert not any(name.endswith("_BACKEND") for name in template)
    compose = (root / "docker-compose.yml").read_text()
    assert "epoch15c.cutover.env.example" not in compose
    assert "hawki_backend.readiness" in compose
    image = (root / "python_backend/Dockerfile").read_text()
    assert "COPY python_backend/alembic" not in image
    assert "alembic upgrade" not in image
