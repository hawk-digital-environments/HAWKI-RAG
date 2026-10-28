"""The database-backed dataset grant reader.

The reader answers the authorization boundary's one question against the
Python-owned schema. It runs exactly one statement, and every ordinary
refusal is the same answer: the statement matched no row.

An infrastructure failure is not a refusal. When the database cannot be
reached the reader raises `AuthorizationUnavailableError`, so an outage is
never reported as a permission problem and never turns into an accidental
allow.
"""

import logging

import sqlalchemy as sa
from sqlalchemy import select
from sqlalchemy.engine import Engine
from sqlalchemy.exc import DBAPIError
from sqlalchemy.exc import TimeoutError as PoolTimeoutError

from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization.errors import AuthorizationUnavailableError
from hawki_backend.authorization.models import AuthorizationAction
from hawki_backend.persistence.schema import ACTIVE_STATUS, dataset_grants, datasets

_logger = logging.getLogger(__name__)

# The active status is a server-side invariant, not caller input, so it is
# written into the statement as a literal. A bound parameter would invite a
# future change to widen it.
_ACTIVE_STATUS = sa.literal_column(f"'{ACTIVE_STATUS}'")


class DatabaseGrantReader:
    """Answer grant questions from the Python-owned database schema."""

    def __init__(self, engine: Engine) -> None:
        """Bind the reader to a database engine.

        Args:
            engine: The engine that reaches the `hawki_backend` schema.
        """
        self._engine = engine

    def has_grant(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
        action: AuthorizationAction,
    ) -> bool:
        """Return whether the principal holds the grant for the action.

        The lookup matches the tenant, the principal kind, the principal id,
        the dataset, the action and the active state of the dataset in one
        statement. It never asks a second question about the dataset, so the
        answer cannot be assembled from two lookups and cannot leak whether
        the dataset exists.

        Returns:
            True only when the principal holds exactly this grant on an
            active dataset of the same tenant. An unknown dataset, an
            inactive dataset and a missing grant are all False.

        Raises:
            AuthorizationUnavailableError: When the database could not be
                reached, so no answer could be reached at all. The failure is
                passed through unchanged and is never turned into a refusal.
        """
        statement = _grant_statement(principal, dataset_id, action)
        try:
            with self._engine.connect() as connection:
                row = connection.execute(statement).fetchone()
        except (DBAPIError, PoolTimeoutError) as error:
            # A driver failure and an exhausted connection pool are the same
            # kind of event: the grant store could not answer. Neither is a
            # refusal, and neither may reach the caller as it is.
            _log_unavailable(error)
        else:
            return row is not None
        # The failure is raised outside the handler, so the database
        # exception is neither chained nor reachable as a context. Its
        # message can name the host, the port, the database and the
        # account, and none of that leaves the process. The handler also
        # drops its reference to the exception on the way out, so nothing
        # survives to reach the error that is raised here.
        raise AuthorizationUnavailableError()


def _grant_statement(
    principal: AuthenticatedPrincipal,
    dataset_id: str,
    action: AuthorizationAction,
) -> sa.Select:
    """Return the single statement the reader runs."""
    return (
        select(sa.literal_column("1"))
        .select_from(
            dataset_grants.join(
                datasets,
                (datasets.c.tenant_id == dataset_grants.c.tenant_id)
                & (datasets.c.dataset_id == dataset_grants.c.dataset_id),
            )
        )
        .where(
            dataset_grants.c.tenant_id
            == sa.bindparam("tenant_id", value=principal.tenant_id),
            dataset_grants.c.principal_type
            == sa.bindparam("principal_type", value=principal.kind.value),
            dataset_grants.c.principal_id
            == sa.bindparam("principal_id", value=principal.principal_id),
            dataset_grants.c.dataset_id == sa.bindparam("dataset_id", value=dataset_id),
            dataset_grants.c.permission
            == sa.bindparam("permission", value=action.value),
            datasets.c.status == _ACTIVE_STATUS,
        )
        .limit(sa.literal_column("1"))
    )


def _log_unavailable(error: DBAPIError) -> None:
    """Record that the grant store could not be reached, without its detail.

    The driver's message can name the host, the port, the database and the
    account, so it is never logged. The error type and the SQLSTATE are
    enough to tell a refused connection from a broken statement.
    """
    original = getattr(error, "orig", None)
    _logger.error(
        "the grant store could not be reached",
        extra={
            "error_type": type(original).__name__ if original else type(error).__name__,
            "sqlstate": getattr(original, "sqlstate", None),
        },
    )


__all__ = ["DatabaseGrantReader"]
