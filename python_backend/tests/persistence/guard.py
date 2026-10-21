"""The safety guard for the database-backed persistence tests.

The persistence tests destroy what they work on. They run `alembic
downgrade base`, they delete every row of the owned tables, and they
recreate the migration state. That is only ever acceptable against a
database that exists to be destroyed.

Two conditions must both hold before any of it happens, and neither of them
is inferred from anything but the two facts themselves:

* ``HAWKI_BACKEND_DB_TESTING`` is exactly ``1``, so the operator has said
  out loud that this database is disposable, and
* the database the connection string names ends with ``_test``.

A real application database therefore never runs the destructive tests, even
if its connection string is exported by accident.

The database name has exactly one authoritative source: the URL, read the
way the driver reads it. A connection string that also names a database in
its query string is refused outright, because the query is what the driver
ends up using and the two names could disagree.

The check lives here, in one place, so that it cannot be applied to one
test and forgotten in the next.
"""

from collections.abc import Mapping

from sqlalchemy.engine import make_url
from sqlalchemy.exc import ArgumentError

TESTING_VARIABLE = "HAWKI_BACKEND_DB_TESTING"
TESTING_VALUE = "1"
_TEST_DATABASE_SUFFIX = "_test"
_DATABASE_QUERY_PARAMETER = "dbname"


def database_name(dsn: str) -> str | None:
    """Return the database a connection string names.

    Returns:
        The name of the database, or ``None`` when the connection string
        does not name one, cannot be read, or names one in its query
        string. A connection string that carries a ``dbname`` query
        parameter is never treated as a test database: the driver would
        connect to the database in the query, not the one in the path, and
        the two could name different things.
    """
    try:
        url = make_url(dsn)
    except (ArgumentError, ValueError):
        return None
    if _names_a_database_in_the_query(url.query):
        return None
    return url.database


def _names_a_database_in_the_query(query: Mapping[str, object]) -> bool:
    """Return whether the query string names a database of its own."""
    return any(str(key).lower() == _DATABASE_QUERY_PARAMETER for key in query)


def is_disposable_database(dsn: str, environ: Mapping[str, str]) -> bool:
    """Return whether the destructive persistence tests may run.

    Args:
        dsn: The connection string the tests would use.
        environ: The environment the operator configured.

    Returns:
        True only when the operator has marked the database as disposable
        *and* the database it names is a test database. Anything else is
        False, and the caller skips.
    """
    if environ.get(TESTING_VARIABLE) != TESTING_VALUE:
        return False
    name = database_name(dsn)
    if name is None:
        return False
    return name.lower().endswith(_TEST_DATABASE_SUFFIX)


__all__ = [
    "TESTING_VARIABLE",
    "TESTING_VALUE",
    "database_name",
    "is_disposable_database",
]
