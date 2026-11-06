"""Additive URL authority; no Laravel mirrors or duplicate workflow ledger."""

import sqlalchemy as sa

from hawki_backend.persistence.schema import metadata


def timestamps() -> list[sa.Column]:
    return [
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
    ]


requests = sa.Table(
    "url_ingestion_requests",
    metadata,
    sa.Column("request_id", sa.String(64), primary_key=True),
    sa.Column("tenant_id", sa.String(128), nullable=False),
    sa.Column("principal_id", sa.String(255), nullable=False),
    sa.Column("dataset_id", sa.String(191), nullable=False),
    sa.Column("idempotency_key", sa.String(255), nullable=False),
    sa.Column("request_hash", sa.String(64), nullable=False),
    sa.Column("task_id", sa.String(191), nullable=False, unique=True),
    sa.Column("purpose", sa.String(16), nullable=False),
    sa.Column("body", sa.JSON, nullable=False),
    *timestamps(),
    sa.UniqueConstraint("tenant_id", "principal_id", "idempotency_key"),
    sa.UniqueConstraint("request_id", "tenant_id", "dataset_id"),
    sa.ForeignKeyConstraint(
        ["tenant_id", "dataset_id"], ["datasets.tenant_id", "datasets.dataset_id"]
    ),
    sa.CheckConstraint("purpose IN ('start','delete')"),
)

sources = sa.Table(
    "url_ingestion_sources",
    metadata,
    sa.Column("source_id", sa.String(191), primary_key=True),
    sa.Column("tenant_id", sa.String(128), nullable=False),
    sa.Column("principal_id", sa.String(255), nullable=False),
    sa.Column("dataset_id", sa.String(191), nullable=False),
    sa.Column("original_url", sa.Text, nullable=False),
    sa.Column("normalized_url", sa.Text, nullable=False),
    sa.Column("identity_hash", sa.String(64), nullable=False),
    sa.Column("generation", sa.Integer, nullable=False),
    sa.Column("current_generation", sa.Integer),
    sa.Column("pending_operation_id", sa.String(64)),
    sa.Column("status", sa.String(16), nullable=False),
    sa.Column("refresh_cadence", sa.String(16)),
    sa.Column("next_refresh_at", sa.DateTime(timezone=True)),
    *timestamps(),
    sa.UniqueConstraint("tenant_id", "dataset_id", "identity_hash"),
    sa.UniqueConstraint("tenant_id", "dataset_id", "source_id"),
    sa.ForeignKeyConstraint(
        ["tenant_id", "dataset_id"], ["datasets.tenant_id", "datasets.dataset_id"]
    ),
    sa.CheckConstraint("generation >= 0"),
    sa.CheckConstraint(
        "status IN ('pending','running','ready','failed','deleting','deleted')"
    ),
)

generations = sa.Table(
    "url_ingestion_generations",
    metadata,
    sa.Column("url_ingestion_operation_id", sa.String(64), primary_key=True),
    sa.Column(
        "request_id",
        sa.String(64),
        sa.ForeignKey(requests.c.request_id),
        nullable=False,
    ),
    sa.Column("tenant_id", sa.String(128), nullable=False),
    sa.Column("dataset_id", sa.String(191), nullable=False),
    sa.Column("source_id", sa.String(191), nullable=False),
    sa.Column("generation", sa.Integer, nullable=False),
    sa.Column("expected_generation", sa.Integer, nullable=False),
    sa.Column("task_id", sa.String(191), nullable=False),
    sa.Column("job_id", sa.String(191), nullable=False, unique=True),
    sa.Column("purpose", sa.String(16), nullable=False),
    sa.Column("stage", sa.String(16), nullable=False),
    sa.Column("workflow_id", sa.String(255), unique=True),
    sa.Column("run_id", sa.String(255)),
    sa.Column(
        "workflow_operation_id",
        sa.String(64),
        sa.ForeignKey("workflow_operations.operation_id"),
    ),
    sa.Column("workflow_input", sa.JSON, nullable=False),
    sa.Column("writer_state", sa.JSON, nullable=False),
    sa.Column("external_job", sa.JSON, nullable=False),
    sa.Column("cleanup_operations", sa.JSON, nullable=False),
    sa.Column("schedule_id", sa.String(255)),
    sa.Column("schedule_state", sa.String(32), nullable=False),
    sa.Column("content_identity", sa.JSON, nullable=False),
    sa.Column("completion", sa.JSON),
    sa.Column("retired", sa.Boolean, nullable=False, server_default=sa.false()),
    *timestamps(),
    sa.UniqueConstraint("source_id", "generation"),
    sa.UniqueConstraint("source_id", "url_ingestion_operation_id"),
    sa.ForeignKeyConstraint(
        ["request_id", "tenant_id", "dataset_id"],
        [
            "url_ingestion_requests.request_id",
            "url_ingestion_requests.tenant_id",
            "url_ingestion_requests.dataset_id",
        ],
    ),
    sa.ForeignKeyConstraint(
        ["tenant_id", "dataset_id", "source_id"],
        [
            "url_ingestion_sources.tenant_id",
            "url_ingestion_sources.dataset_id",
            "url_ingestion_sources.source_id",
        ],
    ),
    sa.CheckConstraint("generation = expected_generation + 1"),
    sa.CheckConstraint("purpose IN ('start','delete')"),
    sa.CheckConstraint(
        "stage IN ('fenced','drained','cleaned','prepared','dispatched','completed')"
    ),
    sa.CheckConstraint(
        "schedule_state IN ('absent','delete_pending','deleted','upsert_pending','confirmed','uncertain')"
    ),
)

events = sa.Table(
    "url_ingestion_events",
    metadata,
    sa.Column("event_id", sa.String(255), primary_key=True),
    sa.Column("payload_hash", sa.String(64), nullable=False),
    sa.Column("source_id", sa.String(191), nullable=False),
    sa.Column("url_ingestion_operation_id", sa.String(64), nullable=False),
    sa.Column("kind", sa.String(16), nullable=False),
    sa.Column("receipt", sa.JSON, nullable=False),
    *timestamps(),
    sa.ForeignKeyConstraint(
        ["source_id", "url_ingestion_operation_id"],
        [
            "url_ingestion_generations.source_id",
            "url_ingestion_generations.url_ingestion_operation_id",
        ],
    ),
)

TABLES = (requests, sources, generations, events)

sources.append_constraint(
    sa.ForeignKeyConstraint(
        ["source_id", "current_generation"],
        ["url_ingestion_generations.source_id", "url_ingestion_generations.generation"],
        name="fk_url_current_generation",
        use_alter=True,
        deferrable=True,
        initially="DEFERRED",
    )
)
sources.append_constraint(
    sa.ForeignKeyConstraint(
        ["source_id", "pending_operation_id"],
        [
            "url_ingestion_generations.source_id",
            "url_ingestion_generations.url_ingestion_operation_id",
        ],
        name="fk_url_pending_operation",
        use_alter=True,
        deferrable=True,
        initially="DEFERRED",
    )
)
