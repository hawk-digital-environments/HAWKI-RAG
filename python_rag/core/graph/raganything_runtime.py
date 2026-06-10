"""Runtime helpers for RAG-Anything graph extraction."""

from __future__ import annotations

import logging
import os
import re
from typing import Any

from core.graph.raganything_settings import RagAnythingGraphSettings

logger = logging.getLogger(__name__)


def prepare_lightrag_neo4j_env(
    settings: RagAnythingGraphSettings,
    *,
    neo4j_database: str | None = None,
) -> tuple[bool, dict[str, str]]:
    """Prepare environment variables needed for LightRAG Neo4j usage."""
    applied: dict[str, str] = {}
    neo4j_user = settings.neo4j_user
    neo4j_pwd = settings.neo4j_password
    neo4j_uri = settings.neo4j_uri

    if not neo4j_uri:
        if settings.neo4j_bolt_url:
            neo4j_uri = settings.neo4j_bolt_url
        elif settings.neo4j_http_url:
            neo4j_uri = re.sub(r"^https?://", "bolt://", settings.neo4j_http_url)
            neo4j_uri = re.sub(r":7474(?=/|$)", ":7687", neo4j_uri)
            neo4j_uri = re.sub(r":7473(?=/|$)", ":7687", neo4j_uri)
        if neo4j_uri:
            os.environ["NEO4J_URI"] = neo4j_uri
            applied["NEO4J_URI"] = neo4j_uri

    if neo4j_user and not os.environ.get("NEO4J_USERNAME", "").strip():
        os.environ["NEO4J_USERNAME"] = neo4j_user
        applied["NEO4J_USERNAME"] = neo4j_user

    database = (neo4j_database or settings.neo4j_database).strip()
    if database:
        os.environ["NEO4J_DATABASE"] = database
        applied["NEO4J_DATABASE"] = database

    if neo4j_pwd:
        applied["NEO4J_PASSWORD"] = "***"

    ready = bool(
        os.environ.get("NEO4J_URI", "").strip()
        and os.environ.get("NEO4J_USERNAME", "").strip()
        and os.environ.get("NEO4J_PASSWORD", "").strip()
    )
    return ready, applied


def clear_lightrag_temp_graph(
    neo4j_database: str | None = None,
    *,
    neo4j_uri: str | None = None,
    neo4j_user: str | None = None,
    neo4j_password: str | None = None,
) -> None:
    """Delete temporary LightRAG + Neo4j graph state from a local DB."""
    try:
        from neo4j import GraphDatabase  # type: ignore
    except Exception as exc:
        logger.debug("LightRAG Neo4j temp graph cleanup skipped; driver unavailable: %s", exc)
        return

    uri = (neo4j_uri or "").strip() or os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
    user = (
        (neo4j_user or "").strip()
        or os.environ.get("NEO4J_USER")
        or os.environ.get("NEO4J_USERNAME")
        or "neo4j"
    )
    password = (neo4j_password or "").strip() or os.environ.get("NEO4J_PASSWORD", "password")
    database = (neo4j_database or os.environ.get("NEO4J_DATABASE") or "").strip() or None

    driver = GraphDatabase.driver(uri, auth=(user, password))
    try:
        session_kwargs: dict[str, Any] = {"database": database} if database else {}
        with driver.session(**session_kwargs) as session:
            session.execute_write(lambda tx: tx.run("MATCH (n:base) DETACH DELETE n").consume())
    except Exception as exc:
        logger.debug("LightRAG Neo4j temp graph cleanup failed: %s", exc)
    finally:
        driver.close()

