"""Add independent URL ownership and generation/event evidence."""

from alembic import op
from sqlalchemy.schema import AddConstraint, DropConstraint

from hawki_backend.url_ingestion.schema import TABLES, sources

revision = "0005"
down_revision = "0004"
branch_labels = None
depends_on = None


def upgrade() -> None:
    for table in TABLES:
        table.create(op.get_bind())
    for constraint in sources.foreign_key_constraints:
        if constraint.use_alter:
            op.execute(AddConstraint(constraint))


def downgrade() -> None:
    for constraint in sources.foreign_key_constraints:
        if constraint.use_alter:
            op.execute(DropConstraint(constraint))
    for table in reversed(TABLES):
        table.drop(op.get_bind())
