"""One-time, reviewed dataset-authority migration. Never imported at runtime.

The script reads Laravel's ``public`` dataset tables with SELECT-only source
credentials, derives a deterministic plan, and applies it to Python's
``hawki_backend`` dataset authority. It imports exact grants and one-time
token *hashes*; a plaintext token never exists in Laravel's database and this
tool never creates one.

The tool performs no DDL, no Laravel writes and no dual writes. Apply requires
the reviewed plan digest, runs in one transaction and verifies its own result
before commit. Run it once, before Python owns the dataset authority.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
from dataclasses import dataclass, replace
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import sqlalchemy as sa
from sqlalchemy.engine import Connection

from hawki_backend.persistence.schema import (
    ACTIVE_STATUS,
    DATASET_STATUSES,
    PERMISSIONS,
    PRINCIPAL_TYPES,
    dataset_grant_tokens,
    dataset_grants,
    datasets,
)

TENANT = "default"
DATASET_ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\Z")
TOKEN_HASH = re.compile(r"[0-9a-f]{64}\Z")
MAX_DESCRIPTION = 100_000
MAX_NAME = 255
MAX_SCOPE = 191
MAX_PROVIDER = 80
MAX_MODEL = 160

_DATASET_FIELDS = (
    "name",
    "description",
    "status",
    "qdrant_collection",
    "neo4j_namespace",
    "embedding_provider",
    "embedding_model",
    "graph_enabled",
    "deleted_at",
    "created_at",
)


class MigrationError(Exception):
    """A safe operator error. It never carries a DSN, token or driver text."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise MigrationError(message)


def canonical(value: Any) -> str:
    return json.dumps(
        value, sort_keys=True, separators=(",", ":"), default=_json_default
    )


def _json_default(value: Any) -> str:
    if isinstance(value, datetime):
        return value.isoformat()
    raise TypeError(f"Unsupported canonical value: {type(value).__name__}")


@dataclass(frozen=True)
class Plan:
    """The exact desired state plus the reviewed target transition.

    A plan is first derived from the source inventory only. ``bind_target``
    then snapshots the current target and records the only permitted
    transitions. The digest covers the source inventory, the reviewed query-all
    state, the desired state, the expected target and those transitions, so an
    apply can never silently run against a different target.
    """

    datasets: list[dict[str, Any]]
    grants: list[dict[str, Any]]
    tokens: list[dict[str, Any]]
    source_sha256: str = ""
    query_all: bool | None = None
    target: dict[str, Any] | None = None
    transitions: dict[str, Any] | None = None

    def desired(self) -> dict[str, Any]:
        return {
            "version": 1,
            "tenant": TENANT,
            "datasets": self.datasets,
            "dataset_grants": self.grants,
            "dataset_grant_tokens": self.tokens,
        }

    def reviewed(self) -> dict[str, Any]:
        return {
            "version": 1,
            "tenant": TENANT,
            "source_sha256": self.source_sha256,
            "query_all": self.query_all,
            "desired": self.desired(),
            "target": self.target,
            "transitions": self.transitions,
        }

    @property
    def digest(self) -> str:
        return hashlib.sha256(canonical(self.reviewed()).encode()).hexdigest()

    def bind_target(self, connection: Connection) -> "Plan":
        """Bind the current target and the only permitted transitions."""

        target = _target_snapshot(connection)
        transitions = _transitions(self, target)
        return replace(self, target=target, transitions=transitions)

    def summary(self) -> dict[str, Any]:
        return {
            "version": 1,
            "tenant": TENANT,
            "query_all": self.query_all,
            "plan_sha256": self.digest,
            "counts": {
                "datasets": len(self.datasets),
                "active_datasets": sum(
                    row["status"] == ACTIVE_STATUS for row in self.datasets
                ),
                "grants": len(self.grants),
                "tokens": len(self.tokens),
            },
        }


