"""Typed Neo4j adapter wrapper with centralized settings."""

from __future__ import annotations

import logging
import time
from typing import Any, Callable, Iterable

from neo4j import GraphDatabase
from neo4j.exceptions import ClientError, DriverError, Neo4jError

from hawki_graph_store.client import ensure_query_executor
from hawki_graph_store.transport import (
    Neo4jQueryExecutor,
    Neo4jQueryExecutorProtocol,
)
from hawki_graph_store.requests import (
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
    normalize_graph_write_scope,
)
from hawki_graph_store.settings import Neo4jSettings, load_neo4j_settings
from hawki_graph_store.requests import (
    ops_scope,
    ops_node_projection,
    ops_edge_projection,
)
from hawki_graph_store.responses import ops_node, ops_edge, scoped_entity_key
from hawki_graph_store.responses import (
    parse_count,
    parse_fact_rows,
    parse_label_counts,
    parse_relation_counts,
    parse_delete_count,
    parse_structural_rows,
)

logger = logging.getLogger(__name__)

_DATABASE_NOT_FOUND = "Neo.ClientError.Database.DatabaseNotFound"


def _close_driver_after_initialization_failure(driver: Any) -> None:
    """Close a partially initialized driver without masking its first error."""
    try:
        driver.close()
    except DriverError:
        pass


def _perf_log(enabled: bool, msg: str, *args: object) -> None:
    if enabled:
        logger.info(msg, *args)


def _required_read_scope(dataset_id: str, neo4j_namespace: str) -> tuple[str, str]:
    normalized_dataset_id = str(dataset_id or "").strip()
    normalized_namespace = str(neo4j_namespace or "").strip()
    if not normalized_dataset_id or not normalized_namespace:
        raise ValueError(
            "Dataset-scoped graph retrieval requires dataset_id and neo4j_namespace."
        )
    return normalized_dataset_id, normalized_namespace


