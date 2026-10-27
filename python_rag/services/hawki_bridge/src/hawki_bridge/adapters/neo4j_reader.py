"""Read-only Neo4j adapter exposed to bridge application code."""

from __future__ import annotations

from collections.abc import Sequence
from dataclasses import dataclass
import logging
from typing import Any

from neo4j import GraphDatabase
from neo4j.exceptions import DriverError, Neo4jError

from hawki_graph_store.errors import (
    NEO4J_ERRORS,
    NEO4J_UNAVAILABLE_ERRORS,
)
from hawki_graph_store.graph import Neo4jGraph
from hawki_graph_store.settings import load_neo4j_settings

logger = logging.getLogger(__name__)


def fetch_related_graph(
    terms: list[str],
    *,
    dataset_id: str,
    neo4j_namespace: str,
    limit: int = 30,
    excluded_doc_ids: Sequence[str] = (),
) -> list[dict[str, str]]:
    """Fetch scoped relationship records and degrade availability failures.

    Dataset and namespace values reach Neo4j unchanged, database fallback stays
    disabled, and non-availability errors propagate. Facts contributed solely
    by pending-deletion documents are hidden by the graph-store predicate.
    """

    if not terms:
        return []
    graph: Neo4jGraph | None = None
    try:
        graph = Neo4jGraph(allow_database_fallback=False)
        return graph.fetch_related(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
            excluded_doc_ids=excluded_doc_ids,
        )
    except NEO4J_UNAVAILABLE_ERRORS as exc:
        logger.warning("graph:fetch_related unavailable error=%s", type(exc).__name__)
        return []
    finally:
        _close_graph(graph)


def build_structural_hits(
    terms: list[str],
    *,
    dataset_id: str,
    neo4j_namespace: str,
    limit: int,
    hops: int,
    include_rel_match: bool = False,
    excluded_doc_ids: Sequence[str] = (),
) -> list[dict[str, Any]]:
    """Project dataset-scoped Neo4j rows into bridge retrieval candidates.

    Rows attributed (via ``doc_id``/``doc_ids``) solely to pending-deletion
    documents are dropped. Unattributed legacy rows are retained.
    """

    if not terms:
        return []
    excluded = {
        str(doc_id).strip() for doc_id in excluded_doc_ids if str(doc_id).strip()
    }
    graph: Neo4jGraph | None = None
    try:
        graph = Neo4jGraph(allow_database_fallback=False)
        rows = graph.search_structural(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
            hops=hops,
            include_rel_match=include_rel_match,
        )
    except NEO4J_UNAVAILABLE_ERRORS as exc:
        logger.warning(
            "graph:structural_search unavailable error=%s", type(exc).__name__
        )
        rows = []
    finally:
        _close_graph(graph)

    hits: list[dict[str, Any]] = []
    for row in rows:
        doc_id = row.get("doc_id")
        if excluded and doc_id and doc_id in excluded:
            continue
        subject = row.get("subject") or ""
        relation = row.get("relation") or ""
        object_ = row.get("object") or ""
        hops_used = int(row.get("hops") or 1)
        hits.append(
            {
                "id": f"neo4j:{subject}:{relation}:{object_}:{doc_id or ''}",
                "score": 1.0 / max(1, hops_used),
                "payload": {
                    "component_type": "relation",
                    "subject": subject,
                    "relation": relation,
                    "object": object_,
                    "doc_id": doc_id,
                    "dataset_id": dataset_id,
                    "neo4j_namespace": neo4j_namespace,
                    "content": f"{subject} -{relation}-> {object_}".strip(" -"),
                    "title": "Graph relation",
                },
                "source": "neo4j",
            }
        )
    return hits


def _close_graph(graph: Neo4jGraph | None) -> None:
    if graph is None:
        return
    try:
        graph.close()
    except DriverError as exc:
        logger.warning("graph:close failed error=%s", type(exc).__name__)


@dataclass(frozen=True, slots=True)
class Neo4jReader:
    """Adapt dataset-scoped graph-store reads to the bridge graph port."""

    def fetch_related_graph(
        self,
        terms: list[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int,
        excluded_doc_ids: Sequence[str] = (),
    ) -> list[dict[str, str]]:
        """Forward trusted dataset and namespace scope to the Neo4j read."""
        return fetch_related_graph(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
            excluded_doc_ids=excluded_doc_ids,
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
        excluded_doc_ids: Sequence[str] = (),
    ) -> list[dict[str, Any]]:
        return build_structural_hits(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
            hops=hops,
            include_rel_match=include_rel_match,
            excluded_doc_ids=excluded_doc_ids,
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