def read_source(connection: Connection) -> dict[str, list[dict[str, Any]]]:
    """Read the three Laravel tables a one-time migration needs.

    Source credentials must be SELECT-only. A source that carries a
    non-default tenant column is refused rather than silently flattened.
    """

    columns = {
        "datasets": [
            "dataset_id",
            "name",
            "description",
            "status",
            "qdrant_collection",
            "neo4j_namespace",
            "embedding_provider",
            "embedding_model",
            "created_at",
        ],
        "dataset_grants": [
            "dataset_id",
            "principal_type",
            "principal_id",
            "permission",
        ],
        "dataset_grant_tokens": [
            "dataset_id",
            "token_hash",
            "expires_at",
            "consumed_at",
            "created_at",
            "updated_at",
        ],
    }
    result: dict[str, list[dict[str, Any]]] = {}
    try:
        for name, selected in columns.items():
            table = sa.Table(
                name, sa.MetaData(), schema="public", autoload_with=connection
            )
            # A tenant-aware source must not be flattened into the default
            # tenant, and an ambiguous tenant must stop the migration.
            tenant_columns = [
                column["name"]
                for column in sa.inspect(connection).get_columns(name, schema="public")
                if "tenant" in column["name"].lower()
            ]
            wanted = [*selected, *(c for c in tenant_columns if c not in selected)]
            rows = [
                dict(row)
                for row in connection.execute(
                    sa.select(*(table.c[column] for column in wanted))
                ).mappings()
            ]
            for column in tenant_columns:
                for row in rows:
                    require(
                        row[column] == TENANT,
                        "Source has a non-default tenant identity; stop migration.",
                    )
            result[name] = rows
    except MigrationError:
        raise
    except Exception:
        raise MigrationError(
            "Source dataset tables are missing or unreadable; no migration was planned."
        ) from None
    return result


