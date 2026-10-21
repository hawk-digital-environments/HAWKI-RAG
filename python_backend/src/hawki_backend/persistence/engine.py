"""Construction of the database engine the backend uses."""

from sqlalchemy import create_engine
from sqlalchemy.engine import Engine

from hawki_backend.settings import DatabaseSettings


def build_engine(settings: DatabaseSettings) -> Engine:
    """Build an engine that reaches the Python-owned schema.

    The pool is small and the timeouts are short. A grant lookup sits on
    the request path, so an unavailable database has to fail fast and be
    reported as unavailable rather than hold a request open.

    Args:
        settings: The validated database configuration.

    Returns:
        The engine. Its caller owns it and disposes it.
    """
    return create_engine(
        settings.dsn,
        pool_size=settings.pool_size,
        max_overflow=0,
        pool_timeout=settings.pool_timeout_seconds,
        pool_recycle=settings.pool_recycle_seconds,
        pool_pre_ping=True,
        connect_args={
            "connect_timeout": settings.connect_timeout_seconds,
            "options": f"-c statement_timeout={settings.statement_timeout_ms}",
        },
    )


__all__ = ["build_engine"]
