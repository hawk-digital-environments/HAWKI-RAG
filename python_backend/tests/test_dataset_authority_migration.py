"""Operator migration tests for the one-time dataset-authority importer.

The tool is tested against attached SQLite schemas. No production connection
is opened and no Laravel table is written.
"""

from __future__ import annotations

import copy
from datetime import datetime, timedelta, timezone
import json
import sys
from pathlib import Path
from types import SimpleNamespace

import pytest
import sqlalchemy as sa

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import dataset_authority as migration  # noqa: E402
from hawki_backend.persistence.schema import (  # noqa: E402
    dataset_grant_tokens,
    dataset_grants,
    datasets,
    metadata,
)

NOW = datetime(2026, 11, 10, 12, 0, tzinfo=timezone.utc)
HASH_A = "a" * 64
HASH_B = "b" * 64


def _source_rows() -> dict[str, list[dict[str, object]]]:
    return {
        "datasets": [
            {
                "dataset_id": "a",
                "name": "Dataset A",
                "description": "first",
                "status": "active",
                "qdrant_collection": "hawki_a",
                "neo4j_namespace": "hawki_a",
                "embedding_provider": "ollama",
                "embedding_model": "bge-m3",
                "created_at": NOW,
            },
            {
                "dataset_id": "old",
                "name": "Old",
                "description": None,
                "status": "archived",
                "qdrant_collection": "hawki_old",
                "neo4j_namespace": None,
                "embedding_provider": "ollama",
                "embedding_model": "bge-m3",
                "created_at": NOW + timedelta(minutes=1),
            },
            {
                "dataset_id": "gone",
                "name": "Gone",
                "description": None,
                "status": "deleted",
                "qdrant_collection": "hawki_gone",
                "neo4j_namespace": None,
                "embedding_provider": "ollama",
                "embedding_model": "bge-m3",
                "created_at": NOW + timedelta(minutes=2),
            },
        ],
        "dataset_grants": [
            {
                "dataset_id": "a",
                "principal_type": "user",
                "principal_id": "1",
                "permission": "query",
            },
            {
                "dataset_id": "a",
                "principal_type": "user",
                "principal_id": "1",
                "permission": "ingest",
            },
            {
                "dataset_id": "a",
                "principal_type": "service",
                "principal_id": "hawki-bff",
                "permission": "query",
            },
        ],
        "dataset_grant_tokens": [
            {
                "dataset_id": "a",
                "token_hash": HASH_A,
                "expires_at": NOW + timedelta(hours=24),
                "consumed_at": None,
                "created_at": NOW,
                "updated_at": NOW,
            },
            {
                "dataset_id": "a",
                "token_hash": HASH_B,
                "expires_at": NOW + timedelta(hours=12),
                "consumed_at": NOW + timedelta(minutes=5),
                "created_at": NOW,
                "updated_at": NOW + timedelta(minutes=5),
            },
        ],
    }


def _report() -> dict[str, object]:
    return {
        "version": 1,
        "tenant": "default",
        "query_all": False,
        "datasets": [
            {"dataset_id": "a", "graph_enabled": True},
            {"dataset_id": "old", "graph_enabled": False},
            {"dataset_id": "gone", "graph_enabled": False},
        ],
    }


@pytest.fixture
def engine():
    engine = sa.create_engine("sqlite://")
    with engine.begin() as connection:
        connection.execute(sa.text("ATTACH DATABASE ':memory:' AS hawki_backend"))
        connection.execute(sa.text("ATTACH DATABASE ':memory:' AS public"))
        metadata.create_all(connection)
        connection.execute(
            sa.text(
                "CREATE TABLE public.datasets ("
                "dataset_id TEXT, name TEXT, description TEXT, status TEXT, "
                "qdrant_collection TEXT, neo4j_namespace TEXT, "
                "embedding_provider TEXT, embedding_model TEXT, "
                "created_at DATETIME)"
            )
        )
        connection.execute(
            sa.text(
                "CREATE TABLE public.dataset_grants ("
                "dataset_id TEXT, principal_type TEXT, principal_id TEXT, "
                "permission TEXT)"
            )
        )
        connection.execute(
            sa.text(
                "CREATE TABLE public.dataset_grant_tokens ("
                "dataset_id TEXT, token_hash TEXT, expires_at DATETIME, "
                "consumed_at DATETIME, created_at DATETIME, updated_at DATETIME)"
            )
        )
    yield engine
    engine.dispose()


