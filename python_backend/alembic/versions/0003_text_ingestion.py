"""Create the Python-owned direct-text operation and event tables.

Revision ID: 0003
Revises: 0002
Create Date: 2026-11-03 00:00:00.000000

"""

import sqlalchemy as sa
from alembic import op

revision = "0003"
down_revision = "0002"
branch_labels = None
depends_on = None

SCHEMA_NAME = "hawki_backend"


def upgrade() -> None:
    """Create the operation ledger, its event sink and the active-owner guard."""
    op.create_table(
        "ingest_text_operations",
        sa.Column("tenant_id", sa.String(128), nullable=False),
        sa.Column("idempotency_key", sa.String(191), nullable=False),
        sa.Column("principal_type", sa.String(16), nullable=False),
        sa.Column("principal_id", sa.String(255), nullable=False),
        sa.Column("dataset_id", sa.String(191), nullable=False),
        sa.Column("external_document_id", sa.String(191), nullable=False),
        sa.Column("source_id", sa.String(191), nullable=False),
        sa.Column("fingerprint", sa.String(64), nullable=False),
        sa.Column("content_hash", sa.String(64), nullable=False),
        sa.Column("markdown_path", sa.Text, nullable=False),
        sa.Column("task_id", sa.String(191), nullable=False),
        sa.Column("job_id", sa.String(191), nullable=False),
        sa.Column("workflow_id", sa.String(255), nullable=False),
        sa.Column("run_id", sa.String(255), nullable=True),
        sa.Column("lifecycle_status", sa.String(32), nullable=False),
        sa.Column("request_metadata", sa.JSON, nullable=False),
        sa.Column("deletion_key", sa.String(64), nullable=True),
        sa.Column("deleted_at", sa.DateTime(timezone=True), nullable=True),
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
        sa.PrimaryKeyConstraint(
            "tenant_id",
            "idempotency_key",
            name="pk_ingest_text_operations",
        ),
        sa.UniqueConstraint(
            "workflow_id",
            name="uq_ingest_text_operations_workflow",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id", "dataset_id"],
            [f"{SCHEMA_NAME}.datasets.tenant_id", f"{SCHEMA_NAME}.datasets.dataset_id"],
            name="fk_ingest_text_operations_dataset",
            ondelete="RESTRICT",
        ),
        sa.CheckConstraint(
            "principal_type IN ('user', 'service')",
            name="ck_ingest_text_operations_principal_type",
        ),
        sa.CheckConstraint(
            "lifecycle_status IN ("
            "'accepted', 'started', 'ready', 'failed', "
            "'deleting', 'delete_failed', 'deleted'"
            ")",
            name="ck_ingest_text_operations_status",
        ),
        schema=SCHEMA_NAME,
    )
    op.create_index(
        "ix_ingest_text_operations_source",
        "ingest_text_operations",
        ["tenant_id", "source_id"],
        schema=SCHEMA_NAME,
    )
    op.create_index(
        "uq_ingest_text_operations_active_source",
        "ingest_text_operations",
        ["tenant_id", "source_id"],
        unique=True,
        schema=SCHEMA_NAME,
        postgresql_where=sa.text(
            "lifecycle_status IN ('accepted', 'started', 'failed', 'deleting', 'delete_failed')"
        ),
    )
    op.create_table(
        "ingest_text_events",
        sa.Column("event_id", sa.String(191), nullable=False),
        sa.Column("tenant_id", sa.String(128), nullable=False),
        sa.Column("operation_workflow_id", sa.String(255), nullable=False),
        sa.Column("source_id", sa.String(191), nullable=False),
        sa.Column("payload_hash", sa.String(64), nullable=False),
        sa.Column("status", sa.String(32), nullable=False),
        sa.Column(
            "received_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
        sa.PrimaryKeyConstraint("event_id", name="pk_ingest_text_events"),
        schema=SCHEMA_NAME,
    )


def downgrade() -> None:
    """Remove only the tables this revision created."""
    op.drop_table("ingest_text_events", schema=SCHEMA_NAME)
    op.drop_index(
        "uq_ingest_text_operations_active_source",
        table_name="ingest_text_operations",
        schema=SCHEMA_NAME,
    )
    op.drop_index(
        "ix_ingest_text_operations_source",
        table_name="ingest_text_operations",
        schema=SCHEMA_NAME,
    )
    op.drop_table("ingest_text_operations", schema=SCHEMA_NAME)
