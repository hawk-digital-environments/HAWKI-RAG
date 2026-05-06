import logging
import os
import time
from typing import Iterable, Tuple, List, Dict, Any, Optional

from neo4j import GraphDatabase, exceptions as neo4j_exceptions


logger = logging.getLogger(__name__)
GRAPH_PERF_LOG = os.environ.get("GRAPH_PERF_LOG", "").strip().lower() in ("1", "true", "yes")


def _perf_log(msg: str, *args) -> None:
    if GRAPH_PERF_LOG:
        logger.info(msg, *args)


class Neo4jGraph:
    """Neo4j utility used for inserting and querying LightRAG triplets."""
    def __init__(self, *, database: Optional[str] = None) -> None:
        uri = os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
        user = os.environ.get("NEO4J_USER", "neo4j")
        pwd = os.environ.get("NEO4J_PASSWORD", "password")
        self._driver = GraphDatabase.driver(uri, auth=(user, pwd))
        self._database = (database or os.environ.get("NEO4J_DATABASE") or "").strip() or None
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

    def _session(self):
        if self._database:
            return self._driver.session(database=self._database)
        return self._driver.session()

    def close(self):
        """Close the underlying driver."""
        self._driver.close()

    def count_entities(self) -> int:
        """Return the count of graph entity-like nodes across supported schemas."""
        query = (
            "MATCH (n) "
            "WHERE coalesce(n.name, n.entity_id) IS NOT NULL "
            "RETURN count(n) AS c"
        )
        with self._session() as session:
            record = session.execute_read(lambda tx: tx.run(query).single())
        return int(record.value() if record else 0)

    def count_triplets(self) -> int:
        """Return the count of graph relationships across supported schemas."""
        query = (
            "MATCH (s)-[r]->(o) "
            "WHERE coalesce(s.name, s.entity_id) IS NOT NULL "
            "  AND coalesce(o.name, o.entity_id) IS NOT NULL "
            "RETURN count(r) AS c"
        )
        with self._session() as session:
            record = session.execute_read(lambda tx: tx.run(query).single())
        return int(record.value() if record else 0)

    def count_relationships_by_type(self) -> List[Dict[str, int]]:
        """Return relationship counts grouped by relationship type."""
        query = "MATCH ()-[r]->() RETURN type(r) AS rel_type, count(r) AS count"
        with self._session() as session:
            results = session.execute_read(lambda tx: list(tx.run(query)))
        return [
            {"type": record.get("rel_type"), "count": int(record.get("count", 0))}
            for record in results
        ]

    def count_nodes_by_label(self) -> List[Dict[str, Any]]:
        """Return counts of nodes grouped by their label combinations."""
        query = "MATCH (n) RETURN labels(n) AS labels, count(*) AS count"
        with self._session() as session:
            results = session.execute_read(lambda tx: list(tx.run(query)))
        return [
            {"labels": list(record.get("labels", [])), "count": int(record.get("count", 0))}
            for record in results
        ]

    def upsert_triplets(self, triplets: Iterable[Tuple[str, str, str]], *, doc_id: Optional[str] = None):
        """Insert or update triplets by merging nodes and relationships.

        Each relationship is keyed by its semantic type between two entities. Reverse
        duplicates with the same semantic type are folded into the existing direction.
        Source documents are kept as provenance in r.doc_ids so the graph stays deduped.
        """
        fn_start = time.perf_counter()
        _perf_log("perf:graph graph.neo4j_graph.upsert_triplets start doc_id=%s", doc_id if doc_id is not None else "__legacy__")
        cypher = (
            "UNWIND $rows AS row "
            "MERGE (s:Entity {name: row.s}) "
            "MERGE (o:Entity {name: row.o}) "
            "SET s.doc_ids = coalesce(s.doc_ids, []) + "
            "  CASE WHEN row.doc_id IN coalesce(s.doc_ids, []) THEN [] ELSE [row.doc_id] END "
            "SET o.doc_ids = coalesce(o.doc_ids, []) + "
            "  CASE WHEN row.doc_id IN coalesce(o.doc_ids, []) THEN [] ELSE [row.doc_id] END "
            "OPTIONAL MATCH (o)-[reverse:REL {type: row.r}]->(s) "
            "FOREACH (_ IN CASE WHEN reverse IS NULL THEN [1] ELSE [] END | "
            "  MERGE (s)-[r:REL {type: row.r}]->(o) "
            "  SET r.doc_ids = coalesce(r.doc_ids, []) + "
            "    CASE WHEN row.doc_id IN coalesce(r.doc_ids, []) THEN [] ELSE [row.doc_id] END, "
            "    r.doc_id = coalesce(r.doc_id, row.doc_id), "
            "    r.updated_at = timestamp() "
            ") "
            "FOREACH (_ IN CASE WHEN reverse IS NULL THEN [] ELSE [1] END | "
            "  SET reverse.doc_ids = coalesce(reverse.doc_ids, []) + "
            "    CASE WHEN row.doc_id IN coalesce(reverse.doc_ids, []) THEN [] ELSE [row.doc_id] END, "
            "    reverse.doc_id = coalesce(reverse.doc_id, row.doc_id), "
            "    reverse.updated_at = timestamp() "
            ")"
        )
        doc_key = str(doc_id) if doc_id is not None else "__legacy__"
        rows_start = time.perf_counter()
        rows = [{"s": s, "r": r, "o": o, "doc_id": doc_key} for s, r, o in triplets if s and r and o]
        rows_ms = (time.perf_counter() - rows_start) * 1000
        if not rows:
            logger.debug("neo4j:upsert_triplets empty doc_id=%s", doc_key)
            _perf_log(
                "perf:graph graph.neo4j_graph.upsert_triplets done doc_id=%s rows=0 build_rows_ms=%.2f execute_ms=0.00 total_ms=%.2f",
                doc_key,
                rows_ms,
                (time.perf_counter() - fn_start) * 1000,
            )
            return
        exec_start = time.perf_counter()
        with self._session() as session:
            session.execute_write(lambda tx: tx.run(cypher, rows=rows))
        exec_ms = (time.perf_counter() - exec_start) * 1000
        logger.info("neo4j:upsert_triplets count=%s doc_id=%s", len(rows), doc_key)
        _perf_log(
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
        with self._session() as session:
            def _remove_doc_id(tx):
                result = tx.run(
                    "MATCH (:Entity)-[r:REL]->(:Entity) "
                    "WHERE r.doc_id = $doc_id OR $doc_id IN coalesce(r.doc_ids, []) "
                    "SET r.doc_ids = [id IN coalesce(r.doc_ids, []) WHERE id <> $doc_id] "
                    "SET r.doc_id = CASE "
                    "  WHEN r.doc_id = $doc_id THEN head([id IN coalesce(r.doc_ids, []) WHERE id <> $doc_id]) "
                    "  ELSE r.doc_id "
                    "END "
                    "RETURN count(r) AS c",
                    doc_id=doc_key,
                )
                record = result.single()
                return int(record.get("c", 0) if record else 0)

            relationships_touched = session.execute_write(_remove_doc_id)

            def _delete_orphaned_relationships(tx):
                result = tx.run(
                    "MATCH (:Entity)-[r:REL]->(:Entity) "
                    "WHERE coalesce(size(r.doc_ids), 0) = 0 "
                    "DELETE r"
                )
                summary = result.consume()
                return summary.counters.relationships_deleted

            relationships_deleted = session.execute_write(_delete_orphaned_relationships)

            def _cleanup(tx):
                result = tx.run(
                    "MATCH (n:Entity) "
                    "SET n.doc_ids = [id IN coalesce(n.doc_ids, []) WHERE id <> $doc_id] "
                    "WITH n "
                    "WHERE NOT (n)--() DELETE n",
                    doc_id=doc_key,
                )
                summary = result.consume()
                return summary.counters.nodes_deleted

            nodes_deleted = session.execute_write(_cleanup) if relationships_touched or relationships_deleted else 0

        logger.info("neo4j:delete_by_doc_id relationships=%s entities=%s doc_id=%s", relationships_deleted, nodes_deleted, doc_id)
        return {"relationships_deleted": relationships_deleted, "entities_deleted": nodes_deleted}

    def fetch_related(self, terms: Iterable[str], limit: int = 25) -> List[Dict[str, str]]:
        """Pull related entities/relations that match any of the supplied terms."""
        cleaned = [t.strip().lower() for t in terms if t and len(t.strip()) > 2]
        if not cleaned:
            return []

        cypher = (
            "MATCH (s)-[r]->(o) "
            "WHERE coalesce(s.name, s.entity_id) IS NOT NULL "
            "  AND coalesce(o.name, o.entity_id) IS NOT NULL "
            "  AND any(term IN $terms WHERE "
            "    toLower(coalesce(s.name, s.entity_id, '')) CONTAINS term OR "
            "    toLower(coalesce(o.name, o.entity_id, '')) CONTAINS term OR "
            "    toLower(coalesce(r.type, r.keywords, r.description, type(r), '')) CONTAINS term"
            "  ) "
            "RETURN "
            "  coalesce(s.name, s.entity_id) AS subject, "
            "  coalesce(r.type, r.keywords, r.description, type(r)) AS relation, "
            "  coalesce(o.name, o.entity_id) AS object "
            "LIMIT $limit"
        )

        max_attempts = int(os.environ.get("NEO4J_RETRY_ATTEMPTS", "3"))
        log_latency = os.environ.get("NEO4J_LOG_LATENCY", "false").lower() in ("1", "true", "yes")
        backoff = 0.5
        attempt = 0
        while True:
            attempt += 1
            try:
                start = time.perf_counter()
                with self._session() as session:
                    result = session.execute_read(
                        lambda tx: list(tx.run(cypher, terms=cleaned, limit=int(limit)))
                    )
                if log_latency:
                    elapsed = time.perf_counter() - start
                    logger.info("Neo4j fetch_related returned %d rows in %.3fs", len(result), elapsed)
                break
            except neo4j_exceptions.Neo4jError as exc:
                if attempt >= max_attempts:
                    logger.warning("Neo4j fetch_related failed: %s", exc)
                    return []
                logger.warning("Neo4j error (%s). Retrying...", exc)
                time.sleep(backoff)
                backoff = min(backoff * 2, 5.0)

        facts: List[Dict[str, str]] = []
        for record in result:
            facts.append(
                {
                    "subject": record.get("subject"),
                    "relation": record.get("relation"),
                    "object": record.get("object"),
                }
            )
        return facts

    def search_structural(
        self,
        terms: Iterable[str],
        *,
        limit: int = 40,
        hops: int = 2,
        include_rel_match: bool = False,
    ) -> List[Dict[str, Any]]:
        """Return structural graph candidates based on matched entities and hop expansion."""
        cleaned = [t.strip().lower() for t in terms if t and len(t.strip()) > 2]
        if not cleaned:
            return []

        safe_hops = max(1, int(hops))
        rel_clause = (
            " OR any(rel IN r WHERE toLower(coalesce(rel.type, rel.keywords, rel.description, type(rel), '')) CONTAINS term)"
            if include_rel_match
            else ""
        )
        cypher = (
            "MATCH p=(s)-[r*1..%d]->(o) "
            "WHERE coalesce(s.name, s.entity_id) IS NOT NULL "
            "  AND coalesce(o.name, o.entity_id) IS NOT NULL "
            "  AND any(term IN $terms WHERE "
            "    toLower(coalesce(s.name, s.entity_id, '')) CONTAINS term OR "
            "    toLower(coalesce(o.name, o.entity_id, '')) CONTAINS term%s"
            "  ) "
            "WITH s, o, r, size(r) AS hops "
            "RETURN "
            "  coalesce(s.name, s.entity_id) AS subject, "
            "  coalesce(last(r).type, last(r).keywords, last(r).description, type(last(r))) AS relation, "
            "  coalesce(o.name, o.entity_id) AS object, "
            "  coalesce(last(r).doc_id, head(last(r).doc_ids), last(r).source_id) AS doc_id, "
            "  hops "
            "LIMIT $limit"
        ) % (safe_hops, rel_clause)

        max_attempts = int(os.environ.get("NEO4J_RETRY_ATTEMPTS", "3"))
        backoff = 0.5
        attempt = 0
        while True:
            attempt += 1
            try:
                with self._session() as session:
                    result = session.execute_read(
                        lambda tx: list(tx.run(cypher, terms=cleaned, limit=int(limit), hops=int(hops)))
                    )
                break
            except neo4j_exceptions.Neo4jError as exc:
                if attempt >= max_attempts:
                    logger.warning("Neo4j structural search failed: %s", exc)
                    return []
                logger.warning("Neo4j error (%s). Retrying...", exc)
                time.sleep(backoff)
                backoff = min(backoff * 2, 5.0)

        out: List[Dict[str, Any]] = []
        for record in result:
            out.append(
                {
                    "subject": record.get("subject"),
                    "relation": record.get("relation"),
                    "object": record.get("object"),
                    "doc_id": record.get("doc_id"),
                    "hops": int(record.get("hops") or 1),
                }
            )
        return out
