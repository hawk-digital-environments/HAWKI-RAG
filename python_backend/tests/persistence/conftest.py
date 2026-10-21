"""Shared fixtures for the persistence tests.

The tests that need a real database are skipped when no connection string
is configured, so the suite stays green on a machine without PostgreSQL.
"""

import os
from collections.abc import Iterator

import pytest
from alembic import command
from alembic.config import Config
from sqlalchemy import create_engine
from sqlalchemy.engine import Engine

from hawki_backend.settings import DatabaseSettings, load_database_settings

from .guard import is_disposable_database

_BACKEND_ROOT = os.path.dirname(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
)
ALEMBIC_INI = os.path.join(_BACKEND_ROOT, "alembic.ini")


@pytest.fixture(scope="session")
def database_settings() -> DatabaseSettings:
    """Return the configured database, or skip the test.

    The database-backed tests are destructive. They only run against a
    database that the operator has marked as disposable, whose name ends
    with ``_test``. Everything else skips.
    """
    settings = load_database_settings()
    if settings is None:
        pytest.skip("no database is configured for the persistence tests")
    if not is_disposable_database(settings.dsn, os.environ):
        pytest.skip(
            "the persistence tests need a disposable test database: set "
            "HAWKI_BACKEND_DB_TESTING=1 and use a database whose name ends "
            "with _test"
        )
    return settings


@pytest.fixture(scope="session")
def alembic_config(database_settings: DatabaseSettings) -> Config:
    """Return the configuration the migrations run with."""
    return Config(ALEMBIC_INI)


@pytest.fixture(scope="session")
def migrated(alembic_config: Config) -> Iterator[None]:
    """Bring the owned schema to the latest revision."""
    command.upgrade(alembic_config, "head")
    yield
    command.upgrade(alembic_config, "head")


@pytest.fixture(scope="session")
def engine(database_settings: DatabaseSettings) -> Iterator[Engine]:
    """Return an engine that reaches the test database."""
    created = create_engine(database_settings.dsn, pool_pre_ping=True)
    try:
        yield created
    finally:
        created.dispose()
