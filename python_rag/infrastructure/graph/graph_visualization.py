import json
import logging
from collections.abc import Callable
from pathlib import Path
from dataclasses import replace
from typing import Any

from infrastructure.graph.visualization_settings import GraphVisualizationSettings, load_graph_visualization_settings
from common.optional_imports import import_required_module


logger = logging.getLogger(__name__)


class _UnavailableNeo4jError(Exception):
    """Internal sentinel used when the optional Neo4j package is absent."""


def _load_neo4j_driver_factory() -> Callable[..., Any]:
    neo4j_module = import_required_module(
        "neo4j",
        install_hint="Install python_rag/requirements.txt to export Neo4j graph visualizations.",
    )
    return neo4j_module.GraphDatabase.driver


def _load_neo4j_error_type() -> type[Exception]:
    try:
        neo4j_module = import_required_module(
            "neo4j",
            install_hint="Install python_rag/requirements.txt to export Neo4j graph visualizations.",
        )
    except RuntimeError:
        return _UnavailableNeo4jError
    return neo4j_module.exceptions.Neo4jError


class Neo4jGraphVisualization:
    """Export a compact Neo4j graph snapshot for the HAWKI playground UI."""

    def __init__(
        self,
        *,
        database: str | None = None,
        settings: GraphVisualizationSettings | None = None,
        driver_factory: Callable[..., Any] | None = None,
    ) -> None:
        config = settings or load_graph_visualization_settings(database=database)
        self._settings = replace(
            config,
            database=(database or "").strip() or config.database,
        )
        driver_factory = driver_factory or _load_neo4j_driver_factory()
        self._driver = driver_factory(self._settings.uri, auth=(self._settings.user, self._settings.password))
        self._database = self._settings.database
        if self._database:
            neo4j_error_type = _load_neo4j_error_type()
            try:
                with self._driver.session(database=self._database) as session:
                    session.run("RETURN 1").consume()
            except neo4j_error_type as exc:
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

    def snapshot(self, *, limit: int | None = None, recent_doc_id: str | None = None) -> dict[str, Any]:
        effective_limit = int(limit) if limit is not None else 0
        limit_clause = "LIMIT $limit " if effective_limit > 0 else ""
        query = (
            "MATCH (s:Entity)-[r:REL]->(o:Entity) "
            "WITH s, r, o "
            "ORDER BY coalesce(r.updated_at, 0) DESC "
            f"{limit_clause}"
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
            records = session.execute_read(lambda tx: list(tx.run(query, limit=effective_limit)))

        nodes: dict[str, dict[str, Any]] = {}
        links: list[dict[str, Any]] = []
        doc_ids = set()
        recent_doc_key = str(recent_doc_id).strip() if recent_doc_id else None
        recent_relationship_count = 0

        for record in records:
            source_id = str(record.get("source_id"))
            target_id = str(record.get("target_id"))
            doc_id = record.get("doc_id")
            if doc_id:
                doc_ids.add(str(doc_id))
            relationship_doc_ids = [str(value) for value in (record.get("doc_ids") or []) if value]
            doc_ids.update(relationship_doc_ids)
            is_recent = bool(
                recent_doc_key
                and (
                    str(doc_id) == recent_doc_key
                    or recent_doc_key in relationship_doc_ids
                )
            )
            if is_recent:
                recent_relationship_count += 1

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
                    "is_recent": is_recent,
                    "updated_at": record.get("updated_at"),
                }
            )

        return {
            "ok": True,
            "generated_at": _utc_now_iso(),
            "limit": effective_limit if effective_limit > 0 else None,
            "node_count": len(nodes),
            "relationship_count": len(links),
            "recent_doc_id": recent_doc_key,
            "recent_relationship_count": recent_relationship_count,
            "document_count": len(doc_ids),
            "nodes": list(nodes.values()),
            "links": links,
        }


def write_graph_visualization(
    public_dir: Path,
    *,
    database: str | None = None,
    limit: int | None = None,
    settings: GraphVisualizationSettings | None = None,
    recent_doc_id: str | None = None,
) -> Path | None:
    config = settings or load_graph_visualization_settings(database=database)
    if database is not None and database.strip() and database.strip() != (config.database or ""):
        config = replace(config, database=(database or "").strip())
    if not config.enabled:
        return None

    effective_limit = int(limit if limit is not None else config.limit)
    exporter = Neo4jGraphVisualization(database=config.database, settings=config)
    try:
        snapshot = exporter.snapshot(
            limit=effective_limit,
            recent_doc_id=recent_doc_id,
        )
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


def _utc_now_iso() -> str:
    from datetime import datetime, timezone

    return datetime.now(tz=timezone.utc).isoformat()
