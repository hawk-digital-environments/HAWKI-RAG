"""Neo4j exception groups that change application behavior."""

from neo4j.exceptions import (
    ConnectionPoolError,
    DriverError,
    Neo4jError,
    ServiceUnavailable,
    SessionExpired,
    TransientError,
)

NEO4J_ERRORS = (Neo4jError, DriverError)

NEO4J_UNAVAILABLE_ERRORS = (
    TransientError,
    SessionExpired,
    ServiceUnavailable,
    ConnectionPoolError,
)

__all__ = ["NEO4J_ERRORS", "NEO4J_UNAVAILABLE_ERRORS"]
