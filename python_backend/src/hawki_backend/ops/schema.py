"""Python-owned, service-scoped saved graph views."""

import sqlalchemy as sa

from hawki_backend.persistence.schema import metadata

snapshots = sa.Table(
    "graph_snapshots",
    metadata,
    sa.Column("id", sa.String(32), primary_key=True),
    sa.Column("tenant_id", sa.String(128), nullable=False),
    sa.Column("client_id", sa.String(255), nullable=False),
    sa.Column("principal_id", sa.String(255), nullable=False),
    sa.Column("dataset_id", sa.String(191), nullable=False),
    sa.Column("name", sa.String(255), nullable=False),
    sa.Column("scene", sa.JSON, nullable=False),
    sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
    sa.ForeignKeyConstraint(
        ["tenant_id", "dataset_id"],
        ["hawki_backend.datasets.tenant_id", "hawki_backend.datasets.dataset_id"],
    ),
)
