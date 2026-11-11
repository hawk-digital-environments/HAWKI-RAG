"""Add canonical dataset metadata, physical scope and one-time grant tokens.

The revision upgrades databases that already contain the authorization-only
dataset rows. Existing rows receive a conservative physical scope derived from
the dataset identifier; the one-time dataset migration tool must be run before
Python dataset mode is activated so the canonical values come from the reviewed
plan rather than this placeholder.

Revision ID: 0007
Revises: 0006
Create Date: 2026-11-10 00:00:00.000000

"""

import sqlalchemy as sa
from alembic import op

revision = "0007"
down_revision = "0006"
branch_labels = None
depends_on = None

SCHEMA_NAME = "hawki_backend"

_SAFE_DATASET = (
    "COALESCE(NULLIF(trim(both '_' from "
    "regexp_replace(lower(dataset_id), '[^a-z0-9_]+', '_', 'g')), ''), 'default')"
)

# The physical collection is globally unique, while a logical dataset id is
# unique only within its tenant. The deterministic suffix keeps the placeholder
# distinct for the same id in two tenants and for ids that normalize to the same
# safe name. It fits the 191-character column: 6 + 150 + 1 + 12.
_PLACEHOLDER_COLLECTION = (
    "'hawki_' || substr(" + _SAFE_DATASET + ", 1, 150) || '_' || "
    "substr(md5(tenant_id || ':' || dataset_id), 1, 12)"
)


def upgrade() -> None:
    """Add dataset metadata, physical scope and the token store."""

    op.add_column(
        "datasets",
        sa.Column("name", sa.String(255), nullable=True),
        schema=SCHEMA_NAME,
    )
    op.add_column(
        "datasets",
        sa.Column("description", sa.Text(), nullable=True),
        schema=SCHEMA_NAME,
    )
    op.add_column(
        "datasets",
        sa.Column("qdrant_collection", sa.String(191), nullable=True),
        schema=SCHEMA_NAME,
    )
    op.add_column(
        "datasets",
        sa.Column("neo4j_namespace", sa.String(191), nullable=True),
        schema=SCHEMA_NAME,
    )
    op.add_column(
        "datasets",
        sa.Column("embedding_provider", sa.String(80), nullable=True),
        schema=SCHEMA_NAME,
    )
    op.add_column(
        "datasets",
        sa.Column("embedding_model", sa.String(160), nullable=True),
        schema=SCHEMA_NAME,
    )
    op.add_column(
        "datasets",
        sa.Column(
            "graph_enabled",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
        schema=SCHEMA_NAME,
    )
    op.add_column(
        "datasets",
        sa.Column("deleted_at", sa.DateTime(timezone=True), nullable=True),
        schema=SCHEMA_NAME,
    )

    # Existing authorization-only rows cannot carry caller-owned physical
    # scope, and a migration must not invent one that looks authoritative.
    # These deterministic placeholders keep the columns non-null until the
    # reviewed dataset plan replaces them.
    op.execute(
        f"""UPDATE {SCHEMA_NAME}.datasets
        SET name = COALESCE(NULLIF(name, ''), dataset_id),
            qdrant_collection = COALESCE(NULLIF(qdrant_collection, ''),
                {_PLACEHOLDER_COLLECTION}),
            embedding_provider = COALESCE(NULLIF(embedding_provider, ''), 'ollama'),
            embedding_model = COALESCE(NULLIF(embedding_model, ''), 'bge-m3')"""
    )
    for column, column_type in (
        ("name", sa.String(255)),
        ("qdrant_collection", sa.String(191)),
        ("embedding_provider", sa.String(80)),
        ("embedding_model", sa.String(160)),
    ):
        op.alter_column(
            "datasets",
            column,
            existing_type=column_type,
            nullable=False,
            schema=SCHEMA_NAME,
        )

    op.create_unique_constraint(
        "uq_datasets_qdrant_collection",
        "datasets",
        ["qdrant_collection"],
        schema=SCHEMA_NAME,
    )
    op.create_unique_constraint(
        "uq_datasets_neo4j_namespace",
        "datasets",
        ["neo4j_namespace"],
        schema=SCHEMA_NAME,
    )

    op.create_table(
        "dataset_grant_tokens",
        sa.Column("token_hash", sa.String(64), nullable=False),
        sa.Column("tenant_id", sa.String(128), nullable=False),
        sa.Column("dataset_id", sa.String(191), nullable=False),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("consumed_at", sa.DateTime(timezone=True), nullable=True),
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
            "length(token_hash) = 64",
            name="ck_dataset_grant_tokens_hash_length",
        ),
        sa.PrimaryKeyConstraint("token_hash", name="pk_dataset_grant_tokens"),
        sa.ForeignKeyConstraint(
            ["tenant_id", "dataset_id"],
            [
                f"{SCHEMA_NAME}.datasets.tenant_id",
                f"{SCHEMA_NAME}.datasets.dataset_id",
            ],
            name="fk_dataset_grant_tokens_dataset",
            ondelete="CASCADE",
        ),
        schema=SCHEMA_NAME,
    )
    op.create_index(
        "ix_dataset_grant_tokens_dataset",
        "dataset_grant_tokens",
        ["tenant_id", "dataset_id"],
        schema=SCHEMA_NAME,
    )


def downgrade() -> None:
    """Remove the token store and the metadata columns."""

    op.drop_index(
        "ix_dataset_grant_tokens_dataset",
        table_name="dataset_grant_tokens",
        schema=SCHEMA_NAME,
    )
    op.drop_table("dataset_grant_tokens", schema=SCHEMA_NAME)
    op.drop_constraint(
        "uq_datasets_neo4j_namespace",
        "datasets",
        type_="unique",
        schema=SCHEMA_NAME,
    )
    op.drop_constraint(
        "uq_datasets_qdrant_collection",
        "datasets",
        type_="unique",
        schema=SCHEMA_NAME,
    )
    for column in (
        "deleted_at",
        "graph_enabled",
        "embedding_model",
        "embedding_provider",
        "neo4j_namespace",
        "qdrant_collection",
        "description",
        "name",
    ):
        op.drop_column("datasets", column, schema=SCHEMA_NAME)