def make_plan(
    source: dict[str, list[dict[str, Any]]],
    report: dict[str, Any] | None = None,
) -> Plan:
    """Turn the SELECT-only source rows into one deterministic plan."""

    rows = source.get("datasets")
    grants = source.get("dataset_grants")
    tokens = source.get("dataset_grant_tokens")
    require(
        isinstance(rows, list)
        and isinstance(grants, list)
        and isinstance(tokens, list),
        "Source dataset inventory is incomplete.",
    )
    if report is not None:
        require(report.get("version") == 1, "Unsupported Laravel report version.")
        require(
            report.get("tenant") == TENANT,
            "Laravel report tenant must be exactly default.",
        )
        require(
            type(report.get("query_all")) is bool,
            "Laravel query-all state is missing.",
        )
        # Switching to Python exact grants removes the legacy wildcard. The
        # reviewed report must prove query-all is already off; materializing
        # exact grants without that transition would silently drop access.
        require(
            report["query_all"] is False,
            "Laravel query-all must be explicitly disabled and verified before "
            "the dataset authority can move to Python.",
        )

    graph_enabled = _graph_flags(report, rows)
    planned: dict[str, dict[str, Any]] = {}
    scopes: set[str] = set()
    namespaces: set[str] = set()
    for row in rows:
        dataset_id = _identity_text(row.get("dataset_id"), "dataset_id", 191)
        require(
            DATASET_ID.fullmatch(dataset_id) is not None,
            "Source contains a malformed dataset ID.",
        )
        require(dataset_id not in planned, "Source contains a duplicate dataset ID.")
        status = _identity_text(row.get("status"), "status", 32)
        require(
            status in DATASET_STATUSES, "Source contains an unknown dataset status."
        )
        name = _text(row.get("name"), MAX_NAME)
        require(name is not None, "Source contains a dataset without a name.")
        collection = _text(row.get("qdrant_collection"), MAX_SCOPE)
        require(
            collection is not None, "Source contains a dataset without a collection."
        )
        namespace = _text(row.get("neo4j_namespace"), MAX_SCOPE)
        provider = _text(row.get("embedding_provider"), MAX_PROVIDER) or "ollama"
        model = _text(row.get("embedding_model"), MAX_MODEL) or "bge-m3"
        require(
            collection not in scopes,
            "Source contains a duplicate Qdrant collection; stop migration.",
        )
        if namespace is not None:
            require(
                namespace not in namespaces,
                "Source contains a duplicate Neo4j namespace; stop migration.",
            )
            namespaces.add(namespace)
        scopes.add(collection)
        created_at = _timestamp(row.get("created_at"), "created_at")
        deleted_at = created_at if status == "deleted" else None
        planned[dataset_id] = {
            "tenant_id": TENANT,
            "dataset_id": dataset_id,
            "name": name,
            "description": _description(row.get("description")),
            "status": status,
            "qdrant_collection": collection,
            "neo4j_namespace": namespace,
            "embedding_provider": provider,
            "embedding_model": model,
            "graph_enabled": graph_enabled[dataset_id],
            "deleted_at": _iso_or_none(deleted_at),
            "created_at": _iso(created_at),
        }

    planned_grants: dict[tuple[str, str, str, str], dict[str, Any]] = {}
    for row in grants:
        dataset_id = _identity_text(row.get("dataset_id"), "grant dataset_id", 191)
        require(
            dataset_id in planned,
            "Source contains a grant for an unknown dataset.",
        )
        principal_type = _identity_text(row.get("principal_type"), "principal_type", 16)
        require(
            principal_type in PRINCIPAL_TYPES,
            "Source contains an unknown grant principal type.",
        )
        principal_id = _identity_text(row.get("principal_id"), "principal_id", 255)
        require(
            "*" not in principal_id,
            "Source contains a wildcard or ambiguous grant identity.",
        )
        permission = _identity_text(row.get("permission"), "permission", 32)
        require(
            permission in PERMISSIONS,
            "Source contains an unknown grant permission.",
        )
        identity = (dataset_id, principal_type, principal_id, permission)
        require(identity not in planned_grants, "Source contains a duplicate grant.")
        planned_grants[identity] = {
            "tenant_id": TENANT,
            "dataset_id": dataset_id,
            "principal_type": principal_type,
            "principal_id": principal_id,
            "permission": permission,
        }

    planned_tokens: dict[str, dict[str, Any]] = {}
    for row in tokens:
        dataset_id = _identity_text(row.get("dataset_id"), "token dataset_id", 191)
        require(
            dataset_id in planned, "Source contains a token for an unknown dataset."
        )
        token_hash = _identity_text(row.get("token_hash"), "token_hash", 64)
        require(
            TOKEN_HASH.fullmatch(token_hash) is not None,
            "Source contains a malformed token hash.",
        )
        require(
            token_hash not in planned_tokens, "Source contains a duplicate token hash."
        )
        expires_at = _timestamp(row.get("expires_at"), "expires_at")
        consumed_at = _timestamp_or_none(row.get("consumed_at"), "consumed_at")
        created_at = _timestamp(row.get("created_at"), "token created_at")
        updated_at = _timestamp_or_none(row.get("updated_at"), "token updated_at")
        require(
            consumed_at is None or consumed_at >= created_at,
            "Source token was consumed before it was created.",
        )
        planned_tokens[token_hash] = {
            "tenant_id": TENANT,
            "dataset_id": dataset_id,
            "token_hash": token_hash,
            "expires_at": _iso(expires_at),
            "consumed_at": _iso_or_none(consumed_at),
            "created_at": _iso(created_at),
            "updated_at": _iso(updated_at or created_at),
        }

    return Plan(
        [planned[key] for key in sorted(planned)],
        [planned_grants[key] for key in sorted(planned_grants)],
        [planned_tokens[key] for key in sorted(planned_tokens)],
        source_sha256=_source_digest(source, report),
        query_all=report.get("query_all") if report is not None else None,
    )


def _source_digest(
    source: dict[str, list[dict[str, Any]]],
    report: dict[str, Any] | None,
) -> str:
    """Bind the exact reviewed source rows and Laravel report.

    Drivers return timestamps either timezone-aware or naive. Normalize every
    datetime to UTC ISO text so the same rows produce the same digest whether
    they come from the reviewed fixture or a database round-trip. Row order is
    not identity, so sort each table canonically.
    """

    normalized = {
        name: sorted((_source_row(row) for row in rows), key=canonical)
        for name, rows in source.items()
    }
    payload = {"source": normalized, "report": report}
    return hashlib.sha256(canonical(payload).encode()).hexdigest()


def _source_row(row: dict[str, Any]) -> dict[str, Any]:
    normalized = dict(row)
    for field, value in normalized.items():
        if isinstance(value, datetime):
            parsed = _timestamp_or_none(value, field)
            normalized[field] = _iso(parsed)
    return normalized


