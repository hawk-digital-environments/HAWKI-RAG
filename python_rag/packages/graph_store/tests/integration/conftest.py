"""Discovery and safety fixtures for live Neo4j tests."""

from __future__ import annotations

from dataclasses import dataclass
import os
from typing import Any, NoReturn

from neo4j import GraphDatabase
from neo4j.exceptions import DriverError, Neo4jError
import pytest

from hawki_graph_store.settings import Neo4jSettings


_TRUTHY = frozenset({"1", "true", "yes", "on"})


def _required() -> bool:
    return os.environ.get("RAWKI_INTEGRATION_REQUIRED", "").strip().lower() in _TRUTHY


def unavailable(reason: str) -> NoReturn:
    """Skip unavailable Neo4j, or fail in required mode."""

    message = f"Live integration dependency unavailable: {reason}"
    if _required():
        pytest.fail(
            f"{message}. RAWKI_INTEGRATION_REQUIRED=1 requires every selected "
            "integration dependency to be configured and reachable."
        )
    pytest.skip(message)


def _probe_timeout() -> float:
    raw_value = os.environ.get("RAWKI_INTEGRATION_PROBE_TIMEOUT", "1.5")
    try:
        return max(0.25, float(raw_value))
    except ValueError:
        return 1.5


def _unique(values: list[str]) -> list[str]:
    seen: set[str] = set()
    unique_values: list[str] = []
    for value in values:
        normalized = str(value or "").strip().rstrip("/")
        if normalized and normalized not in seen:
            seen.add(normalized)
            unique_values.append(normalized)
    return unique_values


@dataclass(frozen=True)
class LiveNeo4j:
    """Verified Neo4j settings plus a driver reserved for cleanup."""

    settings: Neo4jSettings
    driver: Any


@pytest.fixture(scope="session")
def live_neo4j() -> LiveNeo4j:
    """Connect to a real Neo4j database using configured credentials."""

    user = os.environ.get(
        "NEO4J_USER", os.environ.get("NEO4J_USERNAME", "neo4j")
    ).strip()
    password = os.environ.get("NEO4J_PASSWORD", "password").strip()
    database = os.environ.get("NEO4J_DATABASE", "").strip() or None
    candidates = _unique(
        [
            os.environ.get("RAWKI_INTEGRATION_NEO4J_URI", ""),
            os.environ.get("NEO4J_URI", ""),
            os.environ.get("NEO4J_BOLT_URL", ""),
            "bolt://127.0.0.1:7687",
            "bolt://hawki_rag_neo4j:7687",
            "bolt://neo4j:7687",
        ]
    )
    failures: list[str] = []
    for uri in candidates:
        driver = None
        try:
            driver = GraphDatabase.driver(
                uri,
                auth=(user or "neo4j", password),
                connection_timeout=_probe_timeout(),
            )
            driver.verify_connectivity()
            with driver.session(database=database) as session:
                session.run("RETURN 1 AS ready").consume()
        except (Neo4jError, DriverError) as exc:
            failures.append(f"{uri} ({type(exc).__name__})")
            if driver is not None:
                driver.close()
            continue

        settings = Neo4jSettings(
            uri=uri,
            user=user or "neo4j",
            password=password,
            database=database,
            max_transaction_retry_time=1.0,
            log_latency=False,
            perf_log=False,
        )
        yield LiveNeo4j(settings=settings, driver=driver)
        driver.close()
        return

    unavailable(
        "Neo4j was not reachable with the configured credentials; set "
        "RAWKI_INTEGRATION_NEO4J_URI, NEO4J_USER, and NEO4J_PASSWORD or run "
        "inside the Compose network "
        f"(probes: {', '.join(failures) or 'none'})"
    )