class Neo4jGraph:
    """Neo4j utility used for inserting and querying LightRAG triplets."""

    def __init__(
        self,
        *,
        database: str | None = None,
        dataset_id: str | None = None,
        neo4j_namespace: str | None = None,
        allow_database_fallback: bool = True,
        settings: Neo4jSettings | None = None,
        query_executor: Neo4jQueryExecutorProtocol | None = None,
    ) -> None:
        self._dataset_id = str(dataset_id or "").strip() or None
        self._neo4j_namespace = str(neo4j_namespace or "").strip() or None
        self._settings = settings or load_neo4j_settings(database=database)
        self._database = self._settings.database
        self._query_executor: Neo4jQueryExecutorProtocol
        if query_executor is None:
            self._driver = GraphDatabase.driver(
                self._settings.uri,
                auth=(self._settings.user, self._settings.password),
                max_transaction_retry_time=self._settings.max_transaction_retry_time,
            )
            if self._database:
                try:
                    with self._driver.session(database=self._database) as session:
                        session.run("RETURN 1").consume()
                except ClientError as exc:
                    database_missing = exc.code == _DATABASE_NOT_FOUND
                    if not allow_database_fallback or not database_missing:
                        _close_driver_after_initialization_failure(self._driver)
                        raise
                    logger.warning(
                        "neo4j:requested database '%s' does not exist; falling back to the default database",
                        self._database,
                    )
                    self._database = None
                except (Neo4jError, DriverError):
                    _close_driver_after_initialization_failure(self._driver)
                    raise
            self._query_executor = Neo4jQueryExecutor(
                self._session,
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

    def _ops_params(self, **extra: Any) -> dict[str, Any]:
        dataset, namespace = _required_read_scope(
            self._dataset_id, self._neo4j_namespace
        )
        return {"dataset_id": dataset, "neo4j_namespace": namespace, **extra}

    def _ops_read(self, statement: str, **extra: Any) -> list[dict]:
        params = self._ops_params(**extra)
        query = Neo4jQueryRequest(statement, params, operation="neo4j.scoped_ops")
        return self._run_read(
            query,
            callback=lambda tx: [
                dict(row) for row in tx.run(statement, parameters=params)
            ],
        )

    def graph_overview(self, limit: int = 80) -> dict:
        """Bound the scene by nodes, then read only edges between those nodes."""
        rows = self._ops_read(
            f"MATCH (n:Entity) WHERE {ops_scope('n')} AND n.entity_key IS NOT NULL "
            f"RETURN {ops_node_projection()} AS node ORDER BY node.key LIMIT $limit",
            limit=max(1, min(300, int(limit))),
        )
        nodes = [row["node"] for row in rows]
        return self._ops_scene(nodes, self._ops_edges([node["key"] for node in nodes]))

    def _ops_edges(self, keys: list[str], limit: int = 1000) -> list[dict]:
        return [
            row["edge"]
            for row in self._ops_read(
                f"MATCH (s:Entity)-[r]->(o:Entity) WHERE {ops_scope('s')} AND {ops_scope('r')} "
                f"AND {ops_scope('o')} AND s.entity_key IN $keys AND o.entity_key IN $keys "
                f"RETURN {ops_edge_projection()} AS edge ORDER BY s.entity_key, o.entity_key, type(r) LIMIT $limit",
                keys=keys,
                limit=limit,
            )
        ]

    def graph_search(
        self, query: str, limit: int = 12, *, names: list[str] | None = None
    ) -> list[dict]:
        """Case-insensitive, scoped lookup; no index discovery or global fallback."""
        predicate = (
            "toLower(coalesce(n.name, n.entity_id, '')) IN $names"
            if names is not None
            else "toLower(coalesce(n.name, n.entity_id, '')) CONTAINS toLower($query)"
        )
        rows = self._ops_read(
            f"MATCH (n:Entity) WHERE {ops_scope('n')} AND n.entity_key IS NOT NULL AND {predicate} "
            f"RETURN {ops_node_projection()} AS node, "
            "CASE WHEN toLower(n.name) = toLower($query) THEN 10.0 ELSE 1.0 END AS score "
            "ORDER BY score DESC, n.entity_key LIMIT $limit",
            query=query[:4000],
            names=[name.lower() for name in (names or [])][:100],
            limit=max(1, min(50, int(limit))),
        )
        return [
            ops_node(
                dict(row["node"], score=row["score"]),
                self._dataset_id,
                self._neo4j_namespace,
            )
            for row in rows
        ]

    def graph_node(self, node_id: str, limit: int = 80) -> dict:
        return self.graph_expand(node_id, depth=1, limit=limit)

    def graph_expand(self, node_id: str, depth: int = 1, limit: int = 80) -> dict:
        dataset, namespace = _required_read_scope(
            self._dataset_id, self._neo4j_namespace
        )
        key = scoped_entity_key(node_id, dataset, namespace)
        if key is None:
            return self._ops_scene([], []) | {
                "warnings": ["Node is unavailable in this dataset."]
            }
        depth = max(1, min(3, int(depth)))
        limit = max(1, min(250, int(limit)))
        center = self._ops_read(
            f"MATCH (n:Entity) WHERE {ops_scope('n')} AND n.entity_key = $key "
            f"RETURN {ops_node_projection()} AS node LIMIT 1",
            key=key,
        )
        if not center:
            return self._ops_scene([], []) | {
                "warnings": ["Node is unavailable in this dataset."]
            }
        rows = self._ops_read(
            f"MATCH p=(center:Entity)-[*1..{depth}]-(n:Entity) WHERE {ops_scope('center')} "
            "AND center.entity_key = $key "
            "AND all(n IN nodes(p) WHERE n.dataset_id = $dataset_id AND n.neo4j_namespace = $neo4j_namespace AND n.entity_key IS NOT NULL) "
            "AND all(r IN relationships(p) WHERE r.dataset_id = $dataset_id AND r.neo4j_namespace = $neo4j_namespace) "
            f"RETURN DISTINCT {ops_node_projection()} AS node ORDER BY node.key LIMIT $limit",
            key=key,
            limit=max(0, limit - 1),
        )
        nodes = {row["node"]["key"]: row["node"] for row in center + rows}
        return self._ops_scene(list(nodes.values()), self._ops_edges(list(nodes))) | {
            "expanded_node_id": node_id,
            "entry_node_id": node_id,
            "depth": depth,
        }

    def _ops_scene(self, nodes: list[dict], edges: list[dict]) -> dict:
        dataset, namespace = _required_read_scope(
            self._dataset_id, self._neo4j_namespace
        )
        normalized_nodes = [ops_node(node, dataset, namespace) for node in nodes]
        normalized_edges = {
            edge["id"]: edge
            for raw in edges
            if raw["source"] and raw["target"]
            for edge in [ops_edge(raw, dataset, namespace)]
        }
        return {
            "ok": True,
            "nodes": normalized_nodes,
            "edges": list(normalized_edges.values()),
            "warnings": [],
        }

    def graph_counts(self) -> dict[str, int]:
        nodes = self._ops_read(
            f"MATCH (n) WHERE {ops_scope('n')} RETURN count(n) AS count"
        )[0]["count"]
        edges = self._ops_read(
            f"MATCH (s)-[r]->(o) WHERE {ops_scope('s')} AND {ops_scope('r')} AND {ops_scope('o')} RETURN count(r) AS count"
        )[0]["count"]
        return {"entities": nodes, "triplets": edges}

    def clear_dataset_graph(self) -> dict[str, int]:
        """Atomic scoped clear; foreign relationships cause rollback, never detach."""
        params = self._ops_params()
        delete_edges = f"MATCH (s)-[r]->(o) WHERE {ops_scope('s')} AND {ops_scope('r')} AND {ops_scope('o')} DELETE r RETURN count(r) AS count"
        delete_nodes = (
            f"MATCH (n) WHERE {ops_scope('n')} DELETE n RETURN count(n) AS count"
        )
        query = Neo4jQueryRequest(
            delete_nodes, params, operation="neo4j.clear_dataset_graph"
        )

        def clear(tx: Any) -> dict[str, int]:
            edges = tx.run(delete_edges, **params).single()["count"]
            nodes = tx.run(delete_nodes, **params).single()["count"]
            return {"deleted_nodes": nodes, "deleted_relationships": edges}

        return self._run_write(query, callback=clear)

    def _run_read(
        self, query: Neo4jQueryRequest, *, callback: Callable[[Any], Any]
    ) -> Any:
        self._query_executor = ensure_query_executor(
            getattr(self, "_query_executor", None),
            session_factory=self._session,
            settings=getattr(self, "_settings", None),
        )
        return self._query_executor.run_read(query, callback=callback)

    def _run_write(
        self, query: Neo4jQueryRequest, *, callback: Callable[[Any], Any]
    ) -> Any:
        self._query_executor = ensure_query_executor(
            getattr(self, "_query_executor", None),
            session_factory=self._session,
            settings=getattr(self, "_settings", None),
        )
        return self._query_executor.run_write(query, callback=callback)

    def count_entities(self) -> int:
        """Return the count of graph entity-like nodes across supported schemas."""
        query = Neo4jQueryRequest(
            build_count_query("entities"),
            {},
            operation="neo4j.count_entities",
        )
        result = self._run_read(
            query,
            callback=lambda tx: tx.run(query.statement).single(),
        )
        return parse_count(result)

    def count_triplets(self) -> int:
        """Return the count of graph relationships across supported schemas."""
        query = Neo4jQueryRequest(
            build_count_query("triplets"),
            {},
            operation="neo4j.count_triplets",
        )
        result = self._run_read(
            query,
            callback=lambda tx: tx.run(query.statement).single(),
        )
        return parse_count(result)

    def count_relationships_by_type(self) -> list[dict[str, int]]:
        """Return relationship counts grouped by relationship type."""
        query = Neo4jQueryRequest(
            build_row_grouped_query("relations"),
            {},
            operation="neo4j.count_relationships",
        )
        results = self._run_read(
            query,
            callback=lambda tx: list(tx.run(query.statement)),
        )
        return parse_relation_counts(results)

    def count_nodes_by_label(self) -> list[dict[str, Any]]:
        """Return counts of nodes grouped by their label combinations."""
        query = Neo4jQueryRequest(
            build_row_grouped_query("labels"),
            {},
            operation="neo4j.count_labels",
        )
        results = self._run_read(
            query,
            callback=lambda tx: list(tx.run(query.statement)),
        )
        return parse_label_counts(results)

    def upsert_triplets(
        self,
        triplets: Iterable[tuple[str, str, str]],
        *,
        doc_id: str | None = None,
        request_id: str | None = None,
        dataset_id: str | None = None,
        neo4j_namespace: str | None = None,
    ) -> None:
        """Insert or update triplets by merging nodes and relationships."""
        settings = getattr(self, "_settings", None)
        fn_start = time.perf_counter()
        _perf_log(
            getattr(settings, "perf_log", False),
            "perf:graph graph.neo4j_graph.upsert_triplets start doc_id=%s",
            doc_id if doc_id is not None else "__legacy__",
        )
        doc_key = str(doc_id) if doc_id is not None else "__legacy__"
        if dataset_id is not None or neo4j_namespace is not None:
            write_scope = normalize_graph_write_scope(dataset_id, neo4j_namespace)
        else:
            write_scope = normalize_graph_write_scope(
                getattr(self, "_dataset_id", None),
                getattr(self, "_neo4j_namespace", None),
            )
        if write_scope is None:
            logger.warning(
                "neo4j:refusing unscoped triplet write doc_id=%s; dataset_id and neo4j_namespace are required",
                doc_key,
            )
            return
        write_dataset_id, write_namespace = write_scope
        rows = build_triplet_rows(
            triplets,
            doc_key,
            dataset_id=write_dataset_id,
            neo4j_namespace=write_namespace,
        )
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
            operation="neo4j.upsert_triplets",
            request_id=request_id,
        )
        exec_start = time.perf_counter()
        self._run_write(
            query,
            callback=lambda tx: tx.run(query.statement, **query.params).consume(),
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

    def delete_by_doc_id(
        self, doc_id: str, *, request_id: str | None = None
    ) -> dict[str, int]:
        """Remove relationships (and orphaned nodes) belonging to a document."""
        doc_key = str(doc_id or "").strip()
        if not doc_key:
            return {"relationships_deleted": 0, "entities_deleted": 0}
        neo4j_namespace = str(getattr(self, "_neo4j_namespace", None) or "").strip()
        if not neo4j_namespace:
            logger.warning("neo4j:refusing unscoped document delete doc_id=%s", doc_key)
            return {"relationships_deleted": 0, "entities_deleted": 0}

        remove_edges_query = Neo4jQueryRequest(
            build_delete_doc_edges_query(),
            {"doc_id": doc_key, "neo4j_namespace": neo4j_namespace},
            operation="neo4j.delete_by_doc_id",
            request_id=request_id,
        )
        relationships_touched = self._run_write(
            remove_edges_query,
            callback=lambda tx: parse_delete_count(
                tx.run(
                    remove_edges_query.statement, **remove_edges_query.params
                ).single()
            ),
        )

        orphaned_query = Neo4jQueryRequest(
            build_cleanup_orphaned_relationships_query(),
            {"neo4j_namespace": neo4j_namespace},
            operation="neo4j.delete_by_doc_id",
            request_id=request_id,
        )
        relationships_deleted = self._run_write(
            orphaned_query,
            callback=lambda tx: int(
                tx.run(orphaned_query.statement, **orphaned_query.params)
                .consume()
                .counters.relationships_deleted
            ),
        )

        nodes_deleted = 0
        if relationships_touched or relationships_deleted:
            cleanup_query = Neo4jQueryRequest(
                build_cleanup_isolated_nodes_query(),
                {"doc_id": doc_key, "neo4j_namespace": neo4j_namespace},
                operation="neo4j.delete_by_doc_id",
                request_id=request_id,
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
        return {
            "relationships_deleted": relationships_deleted,
            "entities_deleted": nodes_deleted,
        }

    def delete_scoped_document(
        self, doc_id: str, *, request_id: str | None = None
    ) -> dict[str, Any]:
        """Remove one dataset's provenance atomically and verify both edge/node references.

        This stricter operation is used by managed deletion. Legacy indexing cleanup
        keeps its existing contract. Shared contributions and entities are retained.
        """
        scope = normalize_graph_write_scope(self._dataset_id, self._neo4j_namespace)
        if scope is None or not str(doc_id).strip():
            raise ValueError("Scoped deletion requires document, dataset and namespace")
        params = {"doc_id": doc_id, "dataset_id": scope[0], "neo4j_namespace": scope[1]}
        edge_scope = (
            "r.dataset_id = $dataset_id AND r.neo4j_namespace = $neo4j_namespace"
        )
        node_scope = (
            "n.dataset_id = $dataset_id AND n.neo4j_namespace = $neo4j_namespace"
        )
        query = Neo4jQueryRequest(
            "scoped document deletion",
            params,
            operation="neo4j.delete_by_doc_id",
            request_id=request_id,
        )

        def remove(tx):
            edges = (
                tx.run(
                    "MATCH ()-[r:REL]->() WHERE "
                    + edge_scope
                    + " AND (r.doc_id = $doc_id OR $doc_id IN coalesce(r.doc_ids, [])) "
                    "SET r.doc_ids = [id IN coalesce(r.doc_ids, []) WHERE id <> $doc_id] "
                    "SET r.doc_id = CASE WHEN r.doc_id = $doc_id THEN head(r.doc_ids) ELSE r.doc_id END "
                    "WITH r WHERE size(r.doc_ids) = 0 AND r.doc_id IS NULL DELETE r",
                    **params,
                )
                .consume()
                .counters.relationships_deleted
            )
            nodes = (
                tx.run(
                    "MATCH (n:Entity) WHERE "
                    + node_scope
                    + " AND $doc_id IN coalesce(n.doc_ids, []) "
                    "SET n.doc_ids = [id IN n.doc_ids WHERE id <> $doc_id] "
                    "WITH n WHERE size(n.doc_ids) = 0 AND NOT (n)--() DELETE n",
                    **params,
                )
                .consume()
                .counters.nodes_deleted
            )
            remaining_edges = tx.run(
                "MATCH ()-[r:REL]->() WHERE "
                + edge_scope
                + " AND (r.doc_id = $doc_id OR $doc_id IN coalesce(r.doc_ids, [])) RETURN count(r) AS c",
                **params,
            ).single()["c"]
            remaining_nodes = tx.run(
                "MATCH (n:Entity) WHERE "
                + node_scope
                + " AND $doc_id IN coalesce(n.doc_ids, []) RETURN count(n) AS c",
                **params,
            ).single()["c"]
            if remaining_edges or remaining_nodes:
                raise RuntimeError("Neo4j document contributions remain after deletion")
            return {
                "verified": True,
                "remaining_contributions": 0,
                "relationships_deleted": edges,
                "entities_deleted": nodes,
            }

        return self._run_write(query, callback=remove)

    def fetch_related(
        self,
        terms: Iterable[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int = 25,
    ) -> list[dict[str, str]]:
        """Pull related entities/relations that match any of the supplied terms."""
        dataset_id, neo4j_namespace = _required_read_scope(dataset_id, neo4j_namespace)
        cleaned = clean_query_terms(terms)
        if not cleaned:
            return []

        query = Neo4jQueryRequest(
            build_fetch_related_query(),
            {
                "terms": cleaned,
                "dataset_id": dataset_id,
                "neo4j_namespace": neo4j_namespace,
                "limit": int(limit),
            },
            operation="neo4j.fetch_related",
        )
        result = self._run_read(
            query,
            callback=lambda tx: list(tx.run(query.statement, **query.params)),
        )

        return parse_fact_rows(result)

    def search_structural(
        self,
        terms: Iterable[str],
        *,
        dataset_id: str,
        neo4j_namespace: str,
        limit: int = 40,
        hops: int = 2,
        include_rel_match: bool = False,
    ) -> list[dict[str, Any]]:
        """Return structural graph candidates based on matched entities and hop expansion."""
        dataset_id, neo4j_namespace = _required_read_scope(dataset_id, neo4j_namespace)
        cleaned = clean_query_terms(terms)
        if not cleaned:
            return []

        safe_hops = max(1, int(hops))
        query = Neo4jQueryRequest(
            build_search_structural_query(
                safe_hops, include_rel_match=include_rel_match
            ),
            {
                "terms": cleaned,
                "dataset_id": dataset_id,
                "neo4j_namespace": neo4j_namespace,
                "limit": int(limit),
                "hops": safe_hops,
            },
            operation="neo4j.search_structural",
        )
        result = self._run_read(
            query,
            callback=lambda tx: list(tx.run(query.statement, **query.params)),
        )
        return parse_structural_rows(result)
