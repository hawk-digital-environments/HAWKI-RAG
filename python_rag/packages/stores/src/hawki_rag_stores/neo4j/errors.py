"""Explicit Neo4j Driver 6 exception policy.

The store catches the two public operation-error roots exposed by the driver:
``Neo4jError`` for errors returned by the server and ``DriverError`` for errors
raised by the client.  Concrete subclasses are classified by family so logs and
service boundaries can make a deliberate fail-fast/degrade decision without
guessing from exception text.

Retry ownership belongs to ``Session.execute_read`` and
``Session.execute_write``.  Those managed-transaction methods consult the
driver's ``is_retryable()`` result and retry for at most
``max_transaction_retry_time``.  Application code must therefore keep every
transaction callback idempotent and must not wrap managed transactions in a
second retry loop.
"""

from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum

from neo4j.exceptions import (
    BrokenRecordError,
    ClientError,
    ConfigurationError,
    ConnectionPoolError,
    DatabaseError,
    DriverError,
    IncompleteCommit,
    Neo4jError,
    ResultError,
    ServiceUnavailable,
    SessionError,
    SessionExpired,
    TransactionError,
    TransientError,
)

Neo4jOperationError = Neo4jError | DriverError
NEO4J_OPERATION_ERRORS = (Neo4jError, DriverError)

# These failures can make optional graph reads return no graph enrichment.  All
# other errors (auth, Cypher, constraints, configuration, result misuse, etc.)
# propagate because retrying or silently degrading would hide a defect.
NEO4J_READ_DEGRADATION_ERRORS = (
    TransientError,
    SessionExpired,
    ServiceUnavailable,
    ConnectionPoolError,
)

DATABASE_NOT_FOUND_CODE = "Neo.ClientError.Database.DatabaseNotFound"


class Neo4jFailureFamily(StrEnum):
    """Stable operational families covering the public Driver 6 hierarchy."""

    SERVER_CLIENT = "server_client"
    SERVER_DATABASE = "server_database"
    SERVER_TRANSIENT = "server_transient"
    SERVER_OTHER = "server_other"
    DRIVER_CONFIGURATION = "driver_configuration"
    DRIVER_CONNECTION_POOL = "driver_connection_pool"
    DRIVER_SERVICE = "driver_service"
    DRIVER_INCOMPLETE_COMMIT = "driver_incomplete_commit"
    DRIVER_SESSION = "driver_session"
    DRIVER_TRANSACTION = "driver_transaction"
    DRIVER_RESULT = "driver_result"
    DRIVER_BROKEN_RECORD = "driver_broken_record"
    DRIVER_OTHER = "driver_other"


@dataclass(frozen=True, slots=True)
class Neo4jFailurePolicy:
    """Classification attached to a final server or driver failure."""

    family: Neo4jFailureFamily
    retryable: bool
    commit_outcome_unknown: bool = False


def classify_neo4j_error(exc: Neo4jOperationError) -> Neo4jFailurePolicy:
    """Classify every public server/driver exception through its base family.

    Concrete subclasses listed by Driver 6 are covered by these roots:
    client/auth/Cypher/constraint, database/transient, session/transaction,
    result/record, service/routing, configuration/certificate, and pool errors.
    ``IncompleteCommit`` is checked first because its write outcome is unknown.
    """

    if isinstance(exc, IncompleteCommit):
        family = Neo4jFailureFamily.DRIVER_INCOMPLETE_COMMIT
    elif isinstance(exc, ConfigurationError):
        family = Neo4jFailureFamily.DRIVER_CONFIGURATION
    elif isinstance(exc, ConnectionPoolError):
        family = Neo4jFailureFamily.DRIVER_CONNECTION_POOL
    elif isinstance(exc, ServiceUnavailable):
        family = Neo4jFailureFamily.DRIVER_SERVICE
    elif isinstance(exc, SessionExpired):
        family = Neo4jFailureFamily.DRIVER_SESSION
    elif isinstance(exc, TransactionError):
        family = Neo4jFailureFamily.DRIVER_TRANSACTION
    elif isinstance(exc, ResultError):
        family = Neo4jFailureFamily.DRIVER_RESULT
    elif isinstance(exc, BrokenRecordError):
        family = Neo4jFailureFamily.DRIVER_BROKEN_RECORD
    elif isinstance(exc, SessionError):
        family = Neo4jFailureFamily.DRIVER_SESSION
    elif isinstance(exc, ClientError):
        family = Neo4jFailureFamily.SERVER_CLIENT
    elif isinstance(exc, TransientError):
        family = Neo4jFailureFamily.SERVER_TRANSIENT
    elif isinstance(exc, DatabaseError):
        family = Neo4jFailureFamily.SERVER_DATABASE
    elif isinstance(exc, Neo4jError):
        family = Neo4jFailureFamily.SERVER_OTHER
    else:
        family = Neo4jFailureFamily.DRIVER_OTHER

    return Neo4jFailurePolicy(
        family=family,
        retryable=exc.is_retryable(),
        commit_outcome_unknown=isinstance(exc, IncompleteCommit),
    )


def is_database_not_found_error(exc: ClientError) -> bool:
    """Return whether the server rejected an explicitly selected database."""

    return exc.code == DATABASE_NOT_FOUND_CODE


def is_retryable_neo4j_error(exc: BaseException) -> bool:
    """Use the driver's authoritative retry flag for startup probes only."""

    return isinstance(exc, NEO4J_OPERATION_ERRORS) and exc.is_retryable()


__all__ = [
    "DATABASE_NOT_FOUND_CODE",
    "NEO4J_OPERATION_ERRORS",
    "NEO4J_READ_DEGRADATION_ERRORS",
    "Neo4jFailureFamily",
    "Neo4jFailurePolicy",
    "Neo4jOperationError",
    "classify_neo4j_error",
    "is_database_not_found_error",
    "is_retryable_neo4j_error",
]
