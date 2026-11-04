"""Explicit, one-time ingest-grant backfill. Never imported by the runtime.

The tool copies the legacy ``dataset_grants`` rows with ``permission='ingest'``
into the Python-owned ``hawki_backend`` schema. The source connection must be
SELECT-only. No DDL, deletes, Laravel writes or environment-file writes are
performed.

The plan is explicit and idempotent:

* only exact ``user`` principals on exact source datasets are copied;
* only active users receive a grant, matching the legacy principal rule;
* a wildcard, unknown, duplicated or ambiguous source row fails the plan;
* target grants that the plan does not name are reported, never deleted;
* ``--apply`` requires the reviewed ``plan_sha256`` and verifies afterwards.

The production backfill remains an activation prerequisite; this tool does not
run it, and it does not switch a deployment.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
from dataclasses import dataclass
from typing import Any

import sqlalchemy as sa
from sqlalchemy.engine import Connection

from hawki_backend.persistence.schema import dataset_grants, datasets

TENANT = "default"
TARGET_SCHEMA = "hawki_backend"
PERMISSION = "ingest"
SOURCE_SCHEMA = "public"
DATASET_STATUSES = {"active", "archived", "deleted"}
ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\Z")


class MigrationError(Exception):
    """Safe operator error. Never include raw DB/config exceptions."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise MigrationError(message)


