"""Durable Python workflow-control preparation and reconciliation ledger."""

import sqlalchemy as sa
from alembic import op

revision = "0002"
down_revision = "0001"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "workflow_operations",
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
        schema="hawki_backend",
    )
    op.create_index(
        "uq_workflow_operations_unconfirmed_workflow",
        "workflow_operations",
        ["workflow_id"],
        unique=True,
        schema="hawki_backend",
        postgresql_where=sa.text("status IN ('dispatching','uncertain')"),
    )


def downgrade() -> None:
    op.drop_table("workflow_operations", schema="hawki_backend")
