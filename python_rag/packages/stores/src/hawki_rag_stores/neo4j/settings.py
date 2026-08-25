"""Neo4j connection and driver-managed transaction settings."""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Neo4jSettings:
    uri: str
    user: str
    password: str
    database: str | None
    max_transaction_retry_time: float
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
    return Neo4jSettings(
        uri=uri,
        user=(user or "neo4j").strip(),
        password=(password or "").strip(),
        database=configured_database,
        max_transaction_retry_time=max(
            0.0, _float_env("NEO4J_MAX_TRANSACTION_RETRY_TIME", 30.0)
        ),
        log_latency=_bool_env("NEO4J_LOG_LATENCY"),
        perf_log=_bool_env("GRAPH_PERF_LOG"),
    )


def _bool_env(name: str, default: bool = False) -> bool:
    value = os.environ.get(name)
    if value is None:
        return default
    return str(value).strip().lower() in ("1", "true", "yes", "on")


def _float_env(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, default))
    except (TypeError, ValueError):
        return default