def compare_target(connection: Connection, plan: Plan) -> dict[str, Any]:
    """Return the exact target difference. It never writes."""

    return _compare(plan, _target_snapshot(connection))


def _compare(plan: Plan, target: dict[str, Any]) -> dict[str, Any]:
    """Compare one target snapshot with the desired state."""

    target_datasets = target["datasets"]
    target_grants = _grant_map(target["grants"])
    target_tokens = target["tokens"]
    expected_datasets = {row["dataset_id"]: row for row in plan.datasets}
    expected_grants = {
        (
            row["dataset_id"],
            row["principal_type"],
            row["principal_id"],
            row["permission"],
        ): row
        for row in plan.grants
    }
    expected_tokens = {row["token_hash"]: row for row in plan.tokens}

    missing_datasets = sorted(expected_datasets.keys() - target_datasets.keys())
    extra_datasets = sorted(target_datasets.keys() - expected_datasets.keys())
    missing_grants = sorted(expected_grants.keys() - target_grants.keys())
    extra_grants = sorted(target_grants.keys() - expected_grants.keys())
    missing_tokens = sorted(expected_tokens.keys() - target_tokens.keys())
    extra_tokens = sorted(target_tokens.keys() - expected_tokens.keys())

    mismatched_datasets = []
    for dataset_id in sorted(expected_datasets.keys() & target_datasets.keys()):
        expected = expected_datasets[dataset_id]
        actual = target_datasets[dataset_id]
        fields = [
            field for field in _DATASET_FIELDS if expected[field] != actual[field]
        ]
        if fields:
            mismatched_datasets.append({"dataset_id": dataset_id, "fields": fields})

    mismatched_tokens = []
    for token_hash in sorted(expected_tokens.keys() & target_tokens.keys()):
        expected = expected_tokens[token_hash]
        actual = target_tokens[token_hash]
        fields = [
            field
            for field in (
                "dataset_id",
                "expires_at",
                "consumed_at",
                "created_at",
                "updated_at",
            )
            if expected[field] != actual[field]
        ]
        if fields:
            mismatched_tokens.append({"token_hash": token_hash, "fields": fields})

    # A tombstone is final. Verification must call out any target row that was
    # deleted but is expected to be active or archived again.
    revived_datasets = sorted(
        dataset_id
        for dataset_id in expected_datasets.keys() & target_datasets.keys()
        if (
            target_datasets[dataset_id]["status"] == "deleted"
            and expected_datasets[dataset_id]["status"] != "deleted"
        )
        or (
            target_datasets[dataset_id]["deleted_at"] is not None
            and expected_datasets[dataset_id]["deleted_at"] is None
        )
    )

    result = {
        "counts": {
            "datasets": len(target_datasets),
            "grants": len(target_grants),
            "tokens": len(target_tokens),
        },
        "missing": {
            "datasets": missing_datasets,
            "grants": [_grant_key(row) for row in missing_grants],
            "tokens": missing_tokens,
        },
        "extra": {
            "datasets": extra_datasets,
            "grants": [_grant_key(row) for row in extra_grants],
            "tokens": extra_tokens,
        },
        "mismatched": {
            "datasets": mismatched_datasets,
            "tokens": mismatched_tokens,
        },
        "revived": {"datasets": revived_datasets},
    }
    result["status"] = (
        "PASS"
        if not any(
            (
                *result["missing"].values(),
                *result["extra"].values(),
                result["mismatched"]["datasets"],
                result["mismatched"]["tokens"],
                result["revived"]["datasets"],
            )
        )
        else "FAIL"
    )
    return result


