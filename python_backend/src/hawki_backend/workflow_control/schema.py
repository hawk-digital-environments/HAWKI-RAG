"""Durable dispatch state, separate from Laravel and the query authorization tables."""

import sqlalchemy as sa

from hawki_backend.persistence.schema import metadata

operations = sa.Table(
    "workflow_operations",
    metadata,
    sa.Column("operation_id", sa.String(64), primary_key=True),
    sa.Column("workflow_id", sa.String(255), nullable=False),
    sa.Column("action", sa.String(32), nullable=False),
    sa.Column("input_digest", sa.String(64), nullable=False),
    sa.Column("payload", sa.JSON, nullable=False),
    sa.Column("status", sa.String(16), nullable=False),
    sa.Column("receipt", sa.JSON, nullable=True),
    sa.Column(
        "created_at",
        sa.DateTime(timezone=True),
        nullable=False,
        server_default=sa.func.now(),
    ),
    sa.Column(
        "updated_at",
        sa.DateTime(timezone=True),
        nullable=False,
        server_default=sa.func.now(),
    ),
    sa.CheckConstraint(
        "status IN ('prepared','dispatching','uncertain','confirmed')",
        name="ck_workflow_operations_status",
    ),
)

# Different jobs can share a stable workflow ID. Block another dispatch while
# an earlier RPC for that ID can still arrive, across processes and replicas.
sa.Index(
    "uq_workflow_operations_unconfirmed_workflow",
    operations.c.workflow_id,
    unique=True,
    postgresql_where=operations.c.status.in_(["dispatching", "uncertain"]),
    sqlite_where=operations.c.status.in_(["dispatching", "uncertain"]),
)
