"""Runtime helpers for RAG-Anything graph extraction."""

from __future__ import annotations

from collections.abc import Mapping, MutableMapping
import logging
import os
import re
from typing import Any

from neo4j import GraphDatabase
from neo4j.exceptions import DriverError, Neo4jError

from hawki_indexer_worker.adapters.raganything.settings import RagAnythingGraphSettings

logger = logging.getLogger(__name__)


def build_lightrag_neo4j_env(
    settings: RagAnythingGraphSettings,
    *,
    neo4j_database: str | None = None,
    source_env: Mapping[str, str] | None = None,
) -> dict[str, str]:
    """Build required LightRAG Neo4j env values without mutating process state."""
    env: dict[str, str] = {}
    current_env = source_env if source_env is not None else os.environ

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

    if neo4j_uri and not str(current_env.get("NEO4J_URI", "")).strip():
        env["NEO4J_URI"] = neo4j_uri

    if neo4j_user and not str(current_env.get("NEO4J_USERNAME", "")).strip():
        env["NEO4J_USERNAME"] = neo4j_user

    database = (neo4j_database or settings.neo4j_database).strip()
    if database:
        env["NEO4J_DATABASE"] = database

    if neo4j_pwd and not str(current_env.get("NEO4J_PASSWORD", "")).strip():
        env["NEO4J_PASSWORD"] = neo4j_pwd

    return env


def apply_lightrag_neo4j_env(
    overrides: Mapping[str, str],
    *,
    target_env: MutableMapping[str, str] | None = None,
) -> dict[str, str]:
    """Apply LightRAG Neo4j env overrides and return the concrete values."""
    env = target_env if target_env is not None else os.environ
    applied = {}
    for key, value in overrides.items():
        if not value:
            continue
        env[key] = value
        applied[key] = value
    return applied


def _mask_sensitive_runtime_env(overrides: Mapping[str, str]) -> dict[str, str]:
    return {k: ("***" if k == "NEO4J_PASSWORD" else v) for k, v in overrides.items()}


def prepare_lightrag_neo4j_env(
    settings: RagAnythingGraphSettings,
    *,
    neo4j_database: str | None = None,
) -> tuple[bool, dict[str, str]]:
    """Prepare environment variables needed for LightRAG Neo4j usage."""
    overrides = build_lightrag_neo4j_env(settings, neo4j_database=neo4j_database)
    applied_runtime = apply_lightrag_neo4j_env(overrides)
    applied = _mask_sensitive_runtime_env(applied_runtime)
    if settings.neo4j_password:
        applied.setdefault("NEO4J_PASSWORD", "***")
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
    """Delete temporary LightRAG graph data with a managed, idempotent write.

    ``execute_write`` owns retries and may replay the callback.  ``DETACH
    DELETE`` is safe to replay.  A final server or driver failure is logged and
    ignored because this cleanup is best-effort; programming errors still
    propagate.
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

    driver = GraphDatabase.driver(uri, auth=(user, password))
    try:
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
        try:
            driver.close()
        except DriverError as exc:
            logger.warning(
                "LightRAG Neo4j driver close failed error=%s", type(exc).__name__
            )
