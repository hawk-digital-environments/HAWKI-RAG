"""Operator tooling tests: attached SQLite schemas, no production connections."""

import copy
import json
import sys
import time
from pathlib import Path

import pytest
import sqlalchemy as sa

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import query_grants as migration  # noqa: E402
import query_readiness as readiness  # noqa: E402
from hawki_backend.persistence.schema import metadata, datasets, dataset_grants  # noqa: E402
from hawki_backend.query.settings import load_query_settings  # noqa: E402


@pytest.fixture
def inventory():
    rows = [
        dict(
            dataset_id=name,
            status=status,
            qdrant_collection=f"q-{name}",
            neo4j_namespace="graph" if name == "a" else None,
            embedding_provider="ollama",
            embedding_model="bge-m3",
        )
        for name, status in (
            ("b", "active"),
            ("a", "active"),
            ("old", "archived"),
            ("gone", "deleted"),
        )
    ]
    source = {
        "datasets": rows,
        "users": [
            {"id": 1, "isRemoved": False},
            {"id": 2, "isRemoved": False},
            {"id": 3, "isRemoved": True},
        ],
        "dataset_grants": [
            {
                "dataset_id": "a",
                "principal_type": "user",
                "principal_id": "1",
                "permission": "query",
            },
            {
                "dataset_id": "old",
                "principal_type": "user",
                "principal_id": "1",
                "permission": "query",
            },
            {
                "dataset_id": "b",
                "principal_type": "user",
                "principal_id": "3",
                "permission": "query",
            },
            {
                "dataset_id": "a",
                "principal_type": "user",
                "principal_id": "1",
                "permission": "ingest",
            },
        ],
    }
    report = {
        "version": 1,
        "generated_at": int(time.time()),
        "tenant": "default",
        "backend": "laravel",
        "legacy_bridge_query_url": "http://hawki_rag_bridge/query",
        "key_forbidden_paths": [
            "/var/www/html/public",
            "/var/www/html/storage",
            "/shared",
        ],
        "query_all": False,
        "datasets": [{**r, "graph_enabled": r["dataset_id"] == "a"} for r in rows],
        "models": {
            "ollama": {
                "chat_model": "llama3.1:8b",
                "vision_model": "llama3.2-vision:11b",
            }
        },
        "bff": {
            "tenant": "default",
            "issuer": "https://bff.example.test",
            "audience": "hawki-backend",
            "client_id": "hawki-bff",
            "key_id": "key",
            "token_ttl": 60,
        },
        "auth_default_guard": "web",
        "auth_guard_drivers": {"web": "session", "sanctum": "sanctum"},
    }
    return source, report


@pytest.fixture
def engine():
    engine = sa.create_engine("sqlite://")
    with engine.begin() as connection:
        connection.execute(sa.text("ATTACH DATABASE ':memory:' AS hawki_backend"))
        connection.execute(sa.text("ATTACH DATABASE ':memory:' AS public"))
        metadata.create_all(connection)
        connection.execute(
            sa.text('CREATE TABLE public.users (id INTEGER, "isRemoved" BOOLEAN)')
        )
        connection.execute(
            sa.text(
                "CREATE TABLE public.datasets (dataset_id TEXT, status TEXT, qdrant_collection TEXT, neo4j_namespace TEXT, embedding_provider TEXT, embedding_model TEXT)"
            )
        )
        connection.execute(
            sa.text(
                "CREATE TABLE public.dataset_grants (dataset_id TEXT, principal_type TEXT, principal_id TEXT, permission TEXT)"
            )
        )
    yield engine
    engine.dispose()


