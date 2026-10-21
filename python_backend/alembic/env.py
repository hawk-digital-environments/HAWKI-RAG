"""The Alembic environment for the backend's Python-owned schema.

The backend owns one PostgreSQL schema, `hawki_backend`. Its version table
lives in that schema too, so the migration history of the backend is
separate from the migration history of Laravel, which stays in `public`.

The schema is created before Alembic creates its version table, because the
version table has to live in a schema that already exists.
"""

from alembic import context
from sqlalchemy import engine_from_config, pool, text

from hawki_backend.persistence.schema import SCHEMA_NAME, metadata
from hawki_backend.settings import load_database_settings

config = context.config
target_metadata = metadata


def _owned_schema_of(object_: object) -> str | None:
    """Return the schema an object belongs to, whatever kind of object it is."""
    schema = getattr(object_, "schema", None)
    if schema is None:
        schema = getattr(getattr(object_, "table", None), "schema", None)
    return schema


def _include_owned_object(
    object_: object,
    name: str | None,
    type_: str,
    reflected: bool,
    compare_to: object | None,
) -> bool:
    """Keep only the objects that live in the schema the backend owns.

    The comparison runs over every schema of the database, so the tables of
    the application that still serves the legacy surfaces would otherwise be
    reported as objects to remove.
    """
    return _owned_schema_of(object_) == SCHEMA_NAME


def _database_url() -> str:
    """Return the connection string the migrations run against."""
    settings = load_database_settings()
    if settings is None:
        raise RuntimeError(
            "HAWKI_BACKEND_DB_DSN must be set to run the database migrations"
        )
    return settings.dsn


def run_migrations_offline() -> None:
    """Emit the migrations as SQL, without a database."""
    context.configure(
        url=_database_url(),
        target_metadata=target_metadata,
        version_table_schema=SCHEMA_NAME,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
        compare_type=True,
    )
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    """Run the migrations against a live database."""
    configuration = config.get_section(config.config_ini_section, {})
    configuration["sqlalchemy.url"] = _database_url()
    connectable = engine_from_config(
        configuration,
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    with connectable.connect() as connection:
        # The schema has to exist before Alembic can create its version
        # table inside it.
        connection.execute(text(f"CREATE SCHEMA IF NOT EXISTS {SCHEMA_NAME}"))
        # The search path is set to the schema the backend owns, so that a
        # migration only ever creates, alters or drops objects in it. The
        # connection of the running service does not need this: its queries
        # name the schema explicitly.
        connection.execute(text(f"SET search_path TO {SCHEMA_NAME}"))
        connection.commit()
        context.configure(
            connection=connection,
            target_metadata=target_metadata,
            version_table_schema=SCHEMA_NAME,
            include_schemas=True,
            include_object=_include_owned_object,
            compare_type=True,
        )
        with context.begin_transaction():
            context.run_migrations()
    connectable.dispose()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
