"""Service ownership and Python-owned graph views for the standalone RAG UI."""

from alembic import op
from hawki_backend.ops.schema import snapshots

revision = "0009"
down_revision = "0008"
branch_labels = None
depends_on = None

OWNER_TABLES = (
    "managed_documents",
    "managed_document_mutations",
    "url_ingestion_requests",
    "url_ingestion_sources",
)
KEYS = (
    (
        "managed_document_mutations",
        ["tenant_id", "principal_id", "purpose", "target", "idempotency_key"],
    ),
    ("url_ingestion_requests", ["tenant_id", "principal_id", "idempotency_key"]),
)


def upgrade() -> None:
    for table in OWNER_TABLES:
        op.execute(
            f"ALTER TABLE hawki_backend.{table} ADD COLUMN IF NOT EXISTS "
            "principal_type VARCHAR(16) NOT NULL DEFAULT 'user'"
        )
    # Earlier revisions use live definitions, which may already have the new
    # key on fresh installs. SQL catalog checks also support offline rendering.
    for table, columns in KEYS:
        old_columns = ",".join("'" + column + "'" for column in columns)
        new_columns = ",".join([columns[0], "principal_type", *columns[1:]])
        name = "uq_" + table + "_principal_key"
        op.execute(f"""DO $$ DECLARE item RECORD; BEGIN
          FOR item IN SELECT c.conname FROM pg_constraint c
            WHERE c.contype='u' AND c.conrelid='hawki_backend.{table}'::regclass
              AND ARRAY(SELECT a.attname::text FROM unnest(c.conkey)
                WITH ORDINALITY k(attnum, position)
                JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.attnum
                ORDER BY k.position) = ARRAY[{old_columns}]::text[]
          LOOP EXECUTE format('ALTER TABLE hawki_backend.{table} DROP CONSTRAINT %I', item.conname);
          END LOOP;
          IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='hawki_backend.{table}'::regclass AND conname='{name}')
          THEN ALTER TABLE hawki_backend.{table} ADD CONSTRAINT {name} UNIQUE ({new_columns}); END IF;
        END $$""")
    snapshots.create(op.get_bind(), checkfirst=True)


def downgrade() -> None:
    # An empty test schema can traverse old history, without losing a service
    # operation or graph view. Configuration rollback needs no schema rollback.
    conditions = ["EXISTS (SELECT 1 FROM hawki_backend.graph_snapshots)"] + [
        f"EXISTS (SELECT 1 FROM hawki_backend.{table} WHERE principal_type='service')"
        for table in OWNER_TABLES
    ]
    op.execute(
        "DO $$ BEGIN IF "
        + " OR ".join(conditions)
        + " THEN RAISE EXCEPTION 'Retain service ownership and graph views'; END IF; END $$"
    )
    snapshots.drop(op.get_bind())
    for table, columns in KEYS:
        op.drop_constraint(
            "uq_" + table + "_principal_key",
            table,
            schema="hawki_backend",
            type_="unique",
        )
        op.create_unique_constraint(
            "uq_" + table + "_legacy_key", table, columns, schema="hawki_backend"
        )
    for table in OWNER_TABLES:
        op.drop_column(table, "principal_type", schema="hawki_backend")