def environment(plan):
    return {
        "HAWKI_BACKEND_QUERY_DATASETS": json.dumps(plan.scope),
        "HAWKI_BACKEND_QUERY_MODELS": json.dumps(plan.models),
        "HAWKI_BACKEND_QUERY_RERANKER": "external",
        "HAWKI_BACKEND_AUTH_ISSUER": "https://bff.example.test",
        "HAWKI_BACKEND_AUTH_AUDIENCE": "hawki-backend",
        "HAWKI_BACKEND_AUTH_JWKS_URL": "https://bff.example.test/.well-known/jwks.json",
        "HAWKI_BACKEND_AUTH_CLIENTS": '{"hawki-bff":"user"}',
        "HAWKI_BACKEND_AUTH_ALGORITHMS": "RS256",
    }


def test_dry_plan_exact_identity_statuses_and_order(inventory, engine):
    plan = migration.make_plan(*inventory)
    assert list(plan.scope) == ["a", "b"]
    assert plan.dataset_status == {
        "a": "active",
        "b": "active",
        "gone": "deleted",
        "old": "archived",
    }
    assert plan.grants == {("a", "1"), ("old", "1")}
    assert plan.summary()["counts"] == {
        "users": 2,
        "datasets": 4,
        "active_datasets": 2,
        "grants": 2,
    }
    assert all(r["tenant_id"] == "default" for r in plan.summary()["grants"])
    assert load_query_settings(environment(plan)).resolve_scope("a").graph_enabled
    with engine.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(datasets)
            ).scalar()
            == 0
        )


def test_expansion_is_explicit_and_rollback_requirements_are_exact(inventory):
    source, report = inventory
    report["query_all"] = True
    explicit = migration.make_plan(source, report)
    expanded = migration.make_plan(source, report, expand=True)
    assert len(explicit.grants) == 2
    assert len(expanded.grants) == 5
    assert expanded.legacy_required == {("a", "2"), ("b", "1"), ("b", "2")}
    assert expanded.digest == migration.make_plan(source, report, expand=True).digest
    report["query_all"] = False
    with pytest.raises(migration.MigrationError):
        migration.make_plan(source, report, expand=True)


def test_apply_idempotent_no_public_writes_and_dataset_before_grants(inventory, engine):
    plan = migration.make_plan(*inventory)
    statements = []

    @sa.event.listens_for(engine, "before_cursor_execute")
    def check_writes(conn, cursor, statement, parameters, context, executemany):
        if statement.lstrip().split()[0].upper() in {"INSERT", "UPDATE", "DELETE"}:
            assert "public." not in statement
            statements.append(statement)

    with engine.begin() as connection:
        first = migration.apply_plan(connection, plan, plan.digest)
        assert first == {
            "datasets_inserted": 4,
            "datasets_updated": 0,
            "grants_inserted": 2,
        }
        assert migration.compare_target(connection, plan)["status"] == "PASS"
    with engine.begin() as connection:
        assert not any(migration.apply_plan(connection, plan, plan.digest).values())
    assert all("hawki_backend.datasets" in s for s in statements[:4])
    assert all("hawki_backend.dataset_grants" in s for s in statements[4:])


def test_rollback_on_mid_apply_failure(inventory, engine):
    plan = migration.make_plan(*inventory)

    @sa.event.listens_for(engine, "before_cursor_execute")
    def fail_grant(conn, cursor, statement, parameters, context, executemany):
        if statement.startswith("INSERT INTO hawki_backend.dataset_grants"):
            raise RuntimeError("injected grant failure")

    with pytest.raises(RuntimeError), engine.begin() as connection:
        migration.apply_plan(connection, plan, plan.digest)
    with engine.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(datasets)
            ).scalar()
            == 0
        )


@pytest.mark.parametrize(
    "mutation", ["missing", "extra", "status", "principal", "tenant"]
)
def test_verify_detects_drift(inventory, engine, mutation):
    plan = migration.make_plan(*inventory)
    with engine.begin() as connection:
        migration.apply_plan(connection, plan, plan.digest)
        if mutation == "missing":
            connection.execute(dataset_grants.delete())
        elif mutation == "extra":
            connection.execute(
                datasets.insert().values(
                    tenant_id="default",
                    dataset_id="unexpected",
                    status="active",
                    name="Unexpected",
                    qdrant_collection="hawki_unexpected",
                    neo4j_namespace="hawki_unexpected",
                    embedding_provider="ollama",
                    embedding_model="bge-m3",
                )
            )
        elif mutation == "status":
            connection.execute(datasets.update().values(status="archived"))
        elif mutation == "principal":
            connection.execute(dataset_grants.update().values(principal_id="other"))
        else:
            connection.execute(datasets.update().values(tenant_id="other"))
            with pytest.raises(migration.MigrationError):
                migration.compare_target(connection, plan)
            return
        assert migration.compare_target(connection, plan)["status"] == "FAIL"


