"""Neo4j connection and retry settings."""

from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Final


@dataclass(frozen=True)
class Neo4jSettings:
    uri: str
    user: str
    password: str
    database: str | None
    retry_attempts: int
    retry_attempts_by_operation: dict[str, int]
    log_latency: bool
    perf_log: bool


def load_neo4j_settings(database: str | None = None) -> Neo4jSettings:
    """Parse Neo4j client settings from environment with safe defaults."""
    uri = os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
    user = os.environ.get("NEO4J_USER", os.environ.get("NEO4J_USERNAME", "neo4j"))
    password = os.environ.get("NEO4J_PASSWORD", "password")
    configured_database = (
        database or os.environ.get("NEO4J_DATABASE") or ""
    ).strip() or None
    default_retry_attempts = _int_env("NEO4J_RETRY_ATTEMPTS", 3)
    op_default: Final = default_retry_attempts
    op_upsert = _int_env("NEO4J_RETRY_ATTEMPTS_UPSERT", op_default)
    op_delete = _int_env("NEO4J_RETRY_ATTEMPTS_DELETE", op_default)
    op_read = _int_env("NEO4J_RETRY_ATTEMPTS_READ", op_default)
    return Neo4jSettings(
        uri=uri,
        user=(user or "neo4j").strip(),
        password=(password or "").strip(),
        database=configured_database,
        retry_attempts=default_retry_attempts,
        retry_attempts_by_operation={
            "neo4j.upsert_triplets": op_upsert,
            "neo4j.delete_by_doc_id": op_delete,
            "neo4j.count_entities": op_read,
            "neo4j.count_triplets": op_read,
            "neo4j.count_relationships": op_read,
            "neo4j.count_labels": op_read,
            "neo4j.fetch_related": op_read,
            "neo4j.search_structural": op_read,
            "neo4j.visualization_cleanup": op_read,
        },
        log_latency=_bool_env("NEO4J_LOG_LATENCY"),
        perf_log=_bool_env("GRAPH_PERF_LOG"),
    )


def _bool_env(name: str, default: bool = False) -> bool:
    value = os.environ.get(name)
    if value is None:
        return default
    return str(value).strip().lower() in ("1", "true", "yes", "on")


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except Exception:
        return default
