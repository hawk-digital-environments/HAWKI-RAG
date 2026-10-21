"""Tests for the safety guard of the database-backed persistence tests.

The guard decides whether the destructive tests may run at all. It is
checked here without a database, because the decision must hold even when
no database is reachable.
"""

import pytest

from .guard import database_name, is_disposable_database

_TEST_DSN = "postgresql+psycopg://hawki:hawki@postgres:5432/hawki_backend_test"
_APPLICATION_DSN = "postgresql+psycopg://rag_user:change_me@postgres:5432/hawki_rag"


def test_a_marked_test_database_is_allowed() -> None:
    assert is_disposable_database(_TEST_DSN, {"HAWKI_BACKEND_DB_TESTING": "1"})


def test_an_unmarked_test_database_is_refused() -> None:
    assert not is_disposable_database(_TEST_DSN, {})


def test_a_marked_application_database_is_refused() -> None:
    assert not is_disposable_database(
        _APPLICATION_DSN, {"HAWKI_BACKEND_DB_TESTING": "1"}
    )


def test_the_marking_must_be_exactly_one() -> None:
    for value in ("0", "true", "yes", "on", " 1", "1 ", "11"):
        assert not is_disposable_database(
            _TEST_DSN, {"HAWKI_BACKEND_DB_TESTING": value}
        ), value


def test_a_database_without_a_test_suffix_is_refused() -> None:
    for name in ("hawki_rag", "hawki_backend", "test", "production", "test_hawki_rag"):
        dsn = f"postgresql+psycopg://hawki:hawki@postgres:5432/{name}"
        assert not is_disposable_database(dsn, {"HAWKI_BACKEND_DB_TESTING": "1"}), name


def test_a_database_with_a_test_suffix_is_allowed() -> None:
    for name in ("hawki_backend_test", "hawki_test", "a_test", "x_test"):
        dsn = f"postgresql+psycopg://hawki:hawki@postgres:5432/{name}"
        assert is_disposable_database(dsn, {"HAWKI_BACKEND_DB_TESTING": "1"}), name


def test_a_connection_string_without_a_database_is_refused() -> None:
    dsn = "postgresql+psycopg://hawki:hawki@postgres:5432"

    assert not is_disposable_database(dsn, {"HAWKI_BACKEND_DB_TESTING": "1"})


def test_a_connection_string_that_cannot_be_parsed_is_refused() -> None:
    dsn = "postgresql+psycopg://user:secret@[bad:host:5432/db"

    assert not is_disposable_database(dsn, {"HAWKI_BACKEND_DB_TESTING": "1"})


def test_the_database_name_is_read_from_the_path() -> None:
    assert database_name(_TEST_DSN) == "hawki_backend_test"
    assert database_name(_APPLICATION_DSN) == "hawki_rag"


def test_a_database_named_in_the_query_string_is_refused() -> None:
    # The driver connects to the database in the query, not the one in the
    # path, so the name the guard reads would not be the name the driver
    # uses. A connection string that names a database twice is refused.
    dsn = "postgresql+psycopg://hawki:hawki@postgres:5432/?dbname=hawki_backend_test"

    assert database_name(dsn) is None
    assert not is_disposable_database(dsn, {"HAWKI_BACKEND_DB_TESTING": "1"})


def test_a_query_string_database_cannot_bypass_the_guard() -> None:
    # The path names a test database, the query names the application
    # database, and the driver would connect to the application database.
    dsn = (
        "postgresql+psycopg://hawki:hawki@localhost:5432/"
        "hawki_backend_test?dbname=hawki_rag"
    )

    assert not is_disposable_database(dsn, {"HAWKI_BACKEND_DB_TESTING": "1"})


def test_a_query_string_database_is_refused_even_when_both_names_are_safe() -> None:
    dsn = (
        "postgresql+psycopg://hawki:hawki@localhost:5432/"
        "hawki_backend_test?dbname=hawki_backend_test"
    )

    assert not is_disposable_database(dsn, {"HAWKI_BACKEND_DB_TESTING": "1"})


def test_a_query_string_database_is_refused_whatever_its_case() -> None:
    for parameter in ("dbname", "DBNAME", "DbName"):
        dsn = (
            f"postgresql+psycopg://hawki:hawki@localhost:5432/"
            f"hawki_backend_test?{parameter}=hawki_rag"
        )
        assert not is_disposable_database(dsn, {"HAWKI_BACKEND_DB_TESTING": "1"}), (
            parameter
        )


def test_the_database_name_is_absent_when_there_is_none() -> None:
    assert database_name("postgresql+psycopg://hawki:hawki@postgres:5432") is None
    assert database_name("postgresql+psycopg://user:secret@[bad:host:5432/db") is None


@pytest.mark.parametrize(
    "dsn",
    [
        "postgresql+psycopg://hawki:hawki@postgres:5432/hawki_backend_test",
        "postgresql+psycopg://hawki:hawki@postgres:5432/hawki_rag",
    ],
)
def test_the_guard_never_depends_on_anything_but_the_two_facts(
    dsn: str,
) -> None:
    # The guard reads the marking and the database name. It reads nothing
    # else, so the same environment decides for every connection string.
    for environ in (
        {},
        {"HAWKI_BACKEND_DB_TESTING": "1"},
        {"HAWKI_BACKEND_DB_TESTING": "0"},
    ):
        assert is_disposable_database(dsn, environ) == (
            environ.get("HAWKI_BACKEND_DB_TESTING") == "1" and dsn.endswith("_test")
        )