@pytest.mark.parametrize(
    "mutation",
    [
        "wildcard",
        "whitespace",
        "service",
        "duplicate",
        "unknown_user",
        "status",
        "drift",
        "tenant",
        "stale",
        "graph",
    ],
)
def test_malformed_source_fails_closed(inventory, mutation):
    source, report = copy.deepcopy(inventory)
    grant = source["dataset_grants"][0]
    if mutation == "wildcard":
        grant["principal_id"] = "*"
    elif mutation == "whitespace":
        grant["principal_id"] = " 1 "
    elif mutation == "service":
        grant["principal_type"] = "service"
    elif mutation == "duplicate":
        source["dataset_grants"].append(grant.copy())
    elif mutation == "unknown_user":
        grant["principal_id"] = "01"
    elif mutation == "status":
        source["datasets"][0]["status"] = "unknown"
    elif mutation == "drift":
        report["datasets"][0]["embedding_model"] = "other"
    elif mutation == "tenant":
        report["tenant"] = "other"
    elif mutation == "stale":
        report["generated_at"] -= 301
    else:
        report["datasets"][0]["graph_enabled"] = "false"
    with pytest.raises(Exception):
        migration.make_plan(source, report)


def test_scope_mismatch_and_confirmation_block(inventory, engine):
    plan = migration.make_plan(*inventory)
    env = environment(plan)
    assert migration.verify_scope(plan, env)
    changed = copy.deepcopy(plan.scope)
    changed["a"]["neo4j_namespace"] = "other"
    env["HAWKI_BACKEND_QUERY_DATASETS"] = json.dumps(changed)
    assert not migration.verify_scope(plan, env)
    with engine.begin() as connection, pytest.raises(migration.MigrationError):
        migration.apply_plan(connection, plan, "unreviewed")


def test_public_reads_only_and_tenant_detection(inventory, engine):
    source, report = inventory
    with engine.begin() as connection:
        for name, rows in source.items():
            table = sa.Table(
                name, sa.MetaData(), schema="public", autoload_with=connection
            )
            connection.execute(table.insert(), rows)
        read = migration.read_source(connection)
        assert (
            migration.make_plan(read, report).digest
            == migration.make_plan(source, report).digest
        )
        connection.execute(
            sa.text(
                "ALTER TABLE public.datasets ADD COLUMN tenant_id TEXT DEFAULT 'other'"
            )
        )
        with pytest.raises(migration.MigrationError):
            migration.read_source(connection)


@pytest.mark.parametrize(
    "mutation,gate",
    [
        ("tenant", "auth"),
        ("models", "models"),
        ("scope", "scope"),
        ("missing_grant", "backfill"),
        ("missing_dataset", "backfill"),
        ("guard", "auth_guard_advisory"),
    ],
)
def test_readiness_drift(inventory, engine, mutation, gate):
    plan = migration.make_plan(*inventory)
    report = inventory[1]
    env = environment(plan)
    with engine.begin() as connection:
        migration.apply_plan(connection, plan, plan.digest)
        if mutation == "tenant":
            report["bff"]["tenant"] = "other"
        elif mutation == "models":
            env["HAWKI_BACKEND_QUERY_MODELS"] = (
                '{"ollama":{"chat_model":"other","vision_model":"v"}}'
            )
        elif mutation == "scope":
            env["HAWKI_BACKEND_QUERY_DATASETS"] = "{}"
        elif mutation == "missing_grant":
            connection.execute(dataset_grants.delete())
        elif mutation == "missing_dataset":
            connection.execute(datasets.delete())
        else:
            report["auth_guard_drivers"]["api"] = "token"
        result = readiness.check_readiness(
            connection,
            plan,
            report,
            env,
            inspect_containers=False,
            live=False,
            phase="activate",
        )
    assert result["status"] == "FAIL"
    assert result["checks"][gate] == "FAIL"
    assert result["checks"]["shared_environment"] == "NOT EXECUTED"


