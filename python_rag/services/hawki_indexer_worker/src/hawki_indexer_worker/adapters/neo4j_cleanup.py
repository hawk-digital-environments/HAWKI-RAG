"""Neo4j-specific cleanup adapter for temporary LightRAG graph data."""

from __future__ import annotations

import logging
import os
from typing import Any

from neo4j import GraphDatabase
from neo4j.exceptions import DriverError, Neo4jError

logger = logging.getLogger(__name__)


def clear_lightrag_temp_graph(
    neo4j_database: str | None = None,
    *,
    neo4j_uri: str | None = None,
    neo4j_user: str | None = None,
    neo4j_password: str | None = None,
) -> None:
    """Best-effort deletion of temporary LightRAG nodes.

    The driver's managed write owns retry. The idempotent delete callback may
    therefore be replayed safely, while final Neo4j operation failures are
    logged by this adapter and programming failures continue to propagate.
    """

    uri = (neo4j_uri or "").strip() or os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
    user = (
        (neo4j_user or "").strip()
        or os.environ.get("NEO4J_USER")
        or os.environ.get("NEO4J_USERNAME")
        or "neo4j"
    )
    password = (neo4j_password or "").strip() or os.environ.get(
        "NEO4J_PASSWORD", "password"
    )
    database = (
        neo4j_database or os.environ.get("NEO4J_DATABASE") or ""
    ).strip() or None

    driver = None
    try:
        driver = GraphDatabase.driver(uri, auth=(user, password))
        session_kwargs: dict[str, Any] = {"database": database} if database else {}
        with driver.session(**session_kwargs) as session:
            session.execute_write(
                lambda tx: tx.run("MATCH (n:base) DETACH DELETE n").consume()
            )
    except (Neo4jError, DriverError) as exc:
        logger.warning(
            "LightRAG Neo4j temp graph cleanup failed error=%s",
            type(exc).__name__,
        )
    finally:
        if driver is not None:
            try:
                driver.close()
            except DriverError as exc:
                logger.warning(
                    "LightRAG Neo4j driver close failed error=%s",
                    type(exc).__name__,
                )


__all__ = ["clear_lightrag_temp_graph"]
