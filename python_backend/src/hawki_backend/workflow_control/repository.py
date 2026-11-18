"""Short SQL transactions only. No Temporal call can receive this connection."""

import hashlib
import json
from typing import Literal

import sqlalchemy as sa
from sqlalchemy.engine import Engine
from sqlalchemy.exc import IntegrityError

from .schema import operations


def digest(value: object) -> str:
    return hashlib.sha256(
        json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()


class OperationConflict(Exception):
    """A logical operation was replayed with different immutable input."""


class OperationRepository:
    def __init__(
        self, engine: Engine, *, dataset_source: Literal["environment", "database"]
    ):
        if dataset_source not in {"environment", "database"}:
            raise ValueError("OperationRepository requires canonical dataset authority")
        self.engine = engine
        self.dataset_source = dataset_source

    def prepare(self, action: str, workflow_id: str, payload: dict, key: list) -> dict:
        operation_id = digest([action, workflow_id, *key])
        # Rebuilding a source payload updates this timestamp. Preserve the first
        # committed payload while ignoring only that non-semantic timestamp.
        comparison = json.loads(json.dumps(payload))
        comparison.get("workflow_input", {}).get("refresh", {}).pop(
            "requested_at", None
        )
        input_digest = digest(comparison)
        with self.engine.begin() as connection:
            if (
                action in {"source", "text", "schedule"}
                and self.dataset_source == "database"
            ):
                from hawki_backend.persistence.datasets import lock_active_dataset
                from hawki_backend.persistence.schema import datasets

                wire = payload["workflow_input"]
                namespace = wire.get("ingestion", {}).get("neo4j_namespace")
                # Bind compatibility dispatch to canonical physical scope.
                # Unknown/unregistered writers cannot enter a Python graph.
                dataset = (
                    connection.execute(
                        sa.select(datasets).where(
                            datasets.c.dataset_id == wire.get("dataset_id"),
                            datasets.c.neo4j_namespace == namespace,
                        )
                    )
                    .mappings()
                    .first()
                    if namespace
                    else None
                )
                if wire.get("ingestion", {}).get("graph") and dataset is None:
                    raise OperationConflict(
                        "Graph dispatch has no canonical dataset scope."
                    )
                if dataset is not None:
                    lock_active_dataset(
                        connection, dataset["tenant_id"], dataset["dataset_id"]
                    )
                    if not (
                        wire.get("managed_document_id")
                        or wire.get("owner") == "url"
                        or wire.get("text_writer_protocol") == 1
                    ):
                        raise OperationConflict(
                            "Graph dispatch requires the managed writer protocol."
                        )
            try:
                with connection.begin_nested():
                    connection.execute(
                        operations.insert().values(
                            operation_id=operation_id,
                            action=action,
                            workflow_id=workflow_id,
                            payload=payload,
                            input_digest=input_digest,
                            status="prepared",
                        )
                    )
            except IntegrityError:
                pass
            row = dict(
                connection.execute(
                    sa.select(operations).where(
                        operations.c.operation_id == operation_id
                    )
                )
                .mappings()
                .one()
            )
            if row["input_digest"] != input_digest:
                raise OperationConflict(
                    "Prepared operation input changed; reconciliation is required."
                )
        return row

    def claim(
        self, operation_id: str, *, url_dispatch_protocol: int | None = None
    ) -> bool:
        try:
            with self.engine.begin() as connection:
                fields = {"status": "dispatching", "updated_at": sa.func.now()}
                if url_dispatch_protocol is not None:
                    row = (
                        connection.execute(
                            sa.select(operations)
                            .where(operations.c.operation_id == operation_id)
                            .with_for_update()
                        )
                        .mappings()
                        .one()
                    )
                    if row["status"] != "prepared":
                        return False
                    # Internal dispatch capability, committed BEFORE any RPC.
                    # It is outside the immutable application input/digest.
                    fields["payload"] = dict(
                        row["payload"], url_dispatch_protocol=url_dispatch_protocol
                    )
                result = connection.execute(
                    operations.update()
                    .where(
                        operations.c.operation_id == operation_id,
                        operations.c.status == "prepared",
                    )
                    .values(**fields)
                )
                return result.rowcount == 1
        except IntegrityError:
            # The unique partial index admits only one unresolved dispatch per
            # workflow, even when simultaneous claims belong to different jobs.
            return False

    def has_unconfirmed(self, workflow_id: str) -> bool:
        with self.engine.connect() as connection:
            return (
                connection.execute(
                    sa.select(operations.c.operation_id)
                    .where(
                        operations.c.workflow_id == workflow_id,
                        operations.c.status.in_(["dispatching", "uncertain"]),
                    )
                    .limit(1)
                ).first()
                is not None
            )

    def unconfirmed_for_job(self, job_id: str) -> list[dict]:
        with self.engine.connect() as connection:
            return [
                dict(row)
                for row in connection.execute(
                    sa.select(
                        operations.c.workflow_id, operations.c.operation_id
                    ).where(
                        operations.c.action.in_(["source", "text"]),
                        operations.c.status.in_(["dispatching", "uncertain"]),
                        operations.c.payload["workflow_input"]["job_id"].as_string()
                        == job_id,
                    )
                ).mappings()
            ]

    def release_unsubmitted(self, operation_id: str) -> None:
        with self.engine.begin() as connection:
            connection.execute(
                operations.update()
                .where(
                    operations.c.operation_id == operation_id,
                    operations.c.status == "dispatching",
                )
                .values(status="prepared", updated_at=sa.func.now())
            )

    def reject_url_ownership(self, operation_id: str) -> None:
        """Retain a collision even after the foreign Temporal history expires."""
        with self.engine.begin() as connection:
            row = (
                connection.execute(
                    sa.select(operations)
                    .where(operations.c.operation_id == operation_id)
                    .with_for_update()
                )
                .mappings()
                .one()
            )
            if row["status"] == "confirmed":
                return
            # A foreign run does not obey this operation's acknowledgement
            # protocol. Its later absence is therefore no longer a start proof.
            payload = dict(row["payload"], url_dispatch_protocol=0)
            connection.execute(
                operations.update()
                .where(operations.c.operation_id == operation_id)
                .values(status="uncertain", payload=payload, updated_at=sa.func.now())
            )

    def finish(self, operation_id: str, receipt: dict | None) -> None:
        with self.engine.begin() as connection:
            eligible = operations.c.operation_id == operation_id
            if receipt is None:
                # A concurrent reconciler may already have proved acceptance.
                # A delayed timeout must never erase that confirmed receipt.
                eligible = sa.and_(eligible, operations.c.status != "confirmed")
            connection.execute(
                operations.update()
                .where(eligible)
                .values(
                    status="confirmed" if receipt is not None else "uncertain",
                    receipt=receipt,
                    updated_at=sa.func.now(),
                )
            )
