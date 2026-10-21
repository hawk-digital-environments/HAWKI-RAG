"""Create the Python-owned authorization tables.

The revision is self-contained: it creates its own tables, constraints and
indexes with explicit operations, so it does not depend on the runtime
metadata staying unchanged.

Revision ID: 0001
Revises:
Create Date: 2026-10-21 00:00:00.000000

"""

import sqlalchemy as sa
from alembic import op

revision = "0001"
down_revision = None
branch_labels = None
depends_on = None

SCHEMA_NAME = "hawki_backend"


def upgrade() -> None:
    """Create the datasets and dataset grants tables."""
    op.create_table(
        "datasets",
        sa.Column("tenant_id", sa.String(128), nullable=False),
        sa.Column("dataset_id", sa.String(191), nullable=False),
        sa.Column(
            "status",
            sa.String(32),
            nullable=False,
            server_default=sa.text("'active'"),
        ),
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
            "status IN ('active', 'archived', 'deleted')",
            name="ck_datasets_status",
        ),
        sa.PrimaryKeyConstraint("tenant_id", "dataset_id", name="pk_datasets"),
        schema=SCHEMA_NAME,
    )
    op.create_table(
        "dataset_grants",
        sa.Column("tenant_id", sa.String(128), nullable=False),
        sa.Column("dataset_id", sa.String(191), nullable=False),
        sa.Column("principal_type", sa.String(16), nullable=False),
        sa.Column("principal_id", sa.String(255), nullable=False),
        sa.Column("permission", sa.String(32), nullable=False),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
        sa.CheckConstraint(
            "principal_type IN ('user', 'service')",
            name="ck_dataset_grants_principal_type",
        ),
        sa.CheckConstraint(
            "permission IN ('query', 'ingest')",
            name="ck_dataset_grants_permission",
        ),
        sa.PrimaryKeyConstraint(
            "tenant_id",
            "dataset_id",
            "principal_type",
            "principal_id",
            "permission",
            name="pk_dataset_grants",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id", "dataset_id"],
            [f"{SCHEMA_NAME}.datasets.tenant_id", f"{SCHEMA_NAME}.datasets.dataset_id"],
            name="fk_dataset_grants_dataset",
            ondelete="CASCADE",
        ),
        schema=SCHEMA_NAME,
    )
    op.create_index(
        "ix_dataset_grants_principal",
        "dataset_grants",
        ["tenant_id", "principal_type", "principal_id"],
        schema=SCHEMA_NAME,
    )


def downgrade() -> None:
    """Remove the tables the revision created.

    The schema itself is deliberately left in place. It belongs to the
    backend, not to this revision, and dropping it would take anything else
    that lives in it with it.
    """
    op.drop_index(
        "ix_dataset_grants_principal",
        table_name="dataset_grants",
        schema=SCHEMA_NAME,
    )
    op.drop_table("dataset_grants", schema=SCHEMA_NAME)
    op.drop_table("datasets", schema=SCHEMA_NAME)
