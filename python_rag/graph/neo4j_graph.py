"""Typed Neo4j adapter wrapper with centralized settings."""
from __future__ import annotations

import logging
import time
from typing import Any, Callable, Dict, Iterable, List, Optional, Tuple

from neo4j import GraphDatabase, exceptions as neo4j_exceptions

from graph.neo4j_transport import Neo4jQueryExecutor, Neo4jQueryExecutorProtocol

from graph.neo4j_requests import (
    Neo4jQueryRequest,
    clean_query_terms,
    build_cleanup_isolated_nodes_query,
    build_cleanup_orphaned_relationships_query,
    build_count_query,
    build_delete_doc_edges_query,
    build_fetch_related_query,
    build_row_grouped_query,
    build_search_structural_query,
    build_triplet_rows,
    build_upsert_triplets_query,
)
from graph.neo4j_settings import Neo4jSettings, load_neo4j_settings
from graph.neo4j_responses import (
    parse_count,
    parse_fact_rows,
    parse_label_counts,
    parse_relation_counts,
    parse_delete_count,
    parse_structural_rows,
)

logger = logging.getLogger(__name__)


def _perf_log(enabled: bool, msg: str, *args: object) -> None:
    if enabled:
        logger.info(msg, *args)


class Neo4jGraph:
    """Neo4j utility used for inserting and querying LightRAG triplets."""

    def __init__(
        self,
        *,
        database: Optional[str] = None,
        settings: Optional[Neo4jSettings] = None,
        query_executor: Neo4jQueryExecutorProtocol | None = None,
    ) -> None:
        self._settings = settings or load_neo4j_settings(database=database)
        self._database = self._settings.database
        self._query_executor: Neo4jQueryExecutorProtocol
        if query_executor is None:
            self._driver = GraphDatabase.driver(
                self._settings.uri,
                auth=(self._settings.user, self._settings.password),
            )
            if self._database:
                try:
                    with self._driver.session(database=self._database) as session:
                        session.run("RETURN 1").consume()
                except neo4j_exceptions.Neo4jError as exc:
                    logger.warning(
                        "neo4j:requested database '%s' is unavailable (%s); falling back to default database",
                        self._database,
                        exc,
                    )
                    self._database = None
            self._query_executor = Neo4jQueryExecutor(
                self._session,
                retry_attempts=getattr(self._settings, "retry_attempts", 3),
                log_latency=getattr(self._settings, "log_latency", False),
            )
        else:
            self._driver = None
            self._query_executor = query_executor

    def _session(self):
        if self._database:
            return self._driver.session(database=self._database)
        return self._driver.session()

    def close(self) -> None:
        """Close the underlying driver."""
        if getattr(self, "_driver", None) is not None:
            self._driver.close()

    def _run_read(self, query: Neo4jQueryRequest, *, callback: Callable[[Any], Any]) -> Any:
        if getattr(self, "_query_executor", None) is None:
            settings = getattr(self, "_settings", None)
            self._query_executor = Neo4jQueryExecutor(
                self._session,
                retry_attempts=max(1, int(getattr(settings, "retry_attempts", 1))),
                log_latency=bool(getattr(settings, "log_latency", False)),
            )
        return self._query_executor.run_read(query, callback=callback)

    def _run_write(self, query: Neo4jQueryRequest, *, callback: Callable[[Any], Any]) -> Any:
        if getattr(self, "_query_executor", None) is None:
            settings = getattr(self, "_settings", None)
            self._query_executor = Neo4jQueryExecutor(
                self._session,
                retry_attempts=max(1, int(getattr(settings, "retry_attempts", 1))),
                log_latency=bool(getattr(settings, "log_latency", False)),
            )
        return self._query_executor.run_write(query, callback=callback)

    def count_entities(self) -> int:
        """Return the count of graph entity-like nodes across supported schemas."""
        query = Neo4jQueryRequest(build_count_query("entities"), {})
        result = self._run_read(
            query,
            callback=lambda tx: tx.run(query.statement).single(),
        )
        return parse_count(result)

    def count_triplets(self) -> int:
        """Return the count of graph relationships across supported schemas."""
        query = Neo4jQueryRequest(build_count_query("triplets"), {})
        result = self._run_read(
            query,
            callback=lambda tx: tx.run(query.statement).single(),
        )
        return parse_count(result)

    def count_relationships_by_type(self) -> List[Dict[str, int]]:
        """Return relationship counts grouped by relationship type."""
        query = Neo4jQueryRequest(build_row_grouped_query("relations"), {})
        results = self._run_read(
            query,
            callback=lambda tx: list(tx.run(query.statement)),
        )
        return parse_relation_counts(results)

    def count_nodes_by_label(self) -> List[Dict[str, Any]]:
        """Return counts of nodes grouped by their label combinations."""
        query = Neo4jQueryRequest(build_row_grouped_query("labels"), {})
        results = self._run_read(
            query,
            callback=lambda tx: list(tx.run(query.statement)),
        )
        return parse_label_counts(results)

    def upsert_triplets(self, triplets: Iterable[Tuple[str, str, str]], *, doc_id: Optional[str] = None) -> None:
        """Insert or update triplets by merging nodes and relationships."""
        settings = getattr(self, "_settings", None)
        fn_start = time.perf_counter()
        _perf_log(
            getattr(settings, "perf_log", False),
            "perf:graph graph.neo4j_graph.upsert_triplets start doc_id=%s",
            doc_id if doc_id is not None else "__legacy__",
        )
        doc_key = str(doc_id) if doc_id is not None else "__legacy__"
        rows = build_triplet_rows(triplets, doc_key)
        rows_ms = (time.perf_counter() - fn_start) * 1000
        if not rows:
            logger.debug("neo4j:upsert_triplets empty doc_id=%s", doc_key)
            _perf_log(
                getattr(settings, "perf_log", False),
                "perf:graph graph.neo4j_graph.upsert_triplets done doc_id=%s rows=0 build_rows_ms=%.2f execute_ms=0.00 total_ms=%.2f",
                doc_key,
                rows_ms,
                (time.perf_counter() - fn_start) * 1000,
            )
            return

        query = Neo4jQueryRequest(
            build_upsert_triplets_query(),
            {"rows": rows},
        )
        exec_start = time.perf_counter()
        self._run_write(
            query,
            callback=lambda tx: tx.run(query.statement, **query.params),
        )
        exec_ms = (time.perf_counter() - exec_start) * 1000

        logger.info("neo4j:upsert_triplets count=%s doc_id=%s", len(rows), doc_key)
        _perf_log(
            getattr(settings, "perf_log", False),
            "perf:graph graph.neo4j_graph.upsert_triplets done doc_id=%s rows=%s build_rows_ms=%.2f execute_ms=%.2f total_ms=%.2f",
            doc_key,
            len(rows),
            rows_ms,
            exec_ms,
            (time.perf_counter() - fn_start) * 1000,
        )

    def delete_by_doc_id(self, doc_id: str) -> Dict[str, int]:
        """Remove relationships (and orphaned nodes) belonging to a document."""
        doc_key = str(doc_id)

        remove_edges_query = Neo4jQueryRequest(build_delete_doc_edges_query(), {"doc_id": doc_key})
        relationships_touched = self._run_write(
            remove_edges_query,
            callback=lambda tx: parse_delete_count(
                tx.run(remove_edges_query.statement, **remove_edges_query.params).single()
            ),
        )

        orphaned_query = Neo4jQueryRequest(build_cleanup_orphaned_relationships_query(), {})
        relationships_deleted = self._run_write(
            orphaned_query,
            callback=lambda tx: int(
                tx.run(orphaned_query.statement).consume().counters.relationships_deleted
            ),
        )

        nodes_deleted = 0
        if relationships_touched or relationships_deleted:
            cleanup_query = Neo4jQueryRequest(
                build_cleanup_isolated_nodes_query(),
                {"doc_id": doc_key},
            )
            nodes_deleted = self._run_write(
                cleanup_query,
                callback=lambda tx: int(
                    tx.run(cleanup_query.statement, **cleanup_query.params)
                    .consume()
                    .counters.nodes_deleted
                ),
            )

        logger.info(
            "neo4j:delete_by_doc_id relationships=%s entities=%s doc_id=%s",
            relationships_deleted,
            nodes_deleted,
            doc_id,
        )
        return {"relationships_deleted": relationships_deleted, "entities_deleted": nodes_deleted}

    def fetch_related(self, terms: Iterable[str], limit: int = 25) -> List[Dict[str, str]]:
        """Pull related entities/relations that match any of the supplied terms."""
        cleaned = clean_query_terms(terms)
        if not cleaned:
            return []

        query = Neo4jQueryRequest(
            build_fetch_related_query(),
            {"terms": cleaned, "limit": int(limit)},
        )
        try:
            result = self._run_read(
                query,
                callback=lambda tx: list(tx.run(query.statement, **query.params)),
            )
        except neo4j_exceptions.Neo4jError:
            return []

        facts: List[Dict[str, str]] = []
        return parse_fact_rows(result)

    def search_structural(
        self,
        terms: Iterable[str],
        *,
        limit: int = 40,
        hops: int = 2,
        include_rel_match: bool = False,
    ) -> List[Dict[str, Any]]:
        """Return structural graph candidates based on matched entities and hop expansion."""
        cleaned = clean_query_terms(terms)
        if not cleaned:
            return []

        safe_hops = max(1, int(hops))
        query = Neo4jQueryRequest(
            build_search_structural_query(safe_hops, include_rel_match=include_rel_match),
            {"terms": cleaned, "limit": int(limit), "hops": safe_hops},
        )
        try:
            result = self._run_read(
                query,
                callback=lambda tx: list(tx.run(query.statement, **query.params)),
            )
        except neo4j_exceptions.Neo4jError:
            return []
        return parse_structural_rows(result)