def test_shared_and_reranker_parity_never_prints_values(inventory):
    plan = migration.make_plan(*inventory)
    env = environment(plan)
    query = load_query_settings(env)
    bridge = {
        "RERANKER_MODE": "external",
        "RERANKER_API_URL": "http://reranker.test",
        "RERANKER_API_KEY": "DO-NOT-PRINT",
    }
    assert readiness.reranker_matches(query, bridge)
    assert not readiness.reranker_matches(query, {**bridge, "RERANKER_MODE": "none"})
    assert not readiness.reranker_matches(
        query, {**bridge, "RERANKER_MIX_WEIGHT": "0.3"}
    )
    shared = readiness.shared_environment(
        bridge, {**bridge, "RERANKER_API_KEY": "DIFFERENT-SECRET"}
    )
    assert shared["RERANKER_API_KEY"] == "FAIL"
    assert shared["RAG_GENERATE_ANSWER"] == "NOT EXECUTED"
    assert "DO-NOT-PRINT" not in json.dumps(shared)
    assert "DIFFERENT-SECRET" not in json.dumps(shared)
    assert readiness.auth_matches(inventory[1], env)


@pytest.mark.parametrize(
    "name,value,expected",
    [
        ("RERANKER_MIX_MODE", "", True),
        ("RERANKER_MIX_MODE", "   ", True),
        ("RERANKER_MIX_WEIGHT", "", True),
        ("RERANKER_MIX_MODE", "false", False),
        ("RERANKER_MODE", "   ", False),
    ],
)
def test_bridge_empty_value_defaults_are_compared_exactly(
    inventory, name, value, expected
):
    query = load_query_settings(environment(migration.make_plan(*inventory)))
    bridge = {
        "RERANKER_MODE": "external",
        "RERANKER_API_URL": "http://reranker.test",
        name: value,
    }
    assert readiness.reranker_matches(query, bridge) == expected


def test_cli_safe_failure_exit_and_no_secret_output(tmp_path, monkeypatch, capsys):
    report = tmp_path / "report.json"
    report.write_text("{}")
    monkeypatch.setenv(
        "HAWKI_QUERY_MIGRATION_SOURCE_DSN",
        "postgresql://secret:DO-NOT-PRINT@invalid/db",
    )
    assert migration.main(["--dry-run", "--laravel-report", str(report)]) == 1
    assert "DO-NOT-PRINT" not in capsys.readouterr().out
    with pytest.raises(SystemExit) as error:
        migration.main([])
    assert error.value.code == 2


def test_status_updates_are_explicit_and_extras_refuse_all_writes(inventory, engine):
    plan = migration.make_plan(*inventory)
    with engine.begin() as connection:
        migration.apply_plan(connection, plan, plan.digest)
        connection.execute(
            datasets.update()
            .where(datasets.c.dataset_id == "a")
            .values(status="archived")
        )
        result = migration.apply_plan(connection, plan, plan.digest)
        assert result == {
            "datasets_inserted": 0,
            "datasets_updated": 1,
            "grants_inserted": 0,
        }
        connection.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="b",
                principal_type="service",
                principal_id="unreviewed",
                permission="query",
            )
        )
        with pytest.raises(migration.MigrationError):
            migration.apply_plan(connection, plan, plan.digest)


