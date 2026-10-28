"""Tests for the statement the database grant reader runs.

The statement is the whole authorization lookup. If a predicate is dropped
from it, a caller reaches a dataset it must not reach, so the shape of the
statement is asserted here rather than only its outcome.
"""

from datetime import datetime, timezone
from typing import Any

import pytest
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization.models import AuthorizationAction
from hawki_backend.persistence.grants import DatabaseGrantReader, _grant_statement


def _principal(
    kind: PrincipalKind = PrincipalKind.USER,
    subject: str = "user-1",
    tenant_id: str = "tenant-a",
    client_id: str = "hawki-console",
) -> AuthenticatedPrincipal:
    """Return an authenticated principal."""
    return AuthenticatedPrincipal(
        kind=kind,
        subject=subject,
        tenant_id=tenant_id,
        client_id=client_id,
        scopes=frozenset({"rag:query", "rag:ingest"}),
        issuer="https://issuer.example",
        expires_at=datetime(2026, 1, 1, tzinfo=timezone.utc),
    )


class _FakeResult:
    """A result that holds at most one row."""

    def __init__(self, row: tuple[Any, ...] | None) -> None:
        """Hold the row the statement matched."""
        self._row = row

    def fetchone(self) -> tuple[Any, ...] | None:
        """Return the row, or None when nothing matched."""
        return self._row


class _FakeConnection:
    """A connection that records the statement it is given."""

    def __init__(
        self,
        row: tuple[Any, ...] | None = None,
        error: Exception | None = None,
    ) -> None:
        """Answer the statement with a row, or with an error."""
        self._row = row
        self._error = error
        self.statements: list[Any] = []

    def execute(self, statement: Any) -> _FakeResult:
        """Record the statement and answer it."""
        self.statements.append(statement)
        if self._error is not None:
            raise self._error
        return _FakeResult(self._row)

    def __enter__(self) -> "_FakeConnection":
        """Enter the connection context."""
        return self

    def __exit__(self, *exception: object) -> bool:
        """Leave the connection context."""
        return False


class _FakeEngine:
    """An engine that hands out one connection."""

    def __init__(self, connection: _FakeConnection) -> None:
        """Hand out the connection."""
        self._connection = connection

    def connect(self) -> _FakeConnection:
        """Return the connection."""
        return self._connection


def _compiled(
    principal: AuthenticatedPrincipal | None = None,
    dataset_id: str = "dataset-1",
    action: AuthorizationAction = AuthorizationAction.QUERY,
) -> tuple[str, dict[str, Any]]:
    """Return the compiled statement and its parameters."""
    statement = _grant_statement(
        principal if principal is not None else _principal(),
        dataset_id,
        action,
    )
    compiled = statement.compile(dialect=postgresql.dialect())
    return str(compiled), dict(compiled.params)


def test_the_statement_reads_only_the_owned_schema() -> None:
    sql, _ = _compiled()

    assert "hawki_backend.dataset_grants" in sql
    assert "hawki_backend.datasets" in sql
    assert "public." not in sql


def test_the_statement_matches_the_tenant() -> None:
    sql, params = _compiled()

    assert "hawki_backend.dataset_grants.tenant_id = %(tenant_id)s" in sql
    assert params["tenant_id"] == "tenant-a"


def test_the_statement_matches_the_principal_kind() -> None:
    sql, params = _compiled()

    assert "hawki_backend.dataset_grants.principal_type = %(principal_type)s" in sql
    assert params["principal_type"] == "user"


def test_the_statement_matches_the_principal_id() -> None:
    sql, params = _compiled()

    assert "hawki_backend.dataset_grants.principal_id = %(principal_id)s" in sql
    assert params["principal_id"] == "user-1"


def test_the_statement_matches_the_dataset() -> None:
    sql, params = _compiled()

    assert "hawki_backend.dataset_grants.dataset_id = %(dataset_id)s" in sql
    assert params["dataset_id"] == "dataset-1"