def _transitions(plan: Plan, target: dict[str, Any]) -> dict[str, Any]:
    """Return the only permitted target transitions, or fail closed.

    Only an exact revision-0007 placeholder may be updated. A canonical target
    row, an extra row, a differing token or a tombstone stops the migration
    before any write.
    """

    comparison = _compare(plan, target)
    require(
        not any(comparison["extra"].values()),
        "Unexpected target rows; refuse writes. Review extras manually.",
    )
    require(
        not comparison["mismatched"]["tokens"],
        "A target token differs from the source; refuse to rewrite it.",
    )

    expected_datasets = {row["dataset_id"]: row for row in plan.datasets}
    target_datasets = target["datasets"]
    updates = []
    for mismatch in comparison["mismatched"]["datasets"]:
        dataset_id = mismatch["dataset_id"]
        actual = target_datasets[dataset_id]
        expected = expected_datasets[dataset_id]
        if actual["status"] == "deleted" or actual["deleted_at"] is not None:
            raise MigrationError(
                "Target contains a dataset tombstone; the migration never revives it."
            )
        if actual["status"] == "archived" and expected["status"] == "active":
            raise MigrationError(
                "Target contains an archived dataset; refuse an archived-to-active change."
            )
        if not _is_revision_0007_placeholder(actual, dataset_id):
            raise MigrationError(
                "Target contains canonical dataset metadata, not a revision 0007 "
                "placeholder; refuse to overwrite it."
            )
        updates.append(
            {
                "dataset_id": dataset_id,
                "from": _dataset_snapshot(actual),
                "to": _dataset_snapshot(expected),
            }
        )

    return {
        "datasets_inserted": sorted(comparison["missing"]["datasets"]),
        "datasets_updated": updates,
        "grants_inserted": sorted(
            comparison["missing"]["grants"], key=lambda row: tuple(row)
        ),
        "tokens_inserted": sorted(comparison["missing"]["tokens"]),
    }


def _is_revision_0007_placeholder(row: dict[str, Any], dataset_id: str) -> bool:
    """Whether the row is exactly the deterministic revision-0007 backfill.

    The target snapshot keys datasets by ``dataset_id`` and deliberately omits
    that value from each row, so the identity is passed separately.
    """

    return bool(
        row["status"] == ACTIVE_STATUS
        and row["name"] == dataset_id
        and row["description"] is None
        and row["qdrant_collection"] == _placeholder_collection(dataset_id)
        and row["neo4j_namespace"] is None
        and row["embedding_provider"] == "ollama"
        and row["embedding_model"] == "bge-m3"
        and row["graph_enabled"] is False
        and row["deleted_at"] is None
    )


def _placeholder_collection(dataset_id: str) -> str:
    """Reproduce revision 0007's deterministic placeholder value."""

    safe = re.sub(r"[^a-z0-9_]+", "_", dataset_id.lower()).strip("_") or "default"
    suffix = hashlib.md5(f"{TENANT}:{dataset_id}".encode()).hexdigest()[:12]
    return f"hawki_{safe[:150]}_{suffix}"


def _dataset_snapshot(row: dict[str, Any]) -> dict[str, Any]:
    return {field: row[field] for field in _DATASET_FIELDS}


