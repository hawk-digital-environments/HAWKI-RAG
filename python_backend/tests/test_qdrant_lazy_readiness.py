"""Readiness distinguishes unused datasets from lost ingestion collections."""

from unittest.mock import MagicMock

import pytest
import sqlalchemy as sa

from hawki_backend import readiness
from test_epoch15c_readiness import environment

HISTORY = (
    "managed_documents",
    "ingest_text_operations",
    "url_ingestion_requests",
    "url_ingestion_sources",
)


@pytest.fixture
def connection():
    engine = sa.create_engine("sqlite://")
    with engine.connect() as connection:
        connection.execute(sa.text("ATTACH DATABASE ':memory:' AS hawki_backend"))
        connection.execute(
            sa.text(
                "CREATE TABLE hawki_backend.datasets "
                "(tenant_id TEXT, dataset_id TEXT, status TEXT, qdrant_collection TEXT)"
            )
        )
        for table in HISTORY:
            connection.execute(
                sa.text(
                    f"CREATE TABLE hawki_backend.{table} "
                    "(tenant_id TEXT, dataset_id TEXT, status TEXT)"
                )
            )
        connection.execute(
            sa.text(
                "INSERT INTO hawki_backend.datasets VALUES ('one','fixture','active','canonical')"
            )
        )
        yield connection
    engine.dispose()


def test_never_ingested_dataset_needs_no_collection(connection):
    assert readiness._required_qdrant_collections(connection) == set()


@pytest.mark.parametrize("table", HISTORY)
@pytest.mark.parametrize(
    "status", ["pending", "failed", "ready", "deleted", "cancelled"]
)
def test_all_ingestion_history_requires_collection(connection, table, status):
    connection.execute(
        sa.text(f"INSERT INTO hawki_backend.{table} VALUES ('one','fixture',:status)"),
        {"status": status},
    )
    assert readiness._required_qdrant_collections(connection) == {"canonical"}


@pytest.mark.parametrize("table", HISTORY)
def test_other_tenant_history_does_not_change_empty_dataset(connection, table):
    connection.execute(
        sa.text(f"INSERT INTO hawki_backend.{table} VALUES ('other','fixture','ready')")
    )
    assert readiness._required_qdrant_collections(connection) == set()


@pytest.mark.parametrize("collection", [None, "", "   "])
def test_empty_dataset_still_requires_canonical_metadata(connection, collection):
    connection.execute(
        sa.text("UPDATE hawki_backend.datasets SET qdrant_collection=:collection"),
        {"collection": collection},
    )
    with pytest.raises(ValueError, match="Canonical"):
        readiness._required_qdrant_collections(connection)


@pytest.mark.parametrize(
    "required,available,passes",
    [
        (set(), set(), True),
        ({"canonical"}, set(), False),
        ({"canonical"}, {"canonical"}, True),
    ],
)
def test_missing_collection_is_allowed_only_without_history(
    monkeypatch, required, available, passes
):
    engine = MagicMock()
    monkeypatch.setattr(readiness, "build_engine", lambda _: engine)
    monkeypatch.setattr(readiness, "_required_qdrant_collections", lambda _: required)
    monkeypatch.setattr(readiness, "_qdrant_inventory", lambda *_: available)
    if passes:
        readiness._qdrant(environment())
    else:
        with pytest.raises(ValueError, match="Canonical"):
            readiness._qdrant(environment())
    engine.dispose.assert_called_once()


def test_database_history_failure_is_not_treated_as_empty(monkeypatch):
    engine = MagicMock()
    connection = engine.connect.return_value.__enter__.return_value
    connection.execute.side_effect = RuntimeError("history unavailable")
    monkeypatch.setattr(readiness, "build_engine", lambda _: engine)
    monkeypatch.setattr(readiness, "_qdrant_inventory", lambda *_: set())
    with pytest.raises(RuntimeError):
        readiness._qdrant(environment())
    engine.dispose.assert_called_once()
