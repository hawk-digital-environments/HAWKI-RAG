"""Tests for the database configuration of the backend."""

import pytest

from hawki_backend.settings import (
    DatabaseConfigurationError,
    DatabaseSettings,
    load_database_settings,
)

_DSN = "postgresql+psycopg://rag_user:change_me@postgres:5432/hawki_rag"


def test_no_database_variable_means_no_database() -> None:
    assert load_database_settings({}) is None


def test_an_unrelated_variable_does_not_configure_the_database() -> None:
    assert load_database_settings({"PATH": "/usr/bin"}) is None


def test_the_connection_string_is_required() -> None:
    with pytest.raises(DatabaseConfigurationError, match="DSN"):
        load_database_settings({"HAWKI_BACKEND_DB_POOL_SIZE": "5"})


def test_an_empty_connection_string_is_refused() -> None:
    with pytest.raises(DatabaseConfigurationError, match="DSN"):
        load_database_settings({"HAWKI_BACKEND_DB_DSN": "  "})


def test_a_connection_string_without_a_host_is_refused() -> None:
    with pytest.raises(DatabaseConfigurationError, match="host"):
        load_database_settings(
            {"HAWKI_BACKEND_DB_DSN": "postgresql+psycopg:///hawki_rag"}
        )


def test_a_connection_string_with_whitespace_is_refused() -> None:
    with pytest.raises(DatabaseConfigurationError, match="whitespace"):
        load_database_settings(
            {"HAWKI_BACKEND_DB_DSN": "postgresql+psycopg://a b@postgres/hawki_rag"}
        )


def test_a_connection_string_with_a_fragment_is_refused() -> None:
    with pytest.raises(DatabaseConfigurationError, match="fragment"):
        load_database_settings(
            {
                "HAWKI_BACKEND_DB_DSN": (
                    "postgresql+psycopg://rag_user@postgres/hawki_rag#other"
                )
            }
        )


def test_another_driver_is_refused() -> None:
    with pytest.raises(DatabaseConfigurationError, match="scheme"):
        load_database_settings(
            {"HAWKI_BACKEND_DB_DSN": "postgresql://rag_user@postgres/hawki_rag"}
        )


def test_an_unknown_database_variable_is_refused() -> None:
    with pytest.raises(DatabaseConfigurationError, match="unknown"):
        load_database_settings(
            {
                "HAWKI_BACKEND_DB_DSN": _DSN,
                "HAWKI_BACKEND_DB_HOST": "postgres",
            }
        )


def test_the_testing_marker_is_known_and_changes_nothing() -> None:
    settings = load_database_settings(
        {
            "HAWKI_BACKEND_DB_DSN": _DSN,
            "HAWKI_BACKEND_DB_TESTING": "1",
        }
    )

    assert settings is not None
    assert settings.dsn == _DSN
    assert settings.pool_size == 5


def test_the_defaults_are_used_when_nothing_else_is_set() -> None:
    settings = load_database_settings({"HAWKI_BACKEND_DB_DSN": _DSN})

    assert settings is not None
    assert settings.dsn == _DSN
    assert settings.pool_size == 5
    assert settings.pool_timeout_seconds == 3
    assert settings.pool_recycle_seconds == 300
    assert settings.connect_timeout_seconds == 3
    assert settings.statement_timeout_ms == 2000


def test_the_timeouts_can_be_configured() -> None:
    settings = load_database_settings(
        {
            "HAWKI_BACKEND_DB_DSN": _DSN,
            "HAWKI_BACKEND_DB_POOL_SIZE": "9",
            "HAWKI_BACKEND_DB_POOL_TIMEOUT_SECONDS": "7",
            "HAWKI_BACKEND_DB_POOL_RECYCLE_SECONDS": "60",
            "HAWKI_BACKEND_DB_CONNECT_TIMEOUT_SECONDS": "2",
            "HAWKI_BACKEND_DB_STATEMENT_TIMEOUT_MS": "1500",
        }
    )

    assert settings is not None
    assert settings.pool_size == 9
    assert settings.pool_timeout_seconds == 7
    assert settings.pool_recycle_seconds == 60
    assert settings.connect_timeout_seconds == 2
    assert settings.statement_timeout_ms == 1500


@pytest.mark.parametrize(
    ("name", "value"),
    [
        ("POOL_SIZE", "0"),
        ("POOL_SIZE", "many"),
        ("POOL_TIMEOUT_SECONDS", "0"),
        ("POOL_TIMEOUT_SECONDS", "61"),
        ("POOL_RECYCLE_SECONDS", "-1"),
        ("CONNECT_TIMEOUT_SECONDS", "0"),
        ("STATEMENT_TIMEOUT_MS", "10"),
        ("STATEMENT_TIMEOUT_MS", "60001"),
    ],
)
def test_an_out_of_range_value_is_refused(name: str, value: str) -> None:
    with pytest.raises(DatabaseConfigurationError, match=name):
        load_database_settings(
            {
                "HAWKI_BACKEND_DB_DSN": _DSN,
                f"HAWKI_BACKEND_DB_{name}": value,
            }
        )


def test_the_settings_are_immutable() -> None:
    settings = load_database_settings({"HAWKI_BACKEND_DB_DSN": _DSN})

    assert settings is not None
    with pytest.raises(Exception):
        settings.dsn = "postgresql+psycopg://other@other/other"  # type: ignore[misc]


def test_the_representation_never_exposes_the_connection_string() -> None:
    settings = DatabaseSettings(
        dsn="postgresql+psycopg://secret-user:hunter2@postgres:5432/db"
    )

    rendered = repr(settings)

    for secret in ("hunter2", "secret-user", "postgresql+psycopg://"):
        assert secret not in rendered


def test_the_representation_still_shows_the_ordinary_configuration() -> None:
    settings = DatabaseSettings(
        dsn="postgresql+psycopg://secret-user:hunter2@postgres:5432/db",
        pool_size=9,
    )

    rendered = repr(settings)

    assert "pool_size=9" in rendered
    assert "statement_timeout_ms" in rendered


def test_the_settings_still_compare_by_value() -> None:
    first = DatabaseSettings(dsn="postgresql+psycopg://u:p@h:5432/db")
    second = DatabaseSettings(dsn="postgresql+psycopg://u:p@h:5432/db")
    other = DatabaseSettings(dsn="postgresql+psycopg://u:p@h:5432/other")

    assert first == second
    assert first != other
    assert hash(first) == hash(second)