def test_the_statement_matches_the_permission() -> None:
    sql, params = _compiled()

    assert "hawki_backend.dataset_grants.permission = %(permission)s" in sql
    assert params["permission"] == "query"


def test_the_statement_requires_an_active_dataset() -> None:
    sql, params = _compiled()

    assert "hawki_backend.datasets.status = 'active'" in sql
    assert "status" not in params


def test_the_statement_carries_only_the_five_identity_values() -> None:
    _, params = _compiled()

    assert params == {
        "tenant_id": "tenant-a",
        "principal_type": "user",
        "principal_id": "user-1",
        "dataset_id": "dataset-1",
        "permission": "query",
    }


def test_the_statement_joins_the_dataset_on_tenant_and_dataset() -> None:
    sql, _ = _compiled()

    assert (
        "hawki_backend.datasets.tenant_id = hawki_backend.dataset_grants.tenant_id"
        in sql
    )
    assert (
        "hawki_backend.datasets.dataset_id = hawki_backend.dataset_grants.dataset_id"
        in sql
    )


def test_the_statement_reads_one_row_at_most() -> None:
    sql, _ = _compiled()

    assert "LIMIT 1" in sql


def test_the_statement_is_a_single_select() -> None:
    sql, _ = _compiled()

    assert sql.count("SELECT") == 1
    assert "INSERT" not in sql
    assert "UPDATE" not in sql
    assert "DELETE" not in sql


def test_the_permission_follows_the_action() -> None:
    _, params = _compiled(action=AuthorizationAction.INGEST)

    assert params["permission"] == "ingest"


def test_a_service_principal_is_matched_by_its_client_id() -> None:
    principal = _principal(
        kind=PrincipalKind.SERVICE, subject="ignored", client_id="batch-importer"
    )

    _, params = _compiled(principal=principal)

    assert params["principal_type"] == "service"
    assert params["principal_id"] == "batch-importer"


def test_a_matched_row_is_allowed() -> None:
    connection = _FakeConnection(row=(1,))
    reader = DatabaseGrantReader(_FakeEngine(connection))  # type: ignore[arg-type]

    assert reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)


def test_an_unmatched_lookup_is_refused() -> None:
    connection = _FakeConnection(row=None)
    reader = DatabaseGrantReader(_FakeEngine(connection))  # type: ignore[arg-type]

    assert not reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)


def test_the_reader_runs_exactly_one_statement() -> None:
    connection = _FakeConnection(row=None)
    reader = DatabaseGrantReader(_FakeEngine(connection))  # type: ignore[arg-type]

    reader.has_grant(_principal(), "dataset-1", AuthorizationAction.QUERY)

    assert len(connection.statements) == 1


def test_the_reader_never_asks_whether_the_dataset_exists() -> None:
    connection = _FakeConnection(row=None)
    reader = DatabaseGrantReader(_FakeEngine(connection))  # type: ignore[arg-type]

    reader.has_grant(
        _principal(), "dataset-that-does-not-exist", AuthorizationAction.QUERY
    )

    assert len(connection.statements) == 1
    sql = str(connection.statements[0])
    assert sql.count("SELECT") == 1


@pytest.mark.parametrize(
    "dataset_id",
    ["dataset-1", "unknown-dataset", "", " dataset-1"],
)
def test_every_dataset_id_is_answered_with_the_same_statement(
    dataset_id: str,
) -> None:
    connection = _FakeConnection(row=None)
    reader = DatabaseGrantReader(_FakeEngine(connection))  # type: ignore[arg-type]

    reader.has_grant(_principal(), dataset_id, AuthorizationAction.QUERY)

    assert len(connection.statements) == 1


def test_the_statement_is_a_select_of_a_constant() -> None:
    sql, _ = _compiled()

    assert "SELECT 1" in sql


def test_the_reader_uses_a_select_statement() -> None:
    statement = _grant_statement(_principal(), "dataset-1", AuthorizationAction.QUERY)

    assert isinstance(statement, sa.Select)
