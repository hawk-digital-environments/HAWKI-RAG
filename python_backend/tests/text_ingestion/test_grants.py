"""Operator-only grant migration: exact identities and transactional changes."""

import copy
import importlib.util
import json
import sys
from pathlib import Path

import pytest
import sqlalchemy as sa

from hawki_backend.persistence.schema import datasets, dataset_grants

spec = importlib.util.spec_from_file_location(
    "ingest_grants", Path(__file__).resolve().parents[2] / "scripts/ingest_grants.py"
)
grants = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = grants
spec.loader.exec_module(grants)


def source():
    return {
        "datasets": [{"dataset_id": "dataset-a", "status": "active"}],
        "users": [{"id": 42, "isRemoved": False}, {"id": 43, "isRemoved": True}],
        "dataset_grants": [
            {
                "dataset_id": "dataset-a",
                "principal_type": "user",
                "principal_id": "42",
                "permission": "ingest",
            },
            {
                "dataset_id": "dataset-a",
                "principal_type": "user",
                "principal_id": "43",
                "permission": "ingest",
            },
        ],
    }


def test_exact_plan_and_active_users():
    plan = grants.make_plan(source())
    assert plan.grants == {("dataset-a", "42")}
    assert plan.skipped_removed == {("dataset-a", "43")}
    assert plan.desired()["grants"] == [
        {
            "tenant_id": "default",
            "dataset_id": "dataset-a",
            "principal_type": "user",
            "principal_id": "42",
            "permission": "ingest",
        }
    ]
    assert plan.digest == grants.make_plan(copy.deepcopy(source())).digest


@pytest.mark.parametrize(
    "change",
    [
        "wildcard",
        "unknown_dataset",
        "ambiguous_user",
        "duplicate_grant",
        "service",
        "unknown_permission",
    ],
)
def test_ambiguous_or_wildcard_sources_refused(change):
    value = source()
    row = value["dataset_grants"][0]
    if change == "wildcard":
        row["principal_id"] = "*"
    if change == "unknown_dataset":
        row["dataset_id"] = "missing"
    if change == "ambiguous_user":
        value["users"][0]["id"] = "042"
    if change == "duplicate_grant":
        value["dataset_grants"].append(dict(row))
    if change == "service":
        row["principal_type"] = "service"
    if change == "unknown_permission":
        row["permission"] = "all"
    with pytest.raises(grants.MigrationError):
        grants.make_plan(value)


def test_apply_verify_rerun_and_preserve_target_extras(env):
    plan = grants.make_plan(source())
    with env.engine.begin() as conn:
        conn.execute(dataset_grants.delete())
        changes = grants.apply_plan(conn, plan, plan.digest)
        assert changes["grants_inserted"] == 1
    with env.engine.begin() as conn:
        assert grants.apply_plan(conn, plan, plan.digest) == {
            "datasets_inserted": 0,
            "datasets_updated": 0,
            "grants_inserted": 0,
        }
        conn.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="dataset-a",
                principal_type="user",
                principal_id="99",
                permission="ingest",
            )
        )
        diff = grants.compare_target(conn, plan)
        assert diff["extra"]["grants"][0]["principal_id"] == "99"
        grants.apply_plan(conn, plan, plan.digest)
        assert grants.compare_target(conn, plan)["extra"] == diff["extra"]


def test_application_rolls_back_atomically(env):
    plan = grants.make_plan(source())
    with env.engine.begin() as conn:
        conn.execute(dataset_grants.delete())
    with pytest.raises(RuntimeError), env.engine.begin() as conn:
        grants.apply_plan(conn, plan, plan.digest)
        raise RuntimeError("simulated commit failure")
    with env.engine.connect() as conn:
        assert grants.compare_target(conn, plan)["status"] == "FAIL"


def test_wrong_confirmation_and_other_tenant_fail_closed(env):
    plan = grants.make_plan(source())
    with env.engine.begin() as conn:
        with pytest.raises(grants.MigrationError):
            grants.apply_plan(conn, plan, "wrong")
        conn.execute(
            datasets.insert().values(
                tenant_id="other",
                dataset_id="dataset-a",
                status="active",
                name="Dataset A",
                qdrant_collection="hawki_dataset_a_other",
                neo4j_namespace="hawki_dataset_a_other",
                embedding_provider="ollama",
                embedding_model="bge-m3",
            )
        )
        with pytest.raises(grants.MigrationError):
            grants.compare_target(conn, plan)


@pytest.mark.parametrize("mode", ["--dry-run", "--apply", "--verify"])
def test_cli_modes_and_safe_errors(mode, monkeypatch, capsys):
    monkeypatch.setenv(
        "HAWKI_INGEST_MIGRATION_SOURCE_DSN",
        "postgresql+psycopg://secret:password@internal/private",
    )

    def fail(*args):
        raise RuntimeError("secret password private")

    monkeypatch.setattr(grants, "_engine", fail)
    assert grants.main([mode]) == 1
    result = json.loads(capsys.readouterr().out)
    assert result["status"] == "FAIL"
    assert "password" not in result["error"]
    assert "secret" not in result["error"]


@pytest.mark.parametrize("status", [None, "deleted", "archived"])
def test_dataset_inventory_is_prerequisite_and_never_repaired(env, status):
    plan = grants.make_plan(source())
    with env.engine.begin() as conn:
        conn.execute(dataset_grants.delete())
        if status is None:
            conn.execute(datasets.delete())
        else:
            conn.execute(datasets.update().values(status=status))
    with env.engine.begin() as conn:
        before = [dict(row) for row in conn.execute(sa.select(datasets)).mappings()]
        assert grants.compare_target(conn, plan)["status"] == "FAIL"
        with pytest.raises(grants.MigrationError, match="prerequisite"):
            grants.apply_plan(conn, plan, plan.digest)
        assert [
            dict(row) for row in conn.execute(sa.select(datasets)).mappings()
        ] == before
        assert (
            conn.execute(
                sa.select(sa.func.count()).select_from(dataset_grants)
            ).scalar_one()
            == 0
        )


def test_apply_only_writes_grants_and_verify_only_reads(env):
    plan = grants.make_plan(source())
    with env.engine.begin() as conn:
        conn.execute(dataset_grants.delete())
    statements = []
    sa.event.listen(
        env.engine,
        "before_cursor_execute",
        lambda conn, cursor, statement, parameters, context, executemany: (
            statements.append(statement)
        ),
    )
    with env.engine.begin() as conn:
        grants.apply_plan(conn, plan, plan.digest)
    mutations = [
        sql.lower()
        for sql in statements
        if sql.lstrip().lower().startswith(("insert", "update", "delete"))
    ]
    assert mutations and all("dataset_grants" in sql for sql in mutations)
    statements.clear()
    with env.engine.connect() as conn:
        assert grants.compare_target(conn, plan)["status"] == "PASS"
    assert not any(
        sql.lstrip().lower().startswith(("insert", "update", "delete"))
        for sql in statements
    )
