"""PostgreSQL locks shared by generation claims and execution reconciliation."""

import sqlalchemy as sa
from sqlalchemy.engine import Connection

from .identity import digest


def lock_key(value: str) -> int:
    return int.from_bytes(bytes.fromhex(digest(value))[:8], "big", signed=True)


def lock(connection: Connection, value: str) -> None:
    if connection.dialect.name == "postgresql":
        connection.execute(
            sa.text("SELECT pg_advisory_xact_lock(:key)"), {"key": lock_key(value)}
        )


def one(connection: Connection, table: sa.Table, *conditions: object) -> dict | None:
    row = (
        connection.execute(sa.select(table).where(*conditions).with_for_update())
        .mappings()
        .first()
    )
    return dict(row) if row else None
