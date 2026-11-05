"""Additive managed ownership, immutable revisions and resumable mutation evidence."""

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


documents = sa.Table(
    "managed_documents",
    metadata,
    sa.Column("managed_document_id", sa.String(64), primary_key=True),
    sa.Column("tenant_id", sa.String(128), nullable=False),
    sa.Column("dataset_id", sa.String(191), nullable=False),
    sa.Column("principal_id", sa.String(255), nullable=False),
    sa.Column("generation", sa.Integer, nullable=False, server_default="0"),
    sa.Column("current_revision_id", sa.String(64)),
    sa.Column("pending_revision_id", sa.String(64)),
    sa.Column("mutation_id", sa.String(64)),
    sa.Column("status", sa.String(32), nullable=False),
    sa.Column("attributes", sa.JSON, nullable=False),
    *timestamps(),
    sa.UniqueConstraint("tenant_id", "managed_document_id"),
    sa.ForeignKeyConstraint(
        ["tenant_id", "dataset_id"], ["datasets.tenant_id", "datasets.dataset_id"]
    ),
    sa.CheckConstraint("generation >= 0"),
    sa.CheckConstraint(
        "status IN ('pending','processing','indexed','failed','deleting','deleted')"
    ),
)

revisions = sa.Table(
    "managed_document_revisions",
    metadata,
    sa.Column("revision_id", sa.String(64), primary_key=True),
    sa.Column("managed_document_id", sa.String(64), nullable=False),
    sa.Column("tenant_id", sa.String(128), nullable=False),
    sa.Column("dataset_id", sa.String(191), nullable=False),
    sa.Column("generation", sa.Integer, nullable=False),
    sa.Column("expected_generation", sa.Integer, nullable=False),
    sa.Column("mutation_id", sa.String(64), nullable=False, unique=True),
    sa.Column("source_checksum", sa.String(64), nullable=False),
    sa.Column("upload_path", sa.Text, nullable=False),
    sa.Column("source_id", sa.String(191), nullable=False, unique=True),
    sa.Column("task_id", sa.String(191), nullable=False, unique=True),
    sa.Column("job_id", sa.String(191), nullable=False, unique=True),
    sa.Column("workflow_id", sa.String(255), nullable=False, unique=True),
    sa.Column("run_id", sa.String(255)),
    sa.Column("status", sa.String(32), nullable=False),
    sa.Column("workflow_input", sa.JSON, nullable=False),
    sa.Column("writer_state", sa.JSON, nullable=False),
    sa.Column("completion", sa.JSON),
    *timestamps(),
    sa.UniqueConstraint("managed_document_id", "generation"),
    sa.UniqueConstraint("managed_document_id", "revision_id"),
    sa.UniqueConstraint("managed_document_id", "revision_id", "generation"),
    sa.ForeignKeyConstraint(
        ["tenant_id", "managed_document_id"],
        ["managed_documents.tenant_id", "managed_documents.managed_document_id"],
    ),
    sa.CheckConstraint("generation > expected_generation AND expected_generation >= 0"),
    sa.CheckConstraint("status IN ('prepared','running','ready','failed','cleaned')"),
)

# A fourth table is necessary: cleanup can be durable before a successor revision
# exists, and DELETE has no successor. It also retains exact replay receipts.
mutations = sa.Table(
    "managed_document_mutations",
    metadata,
    sa.Column("mutation_id", sa.String(64), primary_key=True),
    sa.Column("tenant_id", sa.String(128), nullable=False),
    sa.Column("principal_id", sa.String(255), nullable=False),
    sa.Column("idempotency_key", sa.String(255), nullable=False),
    sa.Column("purpose", sa.String(16), nullable=False),
    sa.Column("target", sa.String(64), nullable=False),
    sa.Column("managed_document_id", sa.String(64), nullable=False),
    sa.Column("request_hash", sa.String(64), nullable=False),
    sa.Column("request", sa.JSON, nullable=False),
    sa.Column("expected_generation", sa.Integer, nullable=False),
    sa.Column("stage", sa.String(32), nullable=False),
    sa.Column("cleanup_snapshot", sa.JSON, nullable=False),
    sa.Column("revision_id", sa.String(64)),
    sa.Column("receipt", sa.JSON),
    *timestamps(),
    sa.UniqueConstraint(
        "tenant_id", "principal_id", "purpose", "target", "idempotency_key"
    ),
    sa.ForeignKeyConstraint(
        ["tenant_id", "managed_document_id"],
        ["managed_documents.tenant_id", "managed_documents.managed_document_id"],
    ),
    sa.CheckConstraint("purpose IN ('create','update','delete')"),
    sa.CheckConstraint(
        "stage IN ('claimed','fenced','drained','cleaned','prepared','dispatched','completed','skipped')"
    ),
)

events = sa.Table(
    "managed_document_events",
    metadata,
    sa.Column("event_id", sa.String(255), primary_key=True),
    sa.Column("payload_hash", sa.String(64), nullable=False),
    sa.Column("managed_document_id", sa.String(64), nullable=False),
    sa.Column("revision_id", sa.String(64), nullable=False),
    sa.Column("kind", sa.String(32), nullable=False),
    sa.Column("receipt", sa.JSON, nullable=False),
    *timestamps(),
    sa.ForeignKeyConstraint(
        ["managed_document_id", "revision_id"],
        [
            "managed_document_revisions.managed_document_id",
            "managed_document_revisions.revision_id",
        ],
    ),
)

TABLES = (documents, revisions, mutations, events)

documents.append_constraint(
    sa.ForeignKeyConstraint(
        ["managed_document_id", "current_revision_id", "generation"],
        [
            "managed_document_revisions.managed_document_id",
            "managed_document_revisions.revision_id",
            "managed_document_revisions.generation",
        ],
        name="fk_managed_current_generation",
        use_alter=True,
        deferrable=True,
        initially="DEFERRED",
    )
)
documents.append_constraint(
    sa.ForeignKeyConstraint(
        ["managed_document_id", "pending_revision_id"],
        [
            "managed_document_revisions.managed_document_id",
            "managed_document_revisions.revision_id",
        ],
        name="fk_managed_pending_revision",
        use_alter=True,
        deferrable=True,
        initially="DEFERRED",
    )
)
