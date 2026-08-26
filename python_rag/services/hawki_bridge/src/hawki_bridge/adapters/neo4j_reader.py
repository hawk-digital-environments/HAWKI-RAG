"""Read-only Neo4j adapter exposed to bridge application code."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from neo4j import GraphDatabase
from neo4j.exceptions import DriverError, Neo4jError

from hawki_graph_store.errors import (
    NEO4J_ERRORS,
    NEO4J_UNAVAILABLE_ERRORS,
)
from hawki_graph_store.settings import load_neo4j_settings
from hawki_graph_store.traversal import (
    build_structural_hits,
    fetch_related_terms,
)


@dataclass(frozen=True, slots=True)
class Neo4jReader:
    """Adapt dataset-scoped graph-store reads to the bridge graph port."""

    def fetch_related_terms(
        self,
        terms: list[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int,
    ) -> list[dict[str, str]]:
        return fetch_related_terms(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
        )

    def build_structural_hits(
        self,
        terms: list[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int,
        hops: int,
        include_rel_match: bool,
    ) -> list[dict[str, Any]]:
        return build_structural_hits(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
            hops=hops,
            include_rel_match=include_rel_match,
        )


def ping_neo4j() -> None:
    """Fail when a read session cannot be opened for configured Neo4j."""

    settings = load_neo4j_settings()
    driver = GraphDatabase.driver(
        settings.uri,
        auth=(settings.user, settings.password),
        max_transaction_retry_time=settings.max_transaction_retry_time,
    )
    try:
        with driver.session(database=settings.database) as session:
            session.run("RETURN 1").consume()
    except NEO4J_ERRORS:
        try:
            driver.close()
        except DriverError:
            pass
        raise
    else:
        driver.close()


__all__ = [
    "NEO4J_ERRORS",
    "NEO4J_UNAVAILABLE_ERRORS",
    "DriverError",
    "Neo4jReader",
    "Neo4jError",
    "ping_neo4j",
]
