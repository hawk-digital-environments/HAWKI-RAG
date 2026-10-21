"""The Python-owned database schema for the backend's authorization boundary.

The backend owns one PostgreSQL schema, `hawki_backend`, and two tables in
it. Laravel owns `public` and cannot see these tables: its connection pins
its search path to `public`, so the legacy application never addresses them
and its migrations never collide with them.

The schema is defined once, here, in SQLAlchemy Core. The runtime query and
the migration both read this definition, so the two cannot drift apart.

The tables start empty. There is no data migration and no dual write: the
backend's own grant management populates them, and Laravel's rows stay in
Laravel's tables.
"""

import sqlalchemy as sa
from sqlalchemy import (
    CheckConstraint,
    Column,
    ForeignKeyConstraint,
    Index,
    MetaData,
    PrimaryKeyConstraint,
    Table,
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
    Column(
        "status",
        sa.String(32),
        nullable=False,
        server_default=sa.text(f"'{ACTIVE_STATUS}'"),
    ),
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


__all__ = [
    "ACTIVE_STATUS",
    "ARCHIVED_STATUS",
    "DATASET_STATUSES",
    "DELETED_STATUS",
    "PERMISSIONS",
    "PRINCIPAL_TYPES",
    "SCHEMA_NAME",
    "dataset_grants",
    "datasets",
    "metadata",
]
