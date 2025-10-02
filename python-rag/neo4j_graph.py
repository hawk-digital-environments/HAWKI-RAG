import logging
import os
import time
from typing import Iterable, Tuple, List, Dict, Any

from neo4j import GraphDatabase, exceptions as neo4j_exceptions


logger = logging.getLogger(__name__)


class Neo4jGraph:
    """Neo4j utility used for inserting and querying LightRAG triplets."""
    def __init__(self) -> None:
        uri = os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
        user = os.environ.get("NEO4J_USER", "neo4j")
        pwd = os.environ.get("NEO4J_PASSWORD", "password")
        self._driver = GraphDatabase.driver(uri, auth=(user, pwd))

    def close(self):
        """Close the underlying driver."""
        self._driver.close()

    def count_entities(self) -> int:
        """Return the count of Entity nodes."""
        query = "MATCH (n:Entity) RETURN count(n) AS c"
        with self._driver.session() as session:
            record = session.execute_read(lambda tx: tx.run(query).single())
        return int(record.value() if record else 0)

    def count_triplets(self) -> int:
        """Return the count of triplet relationships between Entity nodes."""
        query = "MATCH (:Entity)-[r:REL]->(:Entity) RETURN count(r) AS c"
        with self._driver.session() as session:
            record = session.execute_read(lambda tx: tx.run(query).single())
        return int(record.value() if record else 0)

    def count_relationships_by_type(self) -> List[Dict[str, int]]:
        """Return relationship counts grouped by relationship type."""
        query = "MATCH ()-[r]->() RETURN type(r) AS rel_type, count(r) AS count"
        with self._driver.session() as session:
            results = session.execute_read(lambda tx: list(tx.run(query)))
        return [
            {"type": record.get("rel_type"), "count": int(record.get("count", 0))}
            for record in results
        ]

    def count_nodes_by_label(self) -> List[Dict[str, Any]]:
        """Return counts of nodes grouped by their label combinations."""
        query = "MATCH (n) RETURN labels(n) AS labels, count(*) AS count"
        with self._driver.session() as session:
            results = session.execute_read(lambda tx: list(tx.run(query)))
        return [
            {"labels": list(record.get("labels", [])), "count": int(record.get("count", 0))}
            for record in results
        ]

    def upsert_triplets(self, triplets: Iterable[Tuple[str, str, str]]):
        """Insert or update triplets by merging nodes and relationships."""
        cypher = (
            "UNWIND $rows AS row "
            "MERGE (s:Entity {name: row.s}) "
            "MERGE (o:Entity {name: row.o}) "
            "MERGE (s)-[r:REL {type: row.r}]->(o)"
        )
        rows = [{"s": s, "r": r, "o": o} for s, r, o in triplets if s and r and o]
        if not rows:
            return
        with self._driver.session() as session:
            session.execute_write(lambda tx: tx.run(cypher, rows=rows))

    def fetch_related(self, terms: Iterable[str], limit: int = 25) -> List[Dict[str, str]]:
        """Pull related entities/relations that match any of the supplied terms."""
        cleaned = [t.strip().lower() for t in terms if t and len(t.strip()) > 2]
        if not cleaned:
            return []

        cypher = (
            "MATCH (s:Entity)-[r:REL]->(o:Entity) "
            "WHERE any(term IN $terms WHERE toLower(s.name) CONTAINS term OR toLower(o.name) CONTAINS term) "
            "RETURN s.name AS subject, r.type AS relation, o.name AS object "
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
                with self._driver.session() as session:
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