def _load_source(connection, rows) -> None:
    for name, values in rows.items():
        table = sa.Table(name, sa.MetaData(), schema="public", autoload_with=connection)
        connection.execute(table.insert(), values)


def _plan(rows=None, report=None) -> migration.Plan:
    return migration.make_plan(rows or _source_rows(), report)


def _apply(connection, plan: migration.Plan) -> dict[str, int]:
    bound = plan.bind_target(connection)
    return migration.apply_plan(connection, bound, bound.digest)


def _placeholder(dataset_id: str, **overrides) -> dict[str, object]:
    values: dict[str, object] = {
        "tenant_id": "default",
        "dataset_id": dataset_id,
        "name": dataset_id,
        "description": None,
        "status": "active",
        "qdrant_collection": migration._placeholder_collection(dataset_id),
        "neo4j_namespace": None,
        "embedding_provider": "ollama",
        "embedding_model": "bge-m3",
        "graph_enabled": False,
        "created_at": NOW,
        "updated_at": NOW,
    }
    values.update(overrides)
    return values


def test_plan_is_deterministic_and_preserves_exact_identities() -> None:
    first = _plan(report=_report())
    second = _plan(report=copy.deepcopy(_report()))

    assert first.digest == second.digest
    assert [row["dataset_id"] for row in first.datasets] == ["a", "gone", "old"]
    assert first.datasets[1]["status"] == "deleted"
    assert first.datasets[1]["deleted_at"] == first.datasets[1]["created_at"]
    assert first.datasets[0]["graph_enabled"] is True
    assert first.datasets[2]["graph_enabled"] is False
    assert first.summary()["counts"] == {
        "datasets": 3,
        "active_datasets": 1,
        "grants": 3,
        "tokens": 2,
    }
    assert first.grants[0] == {
        "tenant_id": "default",
        "dataset_id": "a",
        "principal_type": "service",
        "principal_id": "hawki-bff",
        "permission": "query",
    }
    assert first.tokens[0]["token_hash"] == HASH_A
    assert migration.canonical(first.desired()) == migration.canonical(second.desired())


def test_graph_readiness_defaults_to_disabled_without_the_reviewed_report() -> None:
    plan = _plan()

    assert all(row["graph_enabled"] is False for row in plan.datasets)


def test_the_source_reader_uses_only_public_tables_and_checks_tenants(engine) -> None:
    rows = _source_rows()
    with engine.begin() as connection:
        _load_source(connection, rows)
        read = migration.read_source(connection)

    assert migration.make_plan(read, _report()).digest == _plan(report=_report()).digest
    with engine.begin() as connection:
        connection.execute(
            sa.text(
                "ALTER TABLE public.datasets ADD COLUMN tenant_id TEXT DEFAULT 'other'"
            )
        )
        with pytest.raises(migration.MigrationError):
            migration.read_source(connection)


def test_apply_is_idempotent_and_never_writes_public(engine) -> None:
    plan = _plan(report=_report())
    statements: list[str] = []

    @sa.event.listens_for(engine, "before_cursor_execute")
    def guard(conn, cursor, statement, parameters, context, executemany):
        if statement.lstrip().split()[0].upper() in {"INSERT", "UPDATE", "DELETE"}:
            assert "public." not in statement
            statements.append(statement)

    with engine.begin() as connection:
        changes = _apply(connection, plan)

        assert changes == {
            "datasets_inserted": 3,
            "datasets_updated": 0,
            "grants_inserted": 3,
            "tokens_inserted": 2,
        }
        assert migration.compare_target(connection, plan)["status"] == "PASS"
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(datasets)
            ).scalar_one()
            == 3
        )
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(dataset_grant_tokens)
            ).scalar_one()
            == 2
        )
    assert all("hawki_backend." in statement for statement in statements)
    with engine.begin() as connection:
        rebound = plan.bind_target(connection)
        assert not any(rebound.transitions.values())
        assert not any(
            migration.apply_plan(connection, rebound, rebound.digest).values()
        )


