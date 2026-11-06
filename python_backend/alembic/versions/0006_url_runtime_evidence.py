"""Add URL refresh and external writer evidence to existing Epoch 11 databases."""

from alembic import op

revision = "0006"
down_revision = "0005"
branch_labels = None
depends_on = None


def upgrade() -> None:
    # The uncommitted 0005 creates from metadata on fresh installations. These
    # idempotent PostgreSQL statements also upgrade earlier Epoch 11 databases
    # and compile in Alembic's offline SQL mode without inspecting a live DB.
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_sources ADD COLUMN IF NOT EXISTS refresh_cadence VARCHAR(16)"
    )
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_sources ADD COLUMN IF NOT EXISTS next_refresh_at TIMESTAMP WITH TIME ZONE"
    )
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_generations ADD COLUMN IF NOT EXISTS external_job JSON"
    )
    op.execute("""UPDATE hawki_backend.url_ingestion_generations
        SET external_job = json_build_object(
            'job_id', job_id, 'lifecycle', 'unknown', 'quiescent', false,
            'cancel_requested_at', NULL)
        WHERE external_job IS NULL""")
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_generations ALTER COLUMN external_job SET NOT NULL"
    )


def downgrade() -> None:
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_generations DROP COLUMN external_job"
    )
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_sources DROP COLUMN next_refresh_at"
    )
    op.execute(
        "ALTER TABLE hawki_backend.url_ingestion_sources DROP COLUMN refresh_cadence"
    )
