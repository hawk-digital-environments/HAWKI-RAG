import json
import logging
import os
from pathlib import Path
from typing import Any, Dict, List, Optional

from neo4j import GraphDatabase, exceptions as neo4j_exceptions


logger = logging.getLogger(__name__)


class Neo4jGraphVisualization:
    """Export a compact Neo4j graph snapshot for the HAWKI playground UI."""

    def __init__(self, *, database: Optional[str] = None) -> None:
        uri = os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
        user = os.environ.get("NEO4J_USER", "neo4j")
        password = os.environ.get("NEO4J_PASSWORD", "password")
        self._driver = GraphDatabase.driver(uri, auth=(user, password))
        self._database = (database or os.environ.get("NEO4J_DATABASE") or "").strip() or None
        if self._database:
            try:
                with self._driver.session(database=self._database) as session:
                    session.run("RETURN 1").consume()
            except neo4j_exceptions.Neo4jError as exc:
                logger.warning(
                    "graph-viz:requested database '%s' unavailable (%s); using default database",
                    self._database,
                    exc,
                )
                self._database = None

    def close(self) -> None:
        self._driver.close()

    def _session(self):
        if self._database:
            return self._driver.session(database=self._database)
        return self._driver.session()

    def snapshot(self, *, limit: int = 250) -> Dict[str, Any]:
        query = (
            "MATCH (s:Entity)-[r:REL]->(o:Entity) "
            "WITH s, r, o "
            "ORDER BY coalesce(r.updated_at, 0) DESC "
            "LIMIT $limit "
            "RETURN "
            "  elementId(s) AS source_id, "
            "  labels(s) AS source_labels, "
            "  coalesce(s.name, s.entity_id, elementId(s)) AS source_name, "
            "  elementId(o) AS target_id, "
            "  labels(o) AS target_labels, "
            "  coalesce(o.name, o.entity_id, elementId(o)) AS target_name, "
            "  elementId(r) AS relationship_id, "
            "  type(r) AS relationship_type, "
            "  coalesce(r.type, r.keywords, r.description, type(r)) AS relationship_label, "
            "  r.doc_id AS doc_id, "
            "  r.doc_ids AS doc_ids, "
            "  r.updated_at AS updated_at"
        )
        with self._session() as session:
            records = session.execute_read(lambda tx: list(tx.run(query, limit=max(1, int(limit)))))

        nodes: Dict[str, Dict[str, Any]] = {}
        links: List[Dict[str, Any]] = []
        doc_ids = set()

        for record in records:
            source_id = str(record.get("source_id"))
            target_id = str(record.get("target_id"))
            doc_id = record.get("doc_id")
            if doc_id:
                doc_ids.add(str(doc_id))
            relationship_doc_ids = [str(value) for value in (record.get("doc_ids") or []) if value]
            doc_ids.update(relationship_doc_ids)

            nodes.setdefault(
                source_id,
                {
                    "id": source_id,
                    "label": str(record.get("source_name") or source_id),
                    "labels": list(record.get("source_labels") or []),
                },
            )
            nodes.setdefault(
                target_id,
                {
                    "id": target_id,
                    "label": str(record.get("target_name") or target_id),
                    "labels": list(record.get("target_labels") or []),
                },
            )
            links.append(
                {
                    "id": str(record.get("relationship_id")),
                    "source": source_id,
                    "target": target_id,
                    "type": str(record.get("relationship_type") or "REL"),
                    "label": str(record.get("relationship_label") or record.get("relationship_type") or "REL"),
                    "doc_id": str(doc_id) if doc_id else None,
                    "doc_ids": relationship_doc_ids,
                    "updated_at": record.get("updated_at"),
                }
            )

        return {
            "ok": True,
            "generated_at": _utc_now_iso(),
            "limit": max(1, int(limit)),
            "node_count": len(nodes),
            "relationship_count": len(links),
            "document_count": len(doc_ids),
            "nodes": list(nodes.values()),
            "links": links,
        }


def write_graph_visualization(
    public_dir: Path,
    *,
    database: Optional[str] = None,
    limit: Optional[int] = None,
) -> Optional[Path]:
    if os.environ.get("NEO4J_GRAPH_VISUALIZATION", "true").strip().lower() in ("0", "false", "no", "off"):
        return None

    exporter = Neo4jGraphVisualization(database=database)
    try:
        snapshot = exporter.snapshot(limit=limit if limit is not None else _int_env("NEO4J_GRAPH_VISUALIZATION_LIMIT", 250))
    finally:
        exporter.close()

    public_dir.mkdir(parents=True, exist_ok=True)
    path = public_dir / "neo4j_graph_visualization.json"
    tmp_path = path.with_suffix(".json.tmp")
    tmp_path.write_text(json.dumps(snapshot, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    tmp_path.replace(path)
    path.chmod(0o666)
    logger.info(
        "graph-viz:wrote nodes=%s relationships=%s file=%s",
        snapshot.get("node_count"),
        snapshot.get("relationship_count"),
        path,
    )
    return path


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except Exception:
        return default


def _utc_now_iso() -> str:
    from datetime import datetime, timezone

    return datetime.now(tz=timezone.utc).isoformat()
