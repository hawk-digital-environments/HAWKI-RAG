"""Python-owned direct-text operation state.

One row owns one logical direct-text ingestion. It references the dataset
grant tables by ``(tenant_id, dataset_id)`` so a dataset of another tenant
cannot be recorded. Dispatch state itself stays in the Epoch 8
``workflow_operations`` ledger; this table stores only the service-level
operation, the artifact and the lifecycle that Python is authoritative for.
"""

from __future__ import annotations

import sqlalchemy as sa
from sqlalchemy import Column, ForeignKeyConstraint, Index, PrimaryKeyConstraint, Table

from hawki_backend.persistence.schema import metadata

STATUS_ACCEPTED = "accepted"
STATUS_STARTED = "started"
STATUS_READY = "ready"
STATUS_FAILED = "failed"
STATUS_DELETING = "deleting"
STATUS_DELETE_FAILED = "delete_failed"
STATUS_DELETED = "deleted"

OPERATION_STATUSES = (
    STATUS_ACCEPTED,
    STATUS_STARTED,
    STATUS_READY,
    STATUS_FAILED,
    STATUS_DELETING,
    STATUS_DELETE_FAILED,
    STATUS_DELETED,
)

ingest_text_operations = Table(
    "ingest_text_operations",
    metadata,
    Column("tenant_id", sa.String(128), nullable=False),
    Column("idempotency_key", sa.String(191), nullable=False),
    Column("principal_type", sa.String(16), nullable=False),
    Column("principal_id", sa.String(255), nullable=False),
    Column("dataset_id", sa.String(191), nullable=False),
    Column("external_document_id", sa.String(191), nullable=False),
    Column("source_id", sa.String(191), nullable=False),
    Column("fingerprint", sa.String(64), nullable=False),
    Column("content_hash", sa.String(64), nullable=False),
    Column("markdown_path", sa.Text, nullable=False),
    Column("task_id", sa.String(191), nullable=False),
    Column("job_id", sa.String(191), nullable=False),
    Column("workflow_id", sa.String(255), nullable=False),
    Column("run_id", sa.String(255), nullable=True),
    Column("lifecycle_status", sa.String(32), nullable=False),
    Column("request_metadata", sa.JSON, nullable=False),
    Column("deletion_key", sa.String(64), nullable=True),
    Column("deleted_at", sa.DateTime(timezone=True), nullable=True),
    Column(
        "created_at",
        sa.DateTime(timezone=True),
        nullable=False,
        server_default=sa.func.now(),
    ),
    Column(
        "updated_at",
        sa.DateTime(timezone=True),
        nullable=False,
        server_default=sa.func.now(),
    ),
    PrimaryKeyConstraint(
        "tenant_id",
        "idempotency_key",
        name="pk_ingest_text_operations",
    ),
    sa.UniqueConstraint(
        "workflow_id",
        name="uq_ingest_text_operations_workflow",
    ),
    ForeignKeyConstraint(
        ["tenant_id", "dataset_id"],
        ["datasets.tenant_id", "datasets.dataset_id"],
        name="fk_ingest_text_operations_dataset",
        ondelete="RESTRICT",
    ),
    sa.CheckConstraint(
        "principal_type IN ('user', 'service')",
        name="ck_ingest_text_operations_principal_type",
    ),
    sa.CheckConstraint(
        "lifecycle_status IN ("
        + ", ".join(f"'{status}'" for status in OPERATION_STATUSES)
        + ")",
        name="ck_ingest_text_operations_status",
    ),
)

Index(
    "ix_ingest_text_operations_source",
    ingest_text_operations.c.tenant_id,
    ingest_text_operations.c.source_id,
)

# One source may be owned by at most one unresolved operation. Failed worker
# events may precede activity retries, so failed sources require verified
# cleanup before reuse. Ready and deleted operations release this guard.
Index(
    "uq_ingest_text_operations_active_source",
    ingest_text_operations.c.tenant_id,
    ingest_text_operations.c.source_id,
    unique=True,
    sqlite_where=sa.text(
        "lifecycle_status IN ('accepted', 'started', 'failed', 'deleting', 'delete_failed')"
    ),
    postgresql_where=sa.text(
        "lifecycle_status IN ('accepted', 'started', 'failed', 'deleting', 'delete_failed')"
    ),
)

ingest_text_events = Table(
    "ingest_text_events",
    metadata,
    Column("event_id", sa.String(191), nullable=False),
    Column("tenant_id", sa.String(128), nullable=False),
    Column("operation_workflow_id", sa.String(255), nullable=False),
    Column("source_id", sa.String(191), nullable=False),
    Column("payload_hash", sa.String(64), nullable=False),
    Column("status", sa.String(32), nullable=False),
    Column(
        "received_at",
        sa.DateTime(timezone=True),
        nullable=False,
        server_default=sa.func.now(),
    ),
    PrimaryKeyConstraint("event_id", name="pk_ingest_text_events"),
)

__all__ = [
    "OPERATION_STATUSES",
    "STATUS_ACCEPTED",
    "STATUS_DELETED",
    "STATUS_DELETE_FAILED",
    "STATUS_DELETING",
    "STATUS_FAILED",
    "STATUS_READY",
    "STATUS_STARTED",
    "ingest_text_events",
    "ingest_text_operations",
]
