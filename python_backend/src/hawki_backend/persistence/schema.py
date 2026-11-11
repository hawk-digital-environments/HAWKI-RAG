"""The Python-owned database schema for the backend's dataset authority.

The backend owns the ``hawki_backend`` schema. Authorization, dataset
metadata, physical scope, grants and one-time grant tokens all live here.
Laravel owns ``public``: its connection pins its search path to ``public``, so
the legacy application never addresses the Python tables and its migrations
never collide with them.

The schema is defined once, here, in SQLAlchemy Core. The runtime code and the
migrations both read this definition, so the two cannot drift apart.

There is no dual write. In Python dataset mode an operator imports the legacy
rows once with the migration tooling; after that the backend's own dataset
management is the only writer.
"""

import sqlalchemy as sa
from sqlalchemy import (
    Boolean,
    CheckConstraint,
    Column,
    ForeignKeyConstraint,
    Index,
    MetaData,
    PrimaryKeyConstraint,
    Table,
    Text,
    UniqueConstraint,
)

SCHEMA_NAME = "hawki_backend"

ACTIVE_STATUS = "active"
ARCHIVED_STATUS = "archived"
DELETED_STATUS = "deleted"
DATASET_STATUSES = (ACTIVE_STATUS, ARCHIVED_STATUS, DELETED_STATUS)

PRINCIPAL_TYPES = ("user", "service")
PERMISSIONS = ("query", "ingest")

_TENANT_ID_LENGTH = 128
_DATASET_ID_LENGTH = 191
_PRINCIPAL_ID_LENGTH = 255

metadata = MetaData(schema=SCHEMA_NAME)

datasets = Table(
    "datasets",
    metadata,
    Column("tenant_id", sa.String(_TENANT_ID_LENGTH), nullable=False),
    Column("dataset_id", sa.String(_DATASET_ID_LENGTH), nullable=False),
    Column("name", sa.String(255), nullable=False),
    Column("description", Text, nullable=True),
    Column(
        "status",
        sa.String(32),
        nullable=False,
        server_default=sa.text(f"'{ACTIVE_STATUS}'"),
    ),
    Column("qdrant_collection", sa.String(191), nullable=False),
    Column("neo4j_namespace", sa.String(191), nullable=True),
    Column("embedding_provider", sa.String(80), nullable=False),
    Column("embedding_model", sa.String(160), nullable=False),
    Column(
        "graph_enabled",
        Boolean,
        nullable=False,
        server_default=sa.text("false"),
    ),
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
    CheckConstraint(
        "status IN ('active', 'archived', 'deleted')",
        name="ck_datasets_status",
    ),
    PrimaryKeyConstraint("tenant_id", "dataset_id", name="pk_datasets"),
    UniqueConstraint(
        "qdrant_collection",
        name="uq_datasets_qdrant_collection",
    ),
    UniqueConstraint(
        "neo4j_namespace",
        name="uq_datasets_neo4j_namespace",
    ),
)

dataset_grants = Table(
    "dataset_grants",
    metadata,
    Column("tenant_id", sa.String(_TENANT_ID_LENGTH), nullable=False),
    Column("dataset_id", sa.String(_DATASET_ID_LENGTH), nullable=False),
    Column("principal_type", sa.String(16), nullable=False),
    Column("principal_id", sa.String(_PRINCIPAL_ID_LENGTH), nullable=False),
    Column("permission", sa.String(32), nullable=False),
    Column(
        "created_at",
        sa.DateTime(timezone=True),
        nullable=False,
        server_default=sa.func.now(),
    ),
    CheckConstraint(
        "principal_type IN ('user', 'service')",
        name="ck_dataset_grants_principal_type",
    ),
    CheckConstraint(
        "permission IN ('query', 'ingest')",
        name="ck_dataset_grants_permission",
    ),
    PrimaryKeyConstraint(
        "tenant_id",
        "dataset_id",
        "principal_type",
        "principal_id",
        "permission",
        name="pk_dataset_grants",
    ),
    ForeignKeyConstraint(
        ["tenant_id", "dataset_id"],
        ["datasets.tenant_id", "datasets.dataset_id"],
        name="fk_dataset_grants_dataset",
        ondelete="CASCADE",
    ),
)

Index(
    "ix_dataset_grants_principal",
    dataset_grants.c.tenant_id,
    dataset_grants.c.principal_type,
    dataset_grants.c.principal_id,
)

dataset_grant_tokens = Table(
    "dataset_grant_tokens",
    metadata,
    Column("token_hash", sa.String(64), nullable=False),
    Column("tenant_id", sa.String(_TENANT_ID_LENGTH), nullable=False),
    Column("dataset_id", sa.String(_DATASET_ID_LENGTH), nullable=False),
    Column("expires_at", sa.DateTime(timezone=True), nullable=False),
    Column("consumed_at", sa.DateTime(timezone=True), nullable=True),
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
    CheckConstraint(
        "length(token_hash) = 64",
        name="ck_dataset_grant_tokens_hash_length",
    ),
    PrimaryKeyConstraint("token_hash", name="pk_dataset_grant_tokens"),
    ForeignKeyConstraint(
        ["tenant_id", "dataset_id"],
        ["datasets.tenant_id", "datasets.dataset_id"],
        name="fk_dataset_grant_tokens_dataset",
        ondelete="CASCADE",
    ),
)

Index(
    "ix_dataset_grant_tokens_dataset",
    dataset_grant_tokens.c.tenant_id,
    dataset_grant_tokens.c.dataset_id,
)


__all__ = [
    "ACTIVE_STATUS",
    "ARCHIVED_STATUS",
    "DATASET_STATUSES",
    "DELETED_STATUS",
    "PERMISSIONS",
    "PRINCIPAL_TYPES",
    "SCHEMA_NAME",
    "dataset_grant_tokens",
    "dataset_grants",
    "datasets",
    "metadata",
]