def apply_plan(connection: Connection, plan: Plan, confirmation: str) -> dict[str, int]:
    """Apply one reviewed, target-bound plan in the caller's transaction.

    Dataset metadata and lifecycle rows may be updated only when the target row
    is the exact revision-0007 placeholder. A canonical target row, tombstone,
    grant, token or extra row is never overwritten. The caller's transaction is
    rolled back on any error.
    """

    require(
        confirmation == plan.digest,
        "Apply requires --confirm-plan with the reviewed plan_sha256.",
    )
    require(
        plan.target is not None and plan.transitions is not None,
        "Apply requires a target-bound plan; regenerate it with target access.",
    )
    require(
        plan.query_all is False,
        "Laravel query-all must be explicitly disabled and verified before apply.",
    )
    if connection.dialect.name == "postgresql":
        connection.execute(sa.text("SELECT pg_advisory_xact_lock(72707704)"))
        connection.execute(
            sa.text(
                "LOCK TABLE hawki_backend.datasets, hawki_backend.dataset_grants, "
                "hawki_backend.dataset_grant_tokens IN SHARE ROW EXCLUSIVE MODE"
            )
        )
    current = _target_snapshot(connection)
    require(
        canonical(current) == canonical(plan.target),
        "Target changed since the reviewed plan; regenerate and re-review it.",
    )
    require(
        canonical(_transitions(plan, current)) == canonical(plan.transitions),
        "Target transition changed since the reviewed plan; refuse writes.",
    )

    changes = {
        "datasets_inserted": 0,
        "datasets_updated": 0,
        "grants_inserted": 0,
        "tokens_inserted": 0,
    }
    expected_datasets = {row["dataset_id"]: row for row in plan.datasets}
    for dataset_id in plan.transitions["datasets_inserted"]:
        row = expected_datasets[dataset_id]
        connection.execute(
            datasets.insert().values(
                tenant_id=TENANT,
                dataset_id=row["dataset_id"],
                name=row["name"],
                description=row["description"],
                status=row["status"],
                qdrant_collection=row["qdrant_collection"],
                neo4j_namespace=row["neo4j_namespace"],
                embedding_provider=row["embedding_provider"],
                embedding_model=row["embedding_model"],
                graph_enabled=row["graph_enabled"],
                deleted_at=_parse_or_none(row["deleted_at"]),
                created_at=_parse(row["created_at"]),
                updated_at=_parse(row["created_at"]),
            )
        )
        changes["datasets_inserted"] += 1
    for transition in plan.transitions["datasets_updated"]:
        row = expected_datasets[transition["dataset_id"]]
        result = connection.execute(
            datasets.update()
            .where(
                datasets.c.tenant_id == TENANT,
                datasets.c.dataset_id == row["dataset_id"],
            )
            .values(
                name=row["name"],
                description=row["description"],
                status=row["status"],
                qdrant_collection=row["qdrant_collection"],
                neo4j_namespace=row["neo4j_namespace"],
                embedding_provider=row["embedding_provider"],
                embedding_model=row["embedding_model"],
                graph_enabled=row["graph_enabled"],
                deleted_at=_parse_or_none(row["deleted_at"]),
                created_at=_parse(row["created_at"]),
                updated_at=sa.func.now(),
            )
        )
        require(
            result.rowcount == 1,
            "Target changed during apply; rolling back.",
        )
        changes["datasets_updated"] += 1
    expected_grants = {
        (
            row["dataset_id"],
            row["principal_type"],
            row["principal_id"],
            row["permission"],
        ): row
        for row in plan.grants
    }
    for key in plan.transitions["grants_inserted"]:
        connection.execute(
            dataset_grants.insert().values(**expected_grants[tuple(key)])
        )
        changes["grants_inserted"] += 1
    expected_tokens = {row["token_hash"]: row for row in plan.tokens}
    for token_hash in plan.transitions["tokens_inserted"]:
        row = expected_tokens[token_hash]
        connection.execute(
            dataset_grant_tokens.insert().values(
                token_hash=row["token_hash"],
                tenant_id=TENANT,
                dataset_id=row["dataset_id"],
                expires_at=_parse(row["expires_at"]),
                consumed_at=_parse_or_none(row["consumed_at"]),
                created_at=_parse(row["created_at"]),
                updated_at=_parse(row["updated_at"]),
            )
        )
        changes["tokens_inserted"] += 1

    after = compare_target(connection, plan)
    require(
        after["status"] == "PASS",
        "Post-apply verification failed; rolling back.",
    )
    return changes


def _target_snapshot(connection: Connection) -> dict[str, Any]:
    """Read one consistent, JSON-canonical target snapshot."""

    return {
        "datasets": _target_datasets(connection),
        "grants": [
            {
                "dataset_id": row[0],
                "principal_type": row[1],
                "principal_id": row[2],
                "permission": row[3],
            }
            for row in sorted(_target_grants(connection))
        ],
        "tokens": _target_tokens(connection),
    }


def _grant_map(
    rows: list[dict[str, str]],
) -> dict[tuple[str, str, str, str], dict[str, str]]:
    return {
        (
            str(row["dataset_id"]),
            str(row["principal_type"]),
            str(row["principal_id"]),
            str(row["permission"]),
        ): row
        for row in rows
    }


def _target_datasets(connection: Connection) -> dict[str, dict[str, Any]]:
    rows = connection.execute(sa.select(datasets)).mappings().all()
    result: dict[str, dict[str, Any]] = {}
    for row in rows:
        require(
            row["tenant_id"] == TENANT,
            "Target contains another tenant; stop migration.",
        )
        result[str(row["dataset_id"])] = {
            "name": str(row["name"]),
            "description": row["description"],
            "status": str(row["status"]),
            "qdrant_collection": str(row["qdrant_collection"]),
            "neo4j_namespace": row["neo4j_namespace"],
            "embedding_provider": str(row["embedding_provider"]),
            "embedding_model": str(row["embedding_model"]),
            "graph_enabled": bool(row["graph_enabled"]),
            "deleted_at": _iso_or_none(row["deleted_at"]),
            "created_at": _iso_or_none(row["created_at"]),
        }
    return result


