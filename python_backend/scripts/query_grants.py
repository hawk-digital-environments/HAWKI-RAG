"""Explicit, one-time query migration. Never imported by the backend runtime.

Source credentials must be SELECT-only. No DDL, deletes, environment-file writes,
or Laravel writes are performed. Production DSNs are read only from environment.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import sqlalchemy as sa
from sqlalchemy.engine import Connection

from hawki_backend.persistence.schema import dataset_grants, datasets
from hawki_backend.query.settings import load_query_settings

TENANT = "default"
FIELDS = (
    "qdrant_collection",
    "neo4j_namespace",
    "embedding_provider",
    "embedding_model",
)
ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\Z")


class MigrationError(Exception):
    """Safe operator error. Never include raw DB/config exceptions."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise MigrationError(message)


def canonical(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"))


def read_source(connection: Connection) -> dict[str, list[dict[str, Any]]]:
    """Only migration tooling may read these three Laravel tables."""
    columns = {
        "datasets": ["dataset_id", "status", *FIELDS],
        "dataset_grants": [
            "dataset_id",
            "principal_type",
            "principal_id",
            "permission",
        ],
        "users": ["id", "isRemoved"],
    }
    result = {}
    inspector = sa.inspect(connection)
    for name, selected in columns.items():
        table = sa.Table(name, sa.MetaData(), schema="public", autoload_with=connection)
        # A new tenant-aware source must never be silently flattened.
        for column in inspector.get_columns(name, schema="public"):
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
    dataset_status: dict[str, str]
    grants: set[tuple[str, str]]  # dataset, exact JWT subject (user only)
    scope: dict[str, dict[str, Any]]
    models: dict[str, Any]
    users: list[str]
    legacy_required: set[tuple[str, str]]
    expanded: bool

    def desired(self) -> dict[str, Any]:
        return {
            "tenant": TENANT,
            "datasets": self.dataset_status,
            "grants": self.grant_rows(self.grants),
            "scope": self.scope,
            "models": self.models,
        }

    @property
    def digest(self) -> str:
        return hashlib.sha256(canonical(self.desired()).encode()).hexdigest()

    @staticmethod
    def grant_rows(grants: set[tuple[str, str]]) -> list[dict[str, str]]:
        return [
            {
                "tenant_id": TENANT,
                "dataset_id": d,
                "principal_type": "user",
                "principal_id": p,
                "permission": "query",
            }
            for d, p in sorted(grants)
        ]

    def summary(self) -> dict[str, Any]:
        return {
            **self.desired(),
            "plan_sha256": self.digest,
            "counts": {
                "users": len(self.users),
                "datasets": len(self.dataset_status),
                "active_datasets": len(self.scope),
                "grants": len(self.grants),
            },
            "expand_default_grants": self.expanded,
            "legacy_grants_required_before_query_all_off": self.grant_rows(
                self.legacy_required
            ),
        }


def make_plan(
    source: dict[str, Any], report: dict[str, Any], *, expand: bool = False
) -> Plan:
    require(report.get("version") == 1, "Unsupported Laravel report version.")
    require(
        report.get("tenant") == TENANT
        and report.get("bff", {}).get("tenant") == TENANT,
        "Laravel tenant must be exactly default.",
    )
    require(
        type(report.get("query_all")) is bool, "Laravel query-all state is missing."
    )
    require(
        not expand or report["query_all"],
        "Expansion requires current Laravel query-all=true.",
    )
    require(
        isinstance(report.get("generated_at"), int)
        and 0 <= time.time() - report["generated_at"] <= 300,
        "Laravel export must be fresh (at most 300 seconds old).",
    )
    by_id = {}
    for row in source["datasets"]:
        name = row["dataset_id"]
        require(
            isinstance(name, str)
            and ID.fullmatch(name) is not None
            and name not in by_id,
            "Malformed or duplicate source dataset ID.",
        )
        require(
            row["status"] in {"active", "archived", "deleted"},
            "Unknown source dataset status.",
        )
        by_id[name] = row
    report_rows = {row["dataset_id"]: row for row in report["datasets"]}
    require(
        len(report_rows) == len(report["datasets"]) and set(by_id) == set(report_rows),
        "Laravel report/source dataset drift.",
    )
    scope = {}
    for name, row in sorted(by_id.items()):
        exported = report_rows[name]
        require(
            all(row[k] == exported.get(k) for k in ("status", *FIELDS)),
            "Laravel report/source physical metadata drift.",
        )
        require(
            type(exported.get("graph_enabled")) is bool,
            "Missing explicit Laravel graph readiness.",
        )
        if row["status"] == "active":
            # Laravel trims physical metadata before forming its trusted scope.
            values = {
                k: (row[k].strip() or None) if isinstance(row[k], str) else row[k]
                for k in FIELDS
            }
            scope[name] = {**values, "graph_enabled": exported["graph_enabled"]}
    require(bool(scope), "No active query dataset; cannot prove cutover parity.")
    models = report["models"]
    # Reuse the exact production contract (no ad hoc scope parser).
    load_query_settings(
        {
            "HAWKI_BACKEND_QUERY_DATASETS": canonical(scope),
            "HAWKI_BACKEND_QUERY_MODELS": canonical(models),
        }
    )
    users = set()
    active_users = set()
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
    grants = set()
    seen = set()
    for row in source["dataset_grants"]:
        require(
            row["permission"] in {"query", "ingest"}, "Unknown source grant permission."
        )
        if row["permission"] != "query":
            continue
        identity = (row["dataset_id"], row["principal_id"])
        require(
            row["principal_type"] == "user"
            and isinstance(row["principal_id"], str)
            and row["principal_id"] in users
            and row["dataset_id"] in by_id
            and identity not in seen,
            "Malformed, wildcard, duplicate or incompatible query grant.",
        )
        seen.add(identity)
        if row["principal_id"] in active_users:
            grants.add(identity)
    all_access = {(d, u) for d in scope for u in active_users}
    legacy_required = all_access - grants if report["query_all"] else set()
    if expand:
        grants |= all_access
    return Plan(
        {d: r["status"] for d, r in sorted(by_id.items())},
        grants,
        scope,
        models,
        sorted(active_users),
        legacy_required,
        expand,
    )


def compare_target(connection: Connection, plan: Plan) -> dict[str, Any]:
    actual_datasets = {}
    for row in connection.execute(sa.select(datasets)).mappings():
        require(
            row["tenant_id"] == TENANT,
            "Target contains another tenant; stop migration.",
        )
        actual_datasets[row["dataset_id"]] = row["status"]
    actual_grants = set()
    for row in connection.execute(sa.select(dataset_grants)).mappings():
        require(
            row["tenant_id"] == TENANT,
            "Target grants contain another tenant; stop migration.",
        )
        if row["permission"] == "query":
            actual_grants.add(
                (row["dataset_id"], row["principal_type"], row["principal_id"])
            )
    expected = {(d, "user", u) for d, u in plan.grants}
    result = {
        "counts": {
            "datasets": len(actual_datasets),
            "query_grants": len(actual_grants),
        },
        "missing": {
            "datasets": sorted(plan.dataset_status.keys() - actual_datasets.keys()),
            "grants": sorted(expected - actual_grants),
        },
        "extra": {
            "datasets": sorted(actual_datasets.keys() - plan.dataset_status.keys()),
            "grants": sorted(actual_grants - expected),
        },
        "mismatched": [
            {"dataset_id": d, "expected_status": s, "actual_status": actual_datasets[d]}
            for d, s in plan.dataset_status.items()
            if d in actual_datasets and s != actual_datasets[d]
        ],
    }
    result["status"] = (
        "PASS"
        if not any(
            (
                *result["missing"].values(),
                *result["extra"].values(),
                result["mismatched"],
            )
        )
        else "FAIL"
    )
    return result


def apply_plan(connection: Connection, plan: Plan, confirmation: str) -> dict[str, int]:
    """Caller owns one transaction. Errors must roll back the entire transaction."""
    require(
        confirmation == plan.digest,
        "Apply requires --confirm-plan with the reviewed plan_sha256.",
    )
    if connection.dialect.name == "postgresql":
        connection.execute(sa.text("SELECT pg_advisory_xact_lock(72707703)"))
        connection.execute(
            sa.text(
                "LOCK TABLE hawki_backend.datasets, hawki_backend.dataset_grants IN SHARE ROW EXCLUSIVE MODE"
            )
        )
    before = compare_target(connection, plan)
    require(
        not any(before["extra"].values()),
        "Unexpected target state; refuse writes. Review extras manually.",
    )
    changes = {"datasets_inserted": 0, "datasets_updated": 0, "grants_inserted": 0}
    for name in before["missing"]["datasets"]:
        scope = plan.scope.get(name, {})
        safe = re.sub("[^a-z0-9_]+", "_", name.lower()).strip("_") or "default"
        connection.execute(
            datasets.insert().values(
                tenant_id=TENANT,
                dataset_id=name,
                status=plan.dataset_status[name],
                name=name,
                qdrant_collection=scope.get("qdrant_collection") or "hawki_" + safe,
                neo4j_namespace=scope.get("neo4j_namespace"),
                embedding_provider=scope.get("embedding_provider") or "ollama",
                embedding_model=scope.get("embedding_model") or "bge-m3",
                graph_enabled=bool(scope.get("graph_enabled", False)),
            )
        )
        changes["datasets_inserted"] += 1
    for row in before["mismatched"]:
        connection.execute(
            datasets.update()
            .where(
                datasets.c.tenant_id == TENANT,
                datasets.c.dataset_id == row["dataset_id"],
            )
            .values(status=row["expected_status"], updated_at=sa.func.now())
        )
        changes["datasets_updated"] += 1
    for name, kind, principal in before["missing"]["grants"]:
        connection.execute(
            dataset_grants.insert().values(
                tenant_id=TENANT,
                dataset_id=name,
                principal_type=kind,
                principal_id=principal,
                permission="query",
            )
        )
        changes["grants_inserted"] += 1
    require(
        compare_target(connection, plan)["status"] == "PASS",
        "Post-apply verification failed; rolling back.",
    )
    return changes


def verify_scope(plan: Plan, environ: dict[str, str]) -> bool:
    configured = load_query_settings(environ)
    return (
        configured is not None
        and json.loads(environ["HAWKI_BACKEND_QUERY_DATASETS"]) == plan.scope
    )


def parser() -> argparse.ArgumentParser:
    cli = argparse.ArgumentParser(description=__doc__)
    modes = cli.add_mutually_exclusive_group(required=True)
    for mode in (
        "export-scope",
        "dry-run",
        "apply",
        "verify",
        "verify-scope",
        "cutover-check",
    ):
        modes.add_argument(f"--{mode}", action="store_true")
    cli.add_argument("--laravel-report", type=Path, required=True)
    cli.add_argument("--expand-default-grants", action="store_true")
    cli.add_argument("--confirm-plan", default="")
    cli.add_argument("--inspect-containers", action="store_true")
    cli.add_argument(
        "--live",
        action="store_true",
        help="Explicitly run deployment key/JWKS and live staging parity checks.",
    )
    cli.add_argument("--phase", choices=("prepare", "activate"), default="activate")
    return cli


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    engines = []
    try:
        require(
            not args.live or args.cutover_check,
            "--live is only valid with --cutover-check.",
        )
        report = json.loads(args.laravel_report.read_text())
        env = dict(os.environ)
        if args.inspect_containers:
            from query_readiness import bound_backend, container_environment

            backend_id, _ = bound_backend(report)
            env = container_environment(backend_id)
        source_dsn = os.environ.get("HAWKI_QUERY_MIGRATION_SOURCE_DSN", "")
        require(
            sa.engine.make_url(source_dsn).drivername == "postgresql+psycopg",
            "Source must use postgresql+psycopg with SELECT-only credentials.",
        )
        source_engine = sa.create_engine(
            source_dsn,
            isolation_level="REPEATABLE READ",
            hide_parameters=True,
            connect_args={
                "connect_timeout": 5,
                "options": "-c statement_timeout=10000",
            },
        )
        engines.append(source_engine)
        with source_engine.connect() as source_connection, source_connection.begin():
            source_connection.execute(sa.text("SET TRANSACTION READ ONLY"))
            plan = make_plan(
                read_source(source_connection),
                report,
                expand=args.expand_default_grants,
            )
        if args.export_scope:
            print(canonical(plan.scope))
            return 0
        if args.verify_scope:
            ok = verify_scope(plan, env)
            print(canonical({"scope": "PASS" if ok else "FAIL"}))
            return 0 if ok else 1
        if args.dry_run:
            # Planning remains possible before target provisioning. If a target
            # DSN is supplied, report exact inserts/updates without writing.
            result = plan.summary()
            target_dsn = os.environ.get("HAWKI_BACKEND_DB_DSN", "")
            result["target_check"] = "NOT EXECUTED"
            result["changes"] = None
            if target_dsn:
                require(
                    sa.engine.make_url(target_dsn).drivername == "postgresql+psycopg",
                    "Target must use postgresql+psycopg.",
                )
                target_engine = sa.create_engine(
                    target_dsn,
                    hide_parameters=True,
                    connect_args={
                        "connect_timeout": 5,
                        "options": "-c statement_timeout=10000",
                    },
                )
                engines.append(target_engine)
                with target_engine.connect() as connection, connection.begin():
                    connection.execute(
                        sa.text(
                            "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY"
                        )
                    )
                    comparison = compare_target(connection, plan)
                result["target_check"] = (
                    "FAIL" if any(comparison["extra"].values()) else "PASS"
                )
                result["target_diff"] = comparison
                result["changes"] = {
                    "datasets_inserted": len(comparison["missing"]["datasets"]),
                    "datasets_updated": len(comparison["mismatched"]),
                    "grants_inserted": len(comparison["missing"]["grants"]),
                }
            print(canonical(result))
            if result["target_check"] == "FAIL":
                return 1
            return 0
        target_dsn = os.environ.get("HAWKI_BACKEND_DB_DSN", "")
        require(
            sa.engine.make_url(target_dsn).drivername == "postgresql+psycopg",
            "Target must use postgresql+psycopg.",
        )
        require(
            sa.engine.make_url(target_dsn).query.get("options") is None,
            "Target DSN options override is not permitted.",
        )
        engine = sa.create_engine(
            target_dsn,
            hide_parameters=True,
            connect_args={
                "connect_timeout": 5,
                "options": "-c statement_timeout=10000",
            },
        )
        engines.append(engine)
        with engine.connect() as connection, connection.begin():
            if not args.apply:
                connection.execute(
                    sa.text(
                        "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY"
                    )
                )
            if args.apply:
                result = {
                    "status": "PASS",
                    "changes": apply_plan(connection, plan, args.confirm_plan),
                }
            elif args.cutover_check:
                from query_readiness import check_readiness

                result = check_readiness(
                    connection,
                    plan,
                    report,
                    env,
                    inspect_containers=args.inspect_containers,
                    live=args.live,
                    phase=args.phase,
                )
            else:
                result = compare_target(connection, plan)
        print(canonical(result))
        return 0 if result["status"] == "PASS" else 1
    except MigrationError as error:
        print(canonical({"status": "FAIL", "error": str(error)}))
        return 1
    except Exception:
        # SQLAlchemy/config/HTTP exception text can contain credentials or tokens.
        print(
            canonical(
                {
                    "status": "FAIL",
                    "error": "Migration/configuration check failed; inspect source, target schema, report freshness and deployment settings securely. No unsafe operation was continued.",
                }
            )
        )
        return 1
    finally:
        for engine in engines:
            engine.dispose()


if __name__ == "__main__":
    sys.exit(main())