def test_readiness_never_accepts_implicit_query_all_and_checks_rollback(
    inventory, engine
):
    source, report = inventory
    report["query_all"] = True
    plan = migration.make_plan(source, report)
    with engine.begin() as connection:
        migration.apply_plan(connection, plan, plan.digest)
        result = readiness.check_readiness(
            connection,
            plan,
            report,
            environment(plan),
            inspect_containers=False,
            live=False,
            phase="prepare",
        )
    assert result["checks"]["discovery_coverage"] == "FAIL"
    assert result["checks"]["rollback_grants"] == "FAIL"
    assert result["checks"]["query_all_policy"] == "PASS"


@pytest.mark.parametrize(
    "mutation",
    ["none", "writable", "inside_repo", "shared", "parent_shared", "weak", "no_mount"],
)
def test_private_mount_gate(inventory, tmp_path, mutation):
    report = inventory[1]
    report["key"] = {
        "bits": 2048,
        "fingerprint": "public-only",
        "mount_path": "/run/secrets/query-bff/private.pem",
    }
    repository = tmp_path / "repo"
    source = tmp_path / "private"
    app = {
        "Name": "/hawki_rag_app",
        "Id": "app",
        "Mounts": [
            {
                "Source": str(source),
                "Destination": "/run/secrets/query-bff",
                "RW": False,
            }
        ],
    }
    other = {"Name": "/other", "Id": "other", "Mounts": []}
    if mutation == "writable":
        app["Mounts"][0]["RW"] = True
    elif mutation == "inside_repo":
        app["Mounts"][0]["Source"] = str(repository / "private")
    elif mutation == "shared":
        other["Mounts"] = [
            {"Source": str(source), "Destination": "/public", "RW": False}
        ]
    elif mutation == "parent_shared":
        other["Mounts"] = [
            {"Source": str(tmp_path), "Destination": "/public", "RW": False}
        ]
    elif mutation == "weak":
        report["key"]["bits"] = 1024
    elif mutation == "no_mount":
        app["Mounts"] = []
    if mutation in {"shared", "parent_shared"}:
        # With otherwise identical, valid metadata, removing only the source
        # overlap must make the topology safe.
        original_source = other["Mounts"][0]["Source"]
        other["Mounts"][0]["Source"] = "/unrelated-mount-source"
        assert readiness.mounted_key_isolated(report, [app, other], repository)
        other["Mounts"][0]["Source"] = original_source
    assert readiness.mounted_key_isolated(report, [app, other], repository) == (
        mutation == "none"
    )


