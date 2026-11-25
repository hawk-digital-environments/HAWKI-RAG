"""Real PostgreSQL ACL/locking tests in an explicitly isolated Docker fixture.

No roles or migrations are created. The existing pg_monitor role is used only
inside a networkless, tmpfs-only container labelled rawki.epoch15c-test=true.
Ordinary test runs skip unless RAG_EPOCH15C_PG_CONTAINER names that fixture.
"""

import json
import os
import subprocess
from types import SimpleNamespace

import pytest
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

from hawki_backend import readiness
from hawki_backend.persistence.schema import metadata
from test_readiness import environment


@pytest.fixture(scope="module")
def pg():
    name = os.environ.get("RAG_EPOCH15C_PG_CONTAINER")
    if not name:
        pytest.skip(
            "Set RAG_EPOCH15C_PG_CONTAINER to the isolated PostgreSQL fixture"
        )
    assert name.startswith("rawki-epoch15c-test-")
    result = subprocess.run(
        ["docker", "inspect", name],
        capture_output=True,
        text=True,
        check=True,
        timeout=10,
    )
    container = json.loads(result.stdout)[0]
    assert container["Config"]["Labels"].get("rawki.epoch15c-test") == "true"
    assert container["HostConfig"]["NetworkMode"] == "none"
    assert container["HostConfig"]["Tmpfs"].get("/var/lib/postgresql/data")
    assert all(mount["Type"] == "tmpfs" for mount in container["Mounts"])

    def sql(script, check=True):
        return subprocess.run(
            [
                "docker",
                "exec",
                "-i",
                name,
                "psql",
                "-X",
                "-qAt",
                "-v",
                "ON_ERROR_STOP=1",
                "-U",
                "postgres",
                "-d",
                "epoch15c_test",
            ],
            input=script,
            capture_output=True,
            text=True,
            check=check,
            timeout=30,
        )

    readiness.required_tables(environment())  # Register every runtime schema.
    ddl = "CREATE SCHEMA hawki_backend;\n" + "\n".join(
        str(sa.schema.CreateTable(table).compile(dialect=postgresql.dialect())) + ";"
        for table in metadata.sorted_tables
    )
    sql(ddl)
    yield sql
    sql("DROP SCHEMA hawki_backend CASCADE;")


def grant_runtime(sql, env=None):
    env = environment() if env is None else env
    statements = ["GRANT USAGE ON SCHEMA hawki_backend TO pg_monitor;"]
    for table, permissions in readiness.required_tables(env).items():
        statements.append(
            f"GRANT {','.join(sorted(permissions))} ON {table.fullname} TO pg_monitor;"
        )
    sql("\n".join(statements))


def verify_real_catalog(sql, env=None):
    """Batch the verifier's actual SELECTs, then consume the real PG answers."""
    queries = []
    env = environment() if env is None else env

    class Recorder:
        def execute(self, statement, params=None):
            queries.append((statement, params))
            return SimpleNamespace(scalar_one=lambda: "'CREATE'" not in str(statement))

    readiness.verify_privileges(Recorder(), env)
    script = ["BEGIN READ ONLY; SET ROLE pg_monitor;"]
    for statement, params in queries:
        bound = statement.bindparams(**(params or {}))
        script.append(
            str(
                bound.compile(
                    dialect=postgresql.dialect(), compile_kwargs={"literal_binds": True}
                )
            )
            + ";"
        )
    script.append("ROLLBACK;")
    answers = iter(sql("\n".join(script)).stdout.splitlines())

    class Catalog:
        def execute(self, statement, params=None):
            answer = next(answers)
            assert answer in {"t", "f"}
            return SimpleNamespace(scalar_one=lambda: answer == "t")

    readiness.verify_privileges(Catalog(), env)


@pytest.mark.parametrize(
    "table", ["dataset_grants", "managed_document_events", "url_ingestion_events"]
)
def test_postgres_rejects_missing_lock_privilege_and_readiness_agrees(pg, table):
    grant_runtime(pg)
    pg(f"REVOKE UPDATE ON hawki_backend.{table} FROM pg_monitor;")
    result = pg(
        f"BEGIN; SET ROLE pg_monitor; SELECT * FROM hawki_backend.{table} FOR UPDATE; ROLLBACK;",
        check=False,
    )
    assert result.returncode != 0
    assert "permission denied" in result.stderr
    with pytest.raises(ValueError, match="privilege"):
        verify_real_catalog(pg)
    pg(f"GRANT UPDATE ON hawki_backend.{table} TO pg_monitor;")
    pg(
        f"BEGIN; SET ROLE pg_monitor; SELECT * FROM hawki_backend.{table} FOR UPDATE; ROLLBACK;"
    )
    verify_real_catalog(pg)


def test_complete_cutover_runtime_privileges_pass_real_postgres(pg):
    grant_runtime(pg)
    verify_real_catalog(pg)


def test_database_authority_keeps_dependencies_without_ingestion(pg):
    from hawki_backend.deployment import ENABLE_FLAGS

    env = environment() | dict.fromkeys(ENABLE_FLAGS, "false")
    pg("REVOKE ALL ON ALL TABLES IN SCHEMA hawki_backend FROM pg_monitor;")
    grant_runtime(pg, env)
    verify_real_catalog(pg, env)
    pg(
        "SET ROLE pg_monitor; SELECT * FROM hawki_backend.ingest_text_operations FOR UPDATE; SELECT * FROM hawki_backend.managed_document_revisions FOR UPDATE; SELECT * FROM hawki_backend.url_ingestion_generations FOR UPDATE;"
    )


@pytest.mark.parametrize("function", ["pg_try_advisory_lock", "pg_advisory_unlock"])
@pytest.mark.parametrize("wrong_overload", [False, True])
def test_managed_only_checks_effective_bigint_function_privilege(
    pg, function, wrong_overload
):
    from hawki_backend.deployment import ENABLE_FLAGS

    env = (
        environment()
        | dict.fromkeys(ENABLE_FLAGS, "false")
        | {"RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED": "true"}
    )
    grant_runtime(pg, env)
    signature = "pg_catalog." + function + "(bigint)"
    pg(f"REVOKE EXECUTE ON FUNCTION {signature} FROM PUBLIC, pg_monitor;")
    try:
        if wrong_overload:
            pg(
                f"GRANT EXECUTE ON FUNCTION pg_catalog.{function}(integer,integer) TO pg_monitor;"
            )
        failed = pg(
            f"SET ROLE pg_monitor; SELECT pg_catalog.{function}(17::bigint);",
            check=False,
        )
        assert failed.returncode != 0 and "permission denied" in failed.stderr
        with pytest.raises(ValueError, match="function"):
            verify_real_catalog(pg, env)
        pg(f"GRANT EXECUTE ON FUNCTION {signature} TO pg_monitor;")
        verify_real_catalog(pg, env)
    finally:
        pg(f"GRANT EXECUTE ON FUNCTION {signature} TO PUBLIC;")


def test_managed_only_all_function_privileges_pass(pg):
    from hawki_backend.deployment import ENABLE_FLAGS

    env = (
        environment()
        | dict.fromkeys(ENABLE_FLAGS, "false")
        | {"RAG_BACKEND_MANAGED_DOCUMENTS_ENABLED": "true"}
    )
    grant_runtime(pg, env)
    verify_real_catalog(pg, env)
