"""Tests of the migration lifecycle of the owned schema."""

import uuid

import sqlalchemy as sa
from alembic import command
from alembic.config import Config
from sqlalchemy import inspect
from sqlalchemy.engine import Engine

from hawki_backend.persistence.schema import SCHEMA_NAME

_SENTINEL_PREFIX = "hawki_backend_migration_sentinel_"


def _table_names(engine: Engine) -> set[str]:
    """Return the names of the tables the backend owns."""
    inspector = inspect(engine)
    return set(inspector.get_table_names(schema=SCHEMA_NAME))


def _public_table_names(engine: Engine) -> set[str]:
    """Return the names of the tables of the public schema.

    A fresh inspector is used on purpose, every time. SQLAlchemy caches
    reflection results on an inspector, so one that is reused keeps the
    tables it saw first and cannot see a table that appeared afterwards.
    A snapshot taken with such an inspector would compare the same stale
    set against itself and pass whatever happened in between.
    """
    return set(sa.inspect(engine).get_table_names(schema="public"))


def test_the_migration_creates_the_owned_tables(
    alembic_config: Config, engine: Engine
) -> None:
    command.downgrade(alembic_config, "base")
    command.upgrade(alembic_config, "head")

    assert _table_names(engine) >= {"datasets", "dataset_grants"}


def test_the_migration_history_lives_in_the_owned_schema(
    alembic_config: Config, engine: Engine
) -> None:
    command.upgrade(alembic_config, "head")

    inspector = inspect(engine)

    assert "alembic_version" in inspector.get_table_names(schema=SCHEMA_NAME)
    assert "alembic_version" not in inspector.get_table_names(schema="public")


def test_the_schema_is_never_dropped_by_a_downgrade(
    alembic_config: Config, engine: Engine
) -> None:
    command.upgrade(alembic_config, "head")
    command.downgrade(alembic_config, "base")

    inspector = inspect(engine)

    assert SCHEMA_NAME in inspector.get_schema_names()
    assert "datasets" not in inspector.get_table_names(schema=SCHEMA_NAME)
    assert "dataset_grants" not in inspector.get_table_names(schema=SCHEMA_NAME)


def test_the_migration_can_be_applied_again(
    alembic_config: Config, engine: Engine
) -> None:
    command.upgrade(alembic_config, "head")
    command.downgrade(alembic_config, "base")
    command.upgrade(alembic_config, "head")

    assert _table_names(engine) >= {"datasets", "dataset_grants"}


def test_the_schema_matches_the_runtime_metadata(
    alembic_config: Config, engine: Engine
) -> None:
    command.upgrade(alembic_config, "head")

    command.check(alembic_config)


def test_the_migration_creates_nothing_in_the_public_schema(
    alembic_config: Config, engine: Engine
) -> None:
    before = _public_table_names(engine)

    command.upgrade(alembic_config, "head")
    command.downgrade(alembic_config, "base")
    command.upgrade(alembic_config, "head")

    assert _public_table_names(engine) == before


def test_the_public_snapshot_sees_a_table_that_appeared(engine: Engine) -> None:
    # The snapshot has to be taken again, from a fresh inspector, or a
    # table that appeared between two reads would stay invisible and the
    # comparison would compare one stale set against itself.
    sentinel = f"{_SENTINEL_PREFIX}{uuid.uuid4().hex[:12]}"
    first = _public_table_names(engine)
    assert sentinel not in first
    with engine.begin() as connection:
        connection.execute(sa.text(f'CREATE TABLE public."{sentinel}" (x INT)'))
    try:
        second = _public_table_names(engine)

        assert sentinel in second
        assert second != first
    finally:
        with engine.begin() as connection:
            connection.execute(sa.text(f'DROP TABLE IF EXISTS public."{sentinel}"'))


def test_the_public_snapshot_sees_a_table_that_disappeared(engine: Engine) -> None:
    sentinel = f"{_SENTINEL_PREFIX}{uuid.uuid4().hex[:12]}"
    with engine.begin() as connection:
        connection.execute(sa.text(f'CREATE TABLE public."{sentinel}" (x INT)'))
    try:
        first = _public_table_names(engine)
        assert sentinel in first
        with engine.begin() as connection:
            connection.execute(sa.text(f'DROP TABLE public."{sentinel}"'))

        second = _public_table_names(engine)

        assert sentinel not in second
        assert second != first
    finally:
        with engine.begin() as connection:
            connection.execute(sa.text(f'DROP TABLE IF EXISTS public."{sentinel}"'))


def test_a_table_of_the_legacy_application_is_never_removed(
    alembic_config: Config, engine: Engine
) -> None:
    # The sentinel is a table of its own, in the schema the legacy
    # application owns, with a name nothing else can hold. The test never
    # touches a table it did not create.
    sentinel = f"{_SENTINEL_PREFIX}{uuid.uuid4().hex[:12]}"
    with engine.begin() as connection:
        connection.execute(
            sa.text(
                f'CREATE TABLE public."{sentinel}" '
                "(dataset_id VARCHAR(191) NOT NULL, status VARCHAR(64))"
            )
        )
        connection.execute(
            sa.text(
                f'INSERT INTO public."{sentinel}" '
                "VALUES ('sentinel-dataset', 'active')"
            )
        )
    try:
        command.upgrade(alembic_config, "head")

        command.check(alembic_config)

        inspector = inspect(engine)
        assert sentinel in inspector.get_table_names(schema="public")
        with engine.connect() as connection:
            rows = connection.execute(
                sa.text(f'SELECT dataset_id FROM public."{sentinel}"')
            ).fetchall()
        assert rows == [("sentinel-dataset",)]
    finally:
        with engine.begin() as connection:
            connection.execute(sa.text(f'DROP TABLE IF EXISTS public."{sentinel}"'))
