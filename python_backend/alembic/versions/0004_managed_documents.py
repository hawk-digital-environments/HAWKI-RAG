"""Add managed upload ownership without adopting any Laravel state."""

from alembic import op
from sqlalchemy.schema import AddConstraint, DropConstraint

from hawki_backend.managed_documents.schema import TABLES

revision = "0004"
down_revision = "0003"
branch_labels = None
depends_on = None


def upgrade() -> None:
    for table in TABLES:
        table.create(op.get_bind())
    for constraint in TABLES[0].foreign_key_constraints:
        if constraint.use_alter:
            op.execute(AddConstraint(constraint))


def downgrade() -> None:
    for constraint in TABLES[0].foreign_key_constraints:
        if constraint.use_alter:
            op.execute(DropConstraint(constraint))
    for table in reversed(TABLES):
        table.drop(op.get_bind())