def test_apply_updates_only_exact_revision_0007_placeholders(engine) -> None:
    plan = _plan(report=_report())
    with engine.begin() as connection:
        connection.execute(
            datasets.insert().values(
                tenant_id="default",
                dataset_id="a",
                name="a",
                status="active",
                qdrant_collection=migration._placeholder_collection("a"),
                neo4j_namespace=None,
                embedding_provider="ollama",
                embedding_model="bge-m3",
                graph_enabled=False,
                created_at=NOW,
                updated_at=NOW,
            )
        )

    with engine.begin() as connection:
        changes = _apply(connection, plan)

    assert changes["datasets_updated"] == 1
    assert changes["datasets_inserted"] == 2
    with engine.begin() as connection:
        row = (
            connection.execute(sa.select(datasets).where(datasets.c.dataset_id == "a"))
            .mappings()
            .one()
        )
    assert row["name"] == "Dataset A"
    assert row["qdrant_collection"] == "hawki_a"


def test_verify_detects_missing_extra_and_mismatched_rows(engine) -> None:
    plan = _plan(report=_report())
    with engine.begin() as connection:
        _apply(connection, plan)
        connection.execute(
            dataset_grants.delete().where(dataset_grants.c.permission == "ingest")
        )
        connection.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="a",
                principal_type="user",
                principal_id="unexpected",
                permission="query",
            )
        )
        connection.execute(
            datasets.update().where(datasets.c.dataset_id == "old").values(name="Other")
        )
        result = migration.compare_target(connection, plan)

    assert result["status"] == "FAIL"
    assert result["missing"]["grants"] == [["a", "user", "1", "ingest"]]
    assert result["extra"]["grants"] == [["a", "user", "unexpected", "query"]]
    assert result["mismatched"]["datasets"] == [
        {"dataset_id": "old", "fields": ["name"]}
    ]


def test_apply_refuses_extras_and_mismatched_tokens(engine) -> None:
    plan = _plan(report=_report())
    with engine.begin() as connection:
        _apply(connection, plan)
        connection.execute(
            dataset_grant_tokens.insert().values(
                token_hash="c" * 64,
                tenant_id="default",
                dataset_id="a",
                expires_at=NOW + timedelta(hours=1),
                created_at=NOW,
                updated_at=NOW,
            )
        )
        with pytest.raises(migration.MigrationError):
            plan.bind_target(connection)

    with engine.begin() as connection:
        connection.execute(dataset_grant_tokens.delete())
        _apply(connection, plan)
        connection.execute(
            dataset_grant_tokens.update()
            .where(dataset_grant_tokens.c.token_hash == HASH_A)
            .values(expires_at=NOW + timedelta(hours=48))
        )
        with pytest.raises(migration.MigrationError):
            plan.bind_target(connection)


def test_a_wrong_confirmation_never_writes(engine) -> None:
    plan = _plan(report=_report())

    with engine.begin() as connection, pytest.raises(migration.MigrationError):
        bound = plan.bind_target(connection)
        migration.apply_plan(connection, bound, "unreviewed")

    with engine.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(datasets)
            ).scalar_one()
            == 0
        )


def test_a_mid_apply_failure_rolls_back_the_transaction(engine) -> None:
    plan = _plan(report=_report())

    @sa.event.listens_for(engine, "before_cursor_execute")
    def fail(conn, cursor, statement, parameters, context, executemany):
        if statement.startswith("INSERT INTO hawki_backend.dataset_grant_tokens"):
            raise RuntimeError("injected token failure")

    with pytest.raises(RuntimeError), engine.begin() as connection:
        _apply(connection, plan)
    with engine.connect() as connection:
        assert (
            connection.execute(
                sa.select(sa.func.count()).select_from(datasets)
            ).scalar_one()
            == 0
        )


