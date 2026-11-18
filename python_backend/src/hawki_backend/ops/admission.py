"""Extend the existing dataset lock and dispatch ledger for graph clear.

No new registry or lease exists. An unresolved graph-clear operation in the
existing ledger is durable admission closure. A lost sink acknowledgement is
never retried automatically and never expires into permission to write.
"""

import hashlib
import uuid

import sqlalchemy as sa
from fastapi import HTTPException
from sqlalchemy.engine import Engine

from hawki_backend.persistence.datasets import lock_active_dataset
from hawki_backend.managed_documents.schema import revisions
from hawki_backend.text_ingestion.schema import ingest_text_operations
from hawki_backend.text_ingestion.writers import writers_released
from hawki_backend.url_ingestion.schema import generations
from hawki_backend.workflow_control.schema import operations


def conflict() -> HTTPException:
    return HTTPException(409, "graph_writers_active_or_unconfirmed")


def prepare_clear(engine: Engine, tenant: str, dataset: str) -> tuple[str, str]:
    """Close admission durably only after every existing writer is drained."""
    with engine.begin() as connection:
        record = lock_active_dataset(
            connection, tenant, dataset, allow_graph_clear=True
        )
        if not record.graph_enabled or not record.neo4j_namespace:
            raise HTTPException(409, "dataset_graph_disabled")
        pending = connection.execute(
            sa.select(operations).where(
                operations.c.action == "graph_clear",
                operations.c.status.in_(["dispatching", "uncertain"]),
                operations.c.payload["tenant_id"].as_string() == tenant,
                operations.c.payload["dataset_id"].as_string() == dataset,
            )
        ).first()
        if pending:
            raise HTTPException(409, "graph_clear_reconciliation_required")
        for table, state_column, wire_column, status_column, inactive in (
            (
                ingest_text_operations,
                "request_metadata",
                None,
                "lifecycle_status",
                {"ready", "deleted", "failed"},
            ),
            (
                revisions,
                "writer_state",
                "workflow_input",
                "status",
                {"ready", "cleaned"},
            ),
            (generations, "writer_state", "workflow_input", "stage", {"completed"}),
        ):
            rows = connection.execute(
                sa.select(table)
                .where(
                    table.c.tenant_id == tenant,
                    table.c.dataset_id == dataset,
                )
                .with_for_update()
            ).mappings()
            for row in rows:
                state = dict(row[state_column])
                wire = (
                    row[wire_column] if wire_column else state.get("workflow_input", {})
                )
                if row[status_column] not in inactive:
                    raise conflict()
                # A failed operation can still have an admitted or uncertain
                # sink request, regardless of the Temporal terminal status.
                closed_empty = (
                    state.get("managed_writer_protocol") == 1
                    and (
                        (
                            table is ingest_text_operations
                            and wire.get("text_writer_protocol") == 1
                        )
                        or (
                            table is revisions
                            and wire.get("managed_writer_protocol") == 1
                            and wire.get("managed_document_id")
                            == row["managed_document_id"]
                        )
                        or (
                            table is generations
                            and wire.get("managed_writer_protocol") == 1
                            and wire.get("owner") == "url"
                            and wire.get("url_ingestion_operation_id")
                            == row["url_ingestion_operation_id"]
                        )
                    )
                    and state.get("managed_no_writers_closed") is True
                    and state.get("managed_writers") == {}
                    and state.get("managed_writer_gate") == "closed"
                )
                if not closed_empty and not writers_released(
                    state | {"workflow_input": wire}
                ):
                    raise conflict()
        # Compatibility dispatch may predate Python source ownership. Its
        # confirmed RPC is not proof that the remote writer has drained.
        controls = connection.execute(
            sa.select(operations).where(
                operations.c.action.in_(["source", "schedule"]),
                operations.c.payload["workflow_input"]["dataset_id"].as_string()
                == dataset,
                operations.c.payload["workflow_input"]["ingestion"][
                    "neo4j_namespace"
                ].as_string()
                == record.neo4j_namespace,
            )
        ).mappings()
        for row in controls:
            wire = row["payload"]["workflow_input"]
            if not wire.get("managed_document_id") and wire.get("owner") != "url":
                raise conflict()
        operation_id = uuid.uuid4().hex
        identity = hashlib.sha256(f"{tenant}\0{dataset}".encode()).hexdigest()
        connection.execute(
            operations.insert().values(
                operation_id=operation_id,
                workflow_id="graph-clear-" + identity,
                action="graph_clear",
                input_digest=identity,
                status="dispatching",
                payload={
                    "tenant_id": tenant,
                    "dataset_id": dataset,
                    "neo4j_namespace": record.neo4j_namespace,
                },
            )
        )
        return operation_id, record.neo4j_namespace


def finish_clear(engine: Engine, operation_id: str, receipt: dict | None) -> None:
    with engine.begin() as connection:
        eligible = operations.c.operation_id == operation_id
        if receipt is None:
            eligible = sa.and_(eligible, operations.c.status != "confirmed")
        connection.execute(
            operations.update()
            .where(
                eligible,
                operations.c.action == "graph_clear",
            )
            .values(
                status="confirmed" if receipt is not None else "uncertain",
                receipt=receipt,
                updated_at=sa.func.now(),
            )
        )
