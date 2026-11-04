"""Restrict dataset deletion while grants reference the dataset.

A grant is retired only by an explicit revocation. Deleting a dataset row
while grants reference it would silently destroy them, so the reference
refuses the deletion instead. No row is changed by this revision; only the
behaviour of the foreign key is.

Revision ID: 0002
Revises: 0001
Create Date: 2026-11-04 00:00:00.000000

"""

from alembic import op

revision = "0002"
down_revision = "0001"
branch_labels = None
depends_on = None

SCHEMA_NAME = "hawki_backend"
_CONSTRAINT_NAME = "fk_dataset_grants_dataset"


def _rebuild(ondelete: str) -> None:
    """Rebuild the grant foreign key with the given deletion behaviour."""
    op.drop_constraint(_CONSTRAINT_NAME, "dataset_grants", schema=SCHEMA_NAME)
    op.create_foreign_key(
        _CONSTRAINT_NAME,
        "dataset_grants",
        "datasets",
        ["tenant_id", "dataset_id"],
        ["tenant_id", "dataset_id"],
        ondelete=ondelete,
        source_schema=SCHEMA_NAME,
        referent_schema=SCHEMA_NAME,
    )


def upgrade() -> None:
    """Make a dataset that grants reference refuse deletion."""
    _rebuild("RESTRICT")


def downgrade() -> None:
    """Restore the cascading behaviour this revision replaced."""
    _rebuild("CASCADE")