@pytest.mark.parametrize(
    "mutation",
    [
        "wildcard",
        "whitespace",
        "service",
        "duplicate_grant",
        "unknown_dataset",
        "unknown_status",
        "duplicate_scope",
        "uppercase_hash",
        "duplicate_token",
        "report_drift",
        "report_graph",
        "report_tenant",
    ],
)
def test_malformed_source_fails_closed(mutation: str) -> None:
    rows = copy.deepcopy(_source_rows())
    report = _report()
    if mutation == "wildcard":
        rows["dataset_grants"][0]["principal_id"] = "*"
    elif mutation == "whitespace":
        rows["dataset_grants"][0]["principal_id"] = " 1 "
    elif mutation == "service":
        rows["dataset_grants"][0]["principal_type"] = "robot"
    elif mutation == "duplicate_grant":
        rows["dataset_grants"].append(copy.deepcopy(rows["dataset_grants"][0]))
    elif mutation == "unknown_dataset":
        rows["dataset_grants"][0]["dataset_id"] = "missing"
    elif mutation == "unknown_status":
        rows["datasets"][0]["status"] = "paused"
    elif mutation == "duplicate_scope":
        rows["datasets"][1]["qdrant_collection"] = "hawki_a"
    elif mutation == "uppercase_hash":
        rows["dataset_grant_tokens"][0]["token_hash"] = "A" * 64
    elif mutation == "duplicate_token":
        rows["dataset_grant_tokens"].append(
            copy.deepcopy(rows["dataset_grant_tokens"][0])
        )
    elif mutation == "report_drift":
        report["datasets"].pop()
    elif mutation == "report_graph":
        report["datasets"][0]["graph_enabled"] = "true"
    else:
        report["tenant"] = "other"

    with pytest.raises(migration.MigrationError):
        migration.make_plan(rows, report)


def test_a_token_cannot_be_consumed_before_it_was_created() -> None:
    rows = copy.deepcopy(_source_rows())
    rows["dataset_grant_tokens"][0]["consumed_at"] = NOW - timedelta(seconds=1)

    with pytest.raises(migration.MigrationError):
        migration.make_plan(rows, _report())


def test_plan_digest_binds_source_rows_and_target_snapshot(engine) -> None:
    plan = _plan(report=_report())
    rows = _source_rows()
    rows["datasets"][0]["name"] = "  Dataset A  "

    assert migration.make_plan(rows, _report()).digest != plan.digest

    with engine.begin() as connection:
        empty = plan.bind_target(connection)
    with engine.begin() as connection:
        connection.execute(datasets.insert().values(**_placeholder("a")))
    with engine.begin() as connection:
        with_placeholder = plan.bind_target(connection)

    assert with_placeholder.digest != empty.digest
    assert with_placeholder.transitions["datasets_updated"][0]["dataset_id"] == "a"


def test_apply_refuses_canonical_target_metadata(engine) -> None:
    plan = _plan(report=_report())
    with engine.begin() as connection:
        connection.execute(
            datasets.insert().values(**_placeholder("a", name="Edited canonical name"))
        )

    with engine.begin() as connection, pytest.raises(migration.MigrationError):
        plan.bind_target(connection)


def test_apply_refuses_archived_to_active_transition(engine) -> None:
    plan = _plan(report=_report())
    with engine.begin() as connection:
        connection.execute(
            datasets.insert().values(**_placeholder("a", status="archived"))
        )

    with engine.begin() as connection, pytest.raises(migration.MigrationError):
        plan.bind_target(connection)


def test_apply_refuses_an_archived_target_even_when_the_source_is_archived(
    engine,
) -> None:
    plan = _plan(report=_report())
    with engine.begin() as connection:
        connection.execute(
            datasets.insert().values(**_placeholder("old", status="archived"))
        )

    with engine.begin() as connection, pytest.raises(migration.MigrationError):
        plan.bind_target(connection)


def test_verify_detects_tombstone_revival_and_apply_refuses_it(engine) -> None:
    plan = _plan(report=_report())
    with engine.begin() as connection:
        connection.execute(
            datasets.insert().values(
                **_placeholder(
                    "a",
                    name="Dataset A",
                    description="first",
                    qdrant_collection="hawki_a",
                    neo4j_namespace="hawki_a",
                    graph_enabled=True,
                    status="deleted",
                    deleted_at=NOW,
                )
            )
        )
        result = migration.compare_target(connection, plan)

    assert result["status"] == "FAIL"
    assert result["revived"]["datasets"] == ["a"]
    assert result["mismatched"]["datasets"] == [
        {"dataset_id": "a", "fields": ["status", "deleted_at"]}
    ]

    with engine.begin() as connection, pytest.raises(migration.MigrationError):
        plan.bind_target(connection)


def test_apply_refuses_a_target_that_changed_after_binding(engine) -> None:
    plan = _plan(report=_report())
    with engine.begin() as connection:
        bound = plan.bind_target(connection)
        connection.execute(datasets.insert().values(**_placeholder("extra")))
        with pytest.raises(migration.MigrationError):
            migration.apply_plan(connection, bound, bound.digest)


