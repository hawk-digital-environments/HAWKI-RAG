"""Tests for how a database failure is reported.

An unreachable database is not a refusal. It has to stay distinguishable
from one, and it must not carry any detail of the connection out of the
process.
"""

import logging
import traceback
from datetime import datetime, timezone
from typing import Any

import pytest
from sqlalchemy.exc import DBAPIError, OperationalError
from sqlalchemy.exc import TimeoutError as PoolTimeoutError

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization.errors import AuthorizationUnavailableError
from hawki_backend.authorization.models import AuthorizationAction
from hawki_backend.persistence.grants import DatabaseGrantReader


class _Result:
    """A result that holds nothing."""

    def fetchone(self) -> None:
        """Return no row."""
        return None


class _FailingConnection:
    """A connection whose statement always fails."""

    def __init__(self, error: Exception) -> None:
        """Fail with the given error."""
        self._error = error

    def execute(self, statement: Any) -> _Result:
        """Fail."""
        raise self._error

    def __enter__(self) -> "_FailingConnection":
        """Enter the connection context."""
        return self

    def __exit__(self, *exception: object) -> bool:
        """Leave the connection context."""
        return False


class _FailingEngine:
    """An engine whose connection always fails."""

    def __init__(self, error: Exception) -> None:
        """Fail with the given error."""
        self._error = error

    def connect(self) -> _FailingConnection:
        """Return the failing connection."""
        return _FailingConnection(self._error)


def _principal() -> AuthenticatedPrincipal:
    """Return an authenticated principal."""
    return AuthenticatedPrincipal(
        kind=PrincipalKind.USER,
        subject="user-1",
        tenant_id="tenant-a",
        client_id="hawki-console",
        scopes=frozenset({"rag:query"}),
        issuer="https://issuer.example",
        expires_at=datetime(2026, 1, 1, tzinfo=timezone.utc),
    )


def _operational_error() -> OperationalError:
    """Return an error that names a host and an account."""
    return OperationalError(
        "SELECT 1",
        {},
        Exception(
            "connection failed: could not connect to server: "
            "host=db.internal port=5432 user=rag_user password=hunter2"
        ),
    )


def _pool_timeout() -> PoolTimeoutError:
    """Return the failure of a connection pool that ran out of connections."""
    return PoolTimeoutError(
        "QueuePool limit of size 5 overflow 10 reached, connection timed out, "
        "timeout 3.00 (QueuePool)"
    )


_INFRASTRUCTURE_FAILURES = {
    "driver": _operational_error,
    "pool": _pool_timeout,
}


def _reader_that_fails(error: Exception) -> DatabaseGrantReader:
    """Return a reader whose grant store cannot answer."""
    return DatabaseGrantReader(_FailingEngine(error))  # type: ignore[arg-type]


@pytest.mark.parametrize("kind", sorted(_INFRASTRUCTURE_FAILURES))
def test_an_unreachable_database_raises_the_unavailable_error(kind: str) -> None:
    reader = _reader_that_fails(_INFRASTRUCTURE_FAILURES[kind]())

    with pytest.raises(AuthorizationUnavailableError):
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)


@pytest.mark.parametrize("kind", sorted(_INFRASTRUCTURE_FAILURES))
def test_the_unavailable_error_carries_no_connection_detail(kind: str) -> None:
    reader = _reader_that_fails(_INFRASTRUCTURE_FAILURES[kind]())

    with pytest.raises(AuthorizationUnavailableError) as captured:
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)

    assert str(captured.value) == "authorization_unavailable"


@pytest.mark.parametrize("kind", sorted(_INFRASTRUCTURE_FAILURES))
def test_the_database_failure_is_not_chained(kind: str) -> None:
    reader = _reader_that_fails(_INFRASTRUCTURE_FAILURES[kind]())

    with pytest.raises(AuthorizationUnavailableError) as captured:
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)

    assert captured.value.__cause__ is None
    assert captured.value.__context__ is None


@pytest.mark.parametrize("kind", sorted(_INFRASTRUCTURE_FAILURES))
def test_no_part_of_the_connection_detail_escapes(kind: str) -> None:
    reader = _reader_that_fails(_INFRASTRUCTURE_FAILURES[kind]())

    with pytest.raises(AuthorizationUnavailableError) as captured:
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)

    rendered = "".join(
        traceback.format_exception(
            type(captured.value), captured.value, captured.value.__traceback__
        )
    )
    assert "QueuePool" not in rendered
    for secret in ("db.internal", "rag_user", "hunter2", "5432"):
        assert secret not in rendered


@pytest.mark.parametrize("kind", sorted(_INFRASTRUCTURE_FAILURES))
def test_an_infrastructure_failure_is_never_a_refusal(kind: str) -> None:
    reader = _reader_that_fails(_INFRASTRUCTURE_FAILURES[kind]())

    with pytest.raises(AuthorizationUnavailableError):
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)


def test_a_broken_statement_is_also_reported_as_unavailable(
    caplog: pytest.LogCaptureFixture,
) -> None:
    reader = DatabaseGrantReader(_FailingEngine(_operational_error()))  # type: ignore[arg-type]

    with caplog.at_level(logging.ERROR), pytest.raises(AuthorizationUnavailableError):
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)

    assert caplog.records
    assert "could not be reached" in caplog.records[0].getMessage()


def test_the_log_does_not_contain_the_connection_detail(
    caplog: pytest.LogCaptureFixture,
) -> None:
    reader = DatabaseGrantReader(_FailingEngine(_operational_error()))  # type: ignore[arg-type]

    with caplog.at_level(logging.DEBUG), pytest.raises(AuthorizationUnavailableError):
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)

    rendered = " ".join(record.getMessage() for record in caplog.records)
    for secret in ("db.internal", "rag_user", "hunter2"):
        assert secret not in rendered


def test_any_database_error_is_reported_as_unavailable() -> None:
    error = DBAPIError("SELECT 1", {}, Exception("boom"))

    reader = DatabaseGrantReader(_FailingEngine(error))  # type: ignore[arg-type]

    with pytest.raises(AuthorizationUnavailableError):
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)


def test_the_unavailable_error_is_not_a_refusal() -> None:
    reader = DatabaseGrantReader(_FailingEngine(_operational_error()))  # type: ignore[arg-type]

    with pytest.raises(AuthorizationUnavailableError):
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)

    assert not issubclass(AuthorizationUnavailableError, (ValueError, PermissionError))


def test_the_pool_timeout_is_logged_without_its_detail(
    caplog: pytest.LogCaptureFixture,
) -> None:
    reader = _reader_that_fails(_pool_timeout())

    with caplog.at_level(logging.ERROR), pytest.raises(AuthorizationUnavailableError):
        reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)

    assert caplog.records
    assert "could not be reached" in caplog.records[0].getMessage()