def test_readiness_pass_requires_all_real_gates_and_no_secrets(
    inventory, engine, monkeypatch
):
    plan = migration.make_plan(*inventory)
    env = environment(plan)
    bridge = {name: "explicit-same-value" for name in readiness.REQUIRED_RAG}
    bridge.update(
        RERANKER_MODE="external",
        RERANKER_API_URL="http://reranker.test",
        RERANKER_API_KEY="SECRET-NEVER-EMITTED",
    )
    env.update(bridge)
    monkeypatch.setattr(
        readiness,
        "container_environment",
        lambda name: bridge if name == "bridge-id" else env,
    )
    monkeypatch.setattr(
        readiness,
        "bound_backend",
        lambda report: ("bound-id", "http://hawki_backend:80/v1/query"),
    )
    monkeypatch.setattr(
        readiness,
        "bound_bridge",
        lambda report: ("bridge-id", "http://hawki_rag_bridge:80/query"),
    )
    monkeypatch.setattr(
        readiness, "deployed_target_matches", lambda plan, backend_id: True
    )
    monkeypatch.setattr(readiness, "probe_storage", lambda backend_id: True)
    monkeypatch.setattr(readiness, "qdrant_routing_matches", lambda *args: True)
    monkeypatch.setattr(
        readiness,
        "live_gates",
        lambda report: {
            "private_key_mounts": "PASS",
            "https_jwks_refresh_key_match": "PASS",
            "live_parity_and_admission": "PASS",
        },
    )
    with engine.begin() as connection:
        migration.apply_plan(connection, plan, plan.digest)
        result = readiness.check_readiness(
            connection,
            plan,
            inventory[1],
            env,
            inspect_containers=True,
            live=True,
            phase="activate",
        )
        assert result["status"] == "PASS"
        assert "SECRET-NEVER-EMITTED" not in json.dumps(result)
        assert result["shared_names"]["qdrant_routing"] == "PASS"
        monkeypatch.setattr(readiness, "qdrant_routing_matches", lambda *args: False)
        routing_mismatch = readiness.check_readiness(
            connection,
            plan,
            inventory[1],
            env,
            inspect_containers=True,
            live=True,
            phase="activate",
        )
        assert routing_mismatch["status"] == "FAIL"
        assert routing_mismatch["checks"]["shared_environment"] == "FAIL"
        assert routing_mismatch["shared_names"]["qdrant_routing"] == "FAIL"
        assert routing_mismatch["checks"]["live_parity_and_admission"] == "NOT EXECUTED"
        monkeypatch.setattr(readiness, "qdrant_routing_matches", lambda *args: True)
        monkeypatch.setattr(readiness, "probe_storage", lambda backend_id: False)
        storage_mismatch = readiness.check_readiness(
            connection,
            plan,
            inventory[1],
            env,
            inspect_containers=True,
            live=True,
            phase="activate",
        )
        assert storage_mismatch["status"] == "FAIL"
        assert storage_mismatch["checks"]["storage_identity"] == "FAIL"
        assert storage_mismatch["checks"]["live_parity_and_admission"] == "NOT EXECUTED"
        monkeypatch.setattr(readiness, "probe_storage", lambda backend_id: True)
        monkeypatch.setattr(
            readiness,
            "bound_bridge",
            lambda report: (_ for _ in ()).throw(ValueError("conflicting bridge")),
        )
        mismatched = readiness.check_readiness(
            connection,
            plan,
            inventory[1],
            env,
            inspect_containers=True,
            live=True,
            phase="activate",
        )
        assert mismatched["status"] == "FAIL"
        assert mismatched["checks"]["legacy_bridge_target"] == "FAIL"
        assert mismatched["checks"]["live_parity_and_admission"] == "NOT EXECUTED"
        monkeypatch.setattr(
            readiness,
            "bound_bridge",
            lambda report: ("bridge-id", "http://hawki_rag_bridge:80/query"),
        )
        result = readiness.check_readiness(
            connection,
            plan,
            inventory[1],
            env,
            inspect_containers=True,
            live=False,
            phase="activate",
        )
        assert result["status"] == "FAIL"
        assert result["checks"]["live_parity_and_admission"] == "NOT EXECUTED"


@pytest.mark.parametrize(
    "name",
    ["QDRANT_HOST", "QDRANT_PORT", "QDRANT_SCHEME", "NEO4J_URI", "NEO4J_DATABASE"],
)
def test_runtime_bridge_storage_disagreement_blocks_readiness(
    inventory, engine, monkeypatch, name
):
    plan = migration.make_plan(*inventory)
    env = environment(plan)
    env.update({key: "same" for key in readiness.REQUIRED_RAG})
    env.update(RERANKER_MODE="external", RERANKER_API_URL="http://reranker.test")
    env[name] = "backend-value"
    bridge = {**env, name: "different-bridge-value"}
    monkeypatch.setattr(
        readiness,
        "bound_backend",
        lambda report: ("backend-id", "http://hawki_backend/v1/query"),
    )
    monkeypatch.setattr(
        readiness,
        "bound_bridge",
        lambda report: ("bridge-id", "http://hawki_rag_bridge/query"),
    )
    monkeypatch.setattr(
        readiness,
        "container_environment",
        lambda name: bridge if name == "bridge-id" else env,
    )
    monkeypatch.setattr(readiness, "deployed_target_matches", lambda *args: True)
    monkeypatch.setattr(readiness, "probe_storage", lambda *args: True)
    monkeypatch.setattr(readiness, "qdrant_routing_matches", lambda *args: True)
    monkeypatch.setattr(
        readiness,
        "live_gates",
        lambda report: pytest.fail("Mismatched storage must stop before live gates"),
    )
    with engine.begin() as connection:
        migration.apply_plan(connection, plan, plan.digest)
        result = readiness.check_readiness(
            connection,
            plan,
            inventory[1],
            env,
            inspect_containers=True,
            live=True,
            phase="activate",
        )
    assert result["status"] == "FAIL"
    assert result["checks"]["shared_environment"] == "FAIL"
    assert result["shared_names"][name] == "FAIL"
    assert result["checks"]["live_parity_and_admission"] == "NOT EXECUTED"