def _target_grants(connection: Connection) -> dict[tuple[str, str, str, str], Any]:
    rows = connection.execute(sa.select(dataset_grants)).mappings().all()
    result: dict[tuple[str, str, str, str], Any] = {}
    for row in rows:
        require(
            row["tenant_id"] == TENANT,
            "Target grants contain another tenant; stop migration.",
        )
        result[
            (
                str(row["dataset_id"]),
                str(row["principal_type"]),
                str(row["principal_id"]),
                str(row["permission"]),
            )
        ] = row
    return result


def _target_tokens(connection: Connection) -> dict[str, dict[str, Any]]:
    rows = connection.execute(sa.select(dataset_grant_tokens)).mappings().all()
    result: dict[str, dict[str, Any]] = {}
    for row in rows:
        require(
            row["tenant_id"] == TENANT,
            "Target tokens contain another tenant; stop migration.",
        )
        result[str(row["token_hash"])] = {
            "dataset_id": str(row["dataset_id"]),
            "expires_at": _iso_or_none(row["expires_at"]),
            "consumed_at": _iso_or_none(row["consumed_at"]),
            "created_at": _iso_or_none(row["created_at"]),
            "updated_at": _iso_or_none(row["updated_at"]),
        }
    return result


def _grant_key(row: tuple[str, str, str, str]) -> list[str]:
    return [row[0], row[1], row[2], row[3]]


def _graph_flags(
    report: dict[str, Any] | None,
    source_rows: list[dict[str, Any]],
) -> dict[str, bool]:
    """Use the reviewed Laravel report when present, otherwise fail closed.

    Laravel derives graph readiness at runtime; it is not a column on the
    dataset table. Without the reviewed export, every dataset is imported with
    graph access disabled rather than guessed.
    """

    source_ids = {str(row.get("dataset_id")) for row in source_rows}
    if report is None:
        return {dataset_id: False for dataset_id in source_ids}
    require(report.get("version") == 1, "Unsupported Laravel report version.")
    require(
        report.get("tenant") == TENANT,
        "Laravel report tenant must be exactly default.",
    )
    exported = report.get("datasets")
    require(isinstance(exported, list), "Laravel report datasets are missing.")
    flags: dict[str, bool] = {}
    for row in exported:
        require(isinstance(row, dict), "Malformed Laravel report dataset.")
        dataset_id = row.get("dataset_id")
        require(
            isinstance(dataset_id, str) and dataset_id in source_ids,
            "Laravel report/source dataset drift.",
        )
        require(dataset_id not in flags, "Duplicate dataset in the Laravel report.")
        require(
            type(row.get("graph_enabled")) is bool,
            "Laravel report graph_enabled must be an explicit boolean.",
        )
        flags[dataset_id] = row["graph_enabled"]
    require(set(flags) == source_ids, "Laravel report/source dataset drift.")
    return flags


def _text(value: Any, limit: int) -> str | None:
    if not isinstance(value, str):
        return None
    stripped = value.strip()
    require(len(stripped) <= limit, "Source text exceeds the canonical field length.")
    return stripped or None


def _identity_text(value: Any, field: str, limit: int) -> str:
    """Return an exact identity token, refusing surrounding whitespace."""

    require(
        isinstance(value, str) and value == value.strip() and value != "",
        f"Source {field} is missing or ambiguous.",
    )
    require(len(value) <= limit, f"Source {field} exceeds the canonical length.")
    return value


def _description(value: Any) -> str | None:
    if value is None:
        return None
    require(isinstance(value, str), "Source description must be text.")
    require(len(value) <= MAX_DESCRIPTION, "Source description is too long.")
    return value


def _timestamp(value: Any, field: str) -> datetime:
    parsed = _timestamp_or_none(value, field)
    require(parsed is not None, f"Source {field} is missing.")
    return parsed