def canonical(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"))


def read_source(connection: Connection) -> dict[str, list[dict[str, Any]]]:
    """Read the legacy tables as a SELECT-only migration source."""
    columns = {
        "datasets": ["dataset_id", "status"],
        "dataset_grants": [
            "dataset_id",
            "principal_type",
            "principal_id",
            "permission",
        ],
        "users": ["id", "isRemoved"],
    }
    result: dict[str, list[dict[str, Any]]] = {}
    inspector = sa.inspect(connection)
    for name, selected in columns.items():
        table = sa.Table(
            name, sa.MetaData(), schema=SOURCE_SCHEMA, autoload_with=connection
        )
        available = {
            column["name"]
            for column in inspector.get_columns(name, schema=SOURCE_SCHEMA)
        }
        require(
            set(selected) <= available,
            f"Source table {name} is missing a required column; stop migration.",
        )
        # A new tenant-aware source must never be silently flattened.
        for column in inspector.get_columns(name, schema=SOURCE_SCHEMA):
            if "tenant" in column["name"].lower():
                selected = [*selected, column["name"]]
        rows = [
            dict(row)
            for row in connection.execute(
                sa.select(*(table.c[c] for c in selected))
            ).mappings()
        ]
        for row in rows:
            for key, value in row.items():
                if "tenant" in key.lower():
                    require(
                        value == TENANT,
                        "Source has non-canonical or ambiguous tenant identity; stop migration.",
                    )
        result[name] = rows
    return result


@dataclass
class Plan:
    """The exact set of legacy ingest grants that must exist in Python."""

    datasets: dict[str, str]
    grants: set[tuple[str, str]]
    skipped_removed: set[tuple[str, str]]

    def desired(self) -> dict[str, Any]:
        return {
            "tenant": TENANT,
            "datasets": self.datasets,
            "grants": self.grant_rows(self.grants),
        }

    @property
    def digest(self) -> str:
        return hashlib.sha256(canonical(self.desired()).encode()).hexdigest()

    @staticmethod
    def grant_rows(grants: set[tuple[str, str]]) -> list[dict[str, str]]:
        return [
            {
                "tenant_id": TENANT,
                "dataset_id": dataset,
                "principal_type": "user",
                "principal_id": principal,
                "permission": PERMISSION,
            }
            for dataset, principal in sorted(grants)
        ]

    def summary(self) -> dict[str, Any]:
        return {
            **self.desired(),
            "plan_sha256": self.digest,
            "counts": {
                "datasets": len(self.datasets),
                "ingest_grants": len(self.grants),
                "skipped_removed_users": len(self.skipped_removed),
            },
            "skipped_removed_user_grants": self.grant_rows(self.skipped_removed),
        }


def make_plan(source: dict[str, Any]) -> Plan:
    """Validate the whole source snapshot before any target is touched."""
    by_id: dict[str, str] = {}
    for row in source["datasets"]:
        name = row["dataset_id"]
        require(
            isinstance(name, str)
            and ID.fullmatch(name) is not None
            and name not in by_id,
            "Malformed or duplicate source dataset ID.",
        )
        require(row["status"] in DATASET_STATUSES, "Unknown source dataset status.")
        by_id[name] = row["status"]

    users: set[str] = set()
    active_users: set[str] = set()
    for row in source["users"]:
        principal = str(row["id"])
        require(
            principal.isascii()
            and principal.isdecimal()
            and str(int(principal)) == principal
            and int(principal) > 0
            and principal not in users,
            "Malformed or ambiguous source user identity.",
        )
        require(
            row["isRemoved"] in (True, False, 0, 1), "Malformed user removal status."
        )
        users.add(principal)
        if not row["isRemoved"]:
            active_users.add(principal)

    grants: set[tuple[str, str]] = set()
    skipped: set[tuple[str, str]] = set()
    seen: set[tuple[str, str]] = set()
    for row in source["dataset_grants"]:
        require(
            row["permission"] in {"query", PERMISSION},
            "Unknown source grant permission.",
        )
        if row["permission"] != PERMISSION:
            continue
        identity = (row["dataset_id"], row["principal_id"])
        require(
            row["principal_type"] == "user"
            and isinstance(row["principal_id"], str)
            and row["principal_id"] in users
            and row["dataset_id"] in by_id
            and identity not in seen,
            "Malformed, wildcard, duplicate or incompatible ingest grant.",
        )
        seen.add(identity)
        if row["principal_id"] in active_users:
            grants.add(identity)
        else:
            skipped.add(identity)

    return Plan(by_id, grants, skipped)


def compare_target(connection: Connection, plan: Plan) -> dict[str, Any]:
    """Report the exact difference between the plan and the Python schema."""
    actual_datasets: dict[str, str] = {}
    for row in connection.execute(sa.select(datasets)).mappings():
        require(
            row["tenant_id"] == TENANT,
            "Target contains another tenant; stop migration.",
        )
        actual_datasets[row["dataset_id"]] = row["status"]

    actual_grants: set[tuple[str, str]] = set()
    for row in connection.execute(sa.select(dataset_grants)).mappings():
        require(
            row["tenant_id"] == TENANT,
            "Target grants contain another tenant; stop migration.",
        )
        if row["permission"] == PERMISSION:
            require(
                row["principal_type"] == "user",
                "Target ingest grants contain a non-user principal; stop migration.",
            )
            actual_grants.add((row["dataset_id"], row["principal_id"]))

    expected = plan.grants
    missing_datasets = sorted(plan.datasets.keys() - actual_datasets.keys())
    extra_datasets = sorted(actual_datasets.keys() - plan.datasets.keys())
    mismatched = [
        {
            "dataset_id": name,
            "expected_status": status,
            "actual_status": actual_datasets[name],
        }
        for name, status in plan.datasets.items()
        if name in actual_datasets and status != actual_datasets[name]
    ]
    missing_grants = sorted(expected - actual_grants)
    extra_grants = sorted(actual_grants - expected)
    result = {
        "counts": {
            "datasets": len(actual_datasets),
            "ingest_grants": len(actual_grants),
        },
        "missing": {
            "datasets": missing_datasets,
            "grants": Plan.grant_rows(set(missing_grants)),
        },
        "extra": {
            "datasets": extra_datasets,
            # Preserved: Python is the authority after cutover and may hold a
            # newer grant that the legacy export does not know about. This tool
            # never deletes one.
            "grants": Plan.grant_rows(set(extra_grants)),
        },
        "mismatched": mismatched,
    }
    result["status"] = (
        "PASS"
        if not missing_datasets and not missing_grants and not mismatched
        else "FAIL"
    )
    return result


def apply_plan(connection: Connection, plan: Plan, confirmation: str) -> dict[str, int]:
    """Insert the missing rows in the caller's transaction; never delete."""
    require(
        confirmation == plan.digest,
        "Apply requires --confirm-plan with the reviewed plan_sha256.",
    )
    if connection.dialect.name == "postgresql":
        connection.execute(sa.text("SELECT pg_advisory_xact_lock(72707704)"))
        connection.execute(
            sa.text(
                "LOCK TABLE hawki_backend.datasets, hawki_backend.dataset_grants "
                "IN SHARE ROW EXCLUSIVE MODE"
            )
        )
    before = compare_target(connection, plan)
    require(
        not before["extra"]["datasets"],
        "Unexpected target datasets; refuse writes. Review extras manually.",
    )
    require(
        not before["missing"]["datasets"] and not before["mismatched"],
        "Target dataset inventory/status is a prerequisite; run the reviewed dataset migration separately. No grants were written.",
    )
    changes = {
        "datasets_inserted": 0,
        "datasets_updated": 0,
        "grants_inserted": 0,
    }
    for row in before["missing"]["grants"]:
        connection.execute(dataset_grants.insert().values(**row))
        changes["grants_inserted"] += 1
    require(
        compare_target(connection, plan)["status"] == "PASS",
        "Post-apply verification failed; rolling back.",
    )
    return changes


def parser() -> argparse.ArgumentParser:
    cli = argparse.ArgumentParser(description=__doc__)
    modes = cli.add_mutually_exclusive_group(required=True)
    for mode in ("dry-run", "apply", "verify"):
        modes.add_argument(f"--{mode}", action="store_true")
    cli.add_argument("--confirm-plan", default="")
    return cli


def _engine(dsn: str, engine_list: list[sa.Engine]) -> sa.Engine:
    require(
        sa.engine.make_url(dsn).drivername == "postgresql+psycopg",
        "Database URLs must use postgresql+psycopg.",
    )
    engine = sa.create_engine(
        dsn,
        hide_parameters=True,
        connect_args={
            "connect_timeout": 5,
            "options": "-c statement_timeout=10000",
        },
    )
    engine_list.append(engine)
    return engine


def _read_only(connection: Connection) -> None:
    connection.execute(
        sa.text("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY")
    )


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    engines: list[sa.Engine] = []
    try:
        source_dsn = os.environ.get("HAWKI_INGEST_MIGRATION_SOURCE_DSN", "")
        require(bool(source_dsn), "HAWKI_INGEST_MIGRATION_SOURCE_DSN is required.")
        source_engine = _engine(source_dsn, engines)
        with source_engine.connect() as source, source.begin():
            _read_only(source)
            plan = make_plan(read_source(source))

        if args.dry_run:
            result = plan.summary()
            result["target_check"] = "NOT EXECUTED"
            result["changes"] = None
            target_dsn = os.environ.get("HAWKI_BACKEND_DB_DSN", "")
            if target_dsn:
                target_engine = _engine(target_dsn, engines)
                with target_engine.connect() as target, target.begin():
                    _read_only(target)
                    comparison = compare_target(target, plan)
                result["target_check"] = (
                    "FAIL" if comparison["status"] == "FAIL" else "PASS"
                )
                result["target_diff"] = comparison
                result["changes"] = {
                    "datasets_inserted": 0,
                    "datasets_updated": 0,
                    "grants_inserted": len(comparison["missing"]["grants"]),
                }
            print(canonical(result))
            return 0 if result["target_check"] != "FAIL" else 1

        target_dsn = os.environ.get("HAWKI_BACKEND_DB_DSN", "")
        require(bool(target_dsn), "HAWKI_BACKEND_DB_DSN is required.")
        target_engine = _engine(target_dsn, engines)
        with target_engine.connect() as target, target.begin():
            if args.apply:
                result = {
                    "status": "PASS",
                    "changes": apply_plan(target, plan, args.confirm_plan),
                }
            else:
                _read_only(target)
                result = compare_target(target, plan)
        print(canonical(result))
        return 0 if result["status"] == "PASS" else 1
    except MigrationError as error:
        print(canonical({"status": "FAIL", "error": str(error)}))
        return 1
    except Exception:
        # SQLAlchemy/config exception text can contain credentials.
        print(
            canonical(
                {
                    "status": "FAIL",
                    "error": "Migration/configuration check failed; inspect source, target schema and deployment settings securely. No unsafe operation was continued.",
                }
            )
        )
        return 1
    finally:
        for engine in engines:
            engine.dispose()


if __name__ == "__main__":
    sys.exit(main())