def test_runtime_target_digest_compares_exact_status_and_subject(
    inventory, monkeypatch
):
    import hashlib

    plan = migration.make_plan(*inventory)
    state = {
        "datasets": plan.dataset_status,
        "grants": sorted((d, "user", u) for d, u in plan.grants),
    }
    digest = hashlib.sha256(migration.canonical(state).encode()).hexdigest()
    monkeypatch.setattr(
        readiness, "run_json", lambda command: {"status": "PASS", "digest": digest}
    )
    assert readiness.deployed_target_matches(plan, "bound-id")
    plan.grants.add(("b", "2"))
    assert not readiness.deployed_target_matches(plan, "bound-id")


def test_cli_dry_run_apply_verify_and_idempotency_without_public_writes(
    inventory, engine, monkeypatch, tmp_path, capsys
):
    from types import SimpleNamespace

    source, report = inventory
    with engine.begin() as connection:
        for name, rows in source.items():
            table = sa.Table(
                name, sa.MetaData(), schema="public", autoload_with=connection
            )
            connection.execute(table.insert(), rows)
    statements = []

    @sa.event.listens_for(engine, "before_cursor_execute", retval=True)
    def guard(conn, cursor, statement, parameters, context, executemany):
        # PostgreSQL transaction setup is replaced only in this SQLite test.
        if statement.startswith("SET TRANSACTION"):
            return "SELECT 1", ()
        if statement.lstrip().split()[0].upper() in {"INSERT", "UPDATE", "DELETE"}:
            assert "public." not in statement
            statements.append(statement)
        return statement, parameters

    monkeypatch.setattr(
        migration.sa,
        "create_engine",
        lambda *a, **k: SimpleNamespace(connect=engine.connect, dispose=lambda: None),
    )
    monkeypatch.setenv(
        "HAWKI_QUERY_MIGRATION_SOURCE_DSN", "postgresql+psycopg://source.invalid/db"
    )
    monkeypatch.setenv("HAWKI_BACKEND_DB_DSN", "postgresql+psycopg://target.invalid/db")
    for key, value in environment(migration.make_plan(source, report)).items():
        monkeypatch.setenv(key, value)
    report_path = tmp_path / "report.json"
    report_path.write_text(json.dumps(report))
    common = ["--laravel-report", str(report_path)]
    assert migration.main([*common, "--dry-run"]) == 0
    result = json.loads(capsys.readouterr().out)
    assert result["changes"] == {
        "datasets_inserted": 4,
        "datasets_updated": 0,
        "grants_inserted": 2,
    }
    assert not statements
    assert migration.main([*common, "--verify"]) == 1
    assert json.loads(capsys.readouterr().out)["missing"]["datasets"]
    assert migration.main([*common, "--export-scope"]) == 0
    assert (
        json.loads(capsys.readouterr().out) == migration.make_plan(source, report).scope
    )
    assert migration.main([*common, "--verify-scope"]) == 0
    capsys.readouterr()
    apply_args = [*common, "--apply", "--confirm-plan", result["plan_sha256"]]
    assert migration.main(apply_args) == 0
    capsys.readouterr()
    assert migration.main(apply_args) == 0
    assert not any(json.loads(capsys.readouterr().out)["changes"].values())
    assert migration.main([*common, "--verify"]) == 0
    assert json.loads(capsys.readouterr().out)["status"] == "PASS"