@pytest.mark.parametrize("query_all", [True, "false", None])
def test_query_all_must_be_explicitly_false(query_all) -> None:
    report = _report()
    report["query_all"] = query_all

    with pytest.raises(migration.MigrationError):
        migration.make_plan(_source_rows(), report)


def test_apply_requires_the_reviewed_query_all_transition(engine) -> None:
    plan = _plan()  # no reviewed Laravel report
    assert plan.query_all is None
    with engine.begin() as connection:
        bound = plan.bind_target(connection)
        with pytest.raises(migration.MigrationError):
            migration.apply_plan(connection, bound, bound.digest)


def test_cli_plan_apply_verify_and_safe_failures(
    engine, monkeypatch, tmp_path, capsys
) -> None:
    rows = _source_rows()
    with engine.begin() as connection:
        _load_source(connection, rows)
    plan = migration.make_plan(rows, _report())

    @sa.event.listens_for(engine, "before_cursor_execute", retval=True)
    def guard(conn, cursor, statement, parameters, context, executemany):
        if statement.startswith("SET TRANSACTION"):
            return "SELECT 1", ()
        return statement, parameters

    monkeypatch.setattr(
        migration.sa,
        "create_engine",
        lambda *args, **kwargs: SimpleNamespace(
            connect=engine.connect, dispose=lambda: None
        ),
    )
    monkeypatch.setenv(
        "HAWKI_DATASET_MIGRATION_SOURCE_DSN",
        "postgresql+psycopg://source.invalid/db",
    )
    monkeypatch.delenv("HAWKI_BACKEND_DB_DSN", raising=False)
    report_path = tmp_path / "report.json"
    report_path.write_text(json.dumps(_report()))

    assert migration.main(["--plan", "--laravel-report", str(report_path)]) == 0
    output = json.loads(capsys.readouterr().out)
    assert output["plan_sha256"] == plan.digest
    assert output["target_check"] == "NOT EXECUTED"

    monkeypatch.setenv("HAWKI_BACKEND_DB_DSN", "postgresql+psycopg://target.invalid/db")
    assert migration.main(["--plan", "--laravel-report", str(report_path)]) == 1
    target_plan = json.loads(capsys.readouterr().out)
    assert target_plan["target_check"] == "FAIL"
    assert target_plan["plan_sha256"] != plan.digest

    apply_args = [
        "--apply",
        "--laravel-report",
        str(report_path),
        "--confirm-plan",
        target_plan["plan_sha256"],
    ]
    assert migration.main(apply_args) == 0
    assert json.loads(capsys.readouterr().out)["status"] == "PASS"

    # The target changed, so the old digest must never apply again.
    assert migration.main(apply_args) == 1
    stale = json.loads(capsys.readouterr().out)
    assert stale["status"] == "FAIL"
    assert "plan_sha256" not in stale

    assert migration.main(["--plan", "--laravel-report", str(report_path)]) == 0
    settled = json.loads(capsys.readouterr().out)
    assert settled["target_check"] == "PASS"
    assert settled["plan_sha256"] != target_plan["plan_sha256"]
    assert not any(settled["changes"].values())
    assert (
        migration.main(
            [
                "--apply",
                "--laravel-report",
                str(report_path),
                "--confirm-plan",
                settled["plan_sha256"],
            ]
        )
        == 0
    )
    assert not any(json.loads(capsys.readouterr().out)["changes"].values())

    assert migration.main(["--verify", "--laravel-report", str(report_path)]) == 0
    assert json.loads(capsys.readouterr().out)["status"] == "PASS"

    monkeypatch.setenv(
        "HAWKI_DATASET_MIGRATION_SOURCE_DSN",
        "postgresql+psycopg://secret:DO-NOT-PRINT@invalid/db",
    )
    monkeypatch.setenv(
        "HAWKI_BACKEND_DB_DSN",
        "postgresql+psycopg://secret:DO-NOT-PRINT@invalid/db",
    )
    monkeypatch.setattr(
        migration.sa,
        "create_engine",
        lambda *args, **kwargs: (_ for _ in ()).throw(RuntimeError("DO-NOT-PRINT")),
    )
    assert migration.main(["--plan", "--laravel-report", str(report_path)]) == 1
    assert "DO-NOT-PRINT" not in capsys.readouterr().out
