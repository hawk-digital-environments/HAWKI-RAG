"""Explicit cancel generations and intent on the existing URL request authority."""

from alembic import op

revision = "0008"
down_revision = "0007"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_requests ADD COLUMN IF NOT EXISTS task_command JSON"
    )
    # 0005 uses live metadata on fresh installs. Older installations have
    # PostgreSQL-generated check names; select checks by their constrained column.
    op.execute("""DO $$ DECLARE item RECORD; BEGIN
      FOR item IN SELECT c.conrelid::regclass AS relation, c.conname
        FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid
          AND a.attnum=ANY(c.conkey)
        WHERE c.contype='c' AND c.conrelid IN (
          'hawki_backend.url_ingestion_requests'::regclass,
          'hawki_backend.url_ingestion_generations'::regclass,
          'hawki_backend.url_ingestion_sources'::regclass)
          AND a.attname IN ('purpose','status')
      LOOP EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', item.relation, item.conname);
      END LOOP;
    END $$""")
    for table in ("requests", "generations"):
        op.execute(
            f"ALTER TABLE hawki_backend.url_ingestion_{table} ADD CONSTRAINT ck_url_{table}_purpose CHECK (purpose IN ('start','delete','cancel'))"
        )
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_sources ADD CONSTRAINT ck_url_sources_status CHECK (status IN ('pending','running','ready','failed','cancelling','cancelled','deleting','deleted'))"
    )


def downgrade() -> None:
    # Refuse rollback while explicit cancel authority exists. A config rollback
    # does not require a schema rollback or reclassify existing Python work.
    op.execute("""DO $$ BEGIN
      IF EXISTS (SELECT 1 FROM hawki_backend.url_ingestion_requests WHERE task_command IS NOT NULL)
      THEN RAISE EXCEPTION 'URL task command authority must be retained'; END IF;
    END $$""")
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_sources ADD CONSTRAINT ck_url_old_status CHECK (status IN ('pending','running','ready','failed','deleting','deleted'))"
    )
    for table in ("requests", "generations"):
        op.execute(
            f"ALTER TABLE hawki_backend.url_ingestion_{table} ADD CONSTRAINT ck_url_old_{table}_purpose CHECK (purpose IN ('start','delete'))"
        )
        op.execute(
            f"ALTER TABLE hawki_backend.url_ingestion_{table} DROP CONSTRAINT ck_url_{table}_purpose"
        )
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_sources DROP CONSTRAINT ck_url_sources_status"
    )
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_requests DROP COLUMN task_command"
    )