def _timestamp_or_none(value: Any, field: str) -> datetime | None:
    if value is None:
        return None
    if isinstance(value, datetime):
        parsed = value
    elif isinstance(value, str):
        try:
            parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        except ValueError:
            raise MigrationError(f"Source {field} is malformed.") from None
    else:
        raise MigrationError(f"Source {field} is malformed.")
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        return parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def _iso(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat()


def _iso_or_none(value: Any) -> str | None:
    parsed = _timestamp_or_none(value, "timestamp")
    return _iso(parsed) if parsed is not None else None


def _parse(value: str) -> datetime:
    return datetime.fromisoformat(value).astimezone(timezone.utc)


def _parse_or_none(value: str | None) -> datetime | None:
    return _parse(value) if value is not None else None


def parser() -> argparse.ArgumentParser:
    cli = argparse.ArgumentParser(description=__doc__)
    modes = cli.add_mutually_exclusive_group(required=True)
    for mode in ("plan", "apply", "verify"):
        modes.add_argument(f"--{mode}", action="store_true")
    cli.add_argument(
        "--laravel-report",
        type=Path,
        help=(
            "query:cutover-export JSON. It supplies the reviewed graph "
            "readiness; without it every dataset imports with graph disabled."
        ),
    )
    cli.add_argument("--confirm-plan", default="")
    return cli


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    engines = []
    try:
        report = None
        if args.laravel_report is not None:
            try:
                report = json.loads(args.laravel_report.read_text())
            except (OSError, ValueError):
                raise MigrationError("Laravel report is unreadable.") from None
        source_dsn = os.environ.get("HAWKI_DATASET_MIGRATION_SOURCE_DSN", "")
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
            plan = make_plan(read_source(source_connection), report)

        target_dsn = os.environ.get("HAWKI_BACKEND_DB_DSN", "")
        if not target_dsn and args.plan:
            print(
                canonical(
                    {
                        **plan.summary(),
                        "status": "PASS",
                        "target_check": "NOT EXECUTED",
                    }
                )
            )
            return 0
        require(
            sa.engine.make_url(target_dsn).drivername == "postgresql+psycopg",
            "Target must use postgresql+psycopg.",
        )
        require(
            sa.engine.make_url(target_dsn).query.get("options") is None,
            "Target DSN options override is not permitted.",
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
            if args.apply:
                bound = plan.bind_target(connection)
                changes = apply_plan(connection, bound, args.confirm_plan)
                result = {
                    "status": "PASS",
                    "plan_sha256": bound.digest,
                    "changes": changes,
                }
            else:
                connection.execute(
                    sa.text(
                        "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY"
                    )
                )
                comparison = compare_target(connection, plan)
                if args.plan:
                    bound = plan.bind_target(connection)
                    result = {
                        **bound.summary(),
                        "status": comparison["status"],
                        "target_check": comparison["status"],
                        "target_diff": comparison,
                        "changes": {
                            "datasets_inserted": len(
                                bound.transitions["datasets_inserted"]
                            ),
                            "datasets_updated": len(
                                bound.transitions["datasets_updated"]
                            ),
                            "grants_inserted": len(
                                bound.transitions["grants_inserted"]
                            ),
                            "tokens_inserted": len(
                                bound.transitions["tokens_inserted"]
                            ),
                        },
                    }
                else:
                    require(
                        plan.query_all is False,
                        "Verify requires the reviewed Laravel report with "
                        "query-all explicitly disabled.",
                    )
                    result = {
                        **plan.summary(),
                        "status": comparison["status"],
                        "target_check": comparison["status"],
                        "target_diff": comparison,
                    }
        print(canonical(result))
        if args.plan and result["target_check"] == "FAIL":
            return 1
        return 0 if result.get("status", "PASS") == "PASS" else 1
    except MigrationError as error:
        print(canonical({"status": "FAIL", "error": str(error)}))
        return 1
    except Exception:
        # Driver and configuration errors can contain credentials. Never echo
        # them; the operator must inspect the environment securely.
        print(
            canonical(
                {
                    "status": "FAIL",
                    "error": "Dataset migration failed; no changes were committed. "
                    "Inspect source, target and the reviewed plan securely.",
                }
            )
        )
        return 1
    finally:
        for engine in engines:
            engine.dispose()


if __name__ == "__main__":
    sys.exit(main())
