"""Typed Neo4j query requests and reusable Cypher fragments."""

from __future__ import annotations

from dataclasses import dataclass
import re
import unicodedata
from typing import Any, TypedDict

from collections.abc import Iterable

Triplet = tuple[str, str, str]


class UpsertRow(TypedDict):
    s: str
    s_key: str
    r: str
    o: str
    o_key: str
    doc_id: str
    dataset_id: str
    neo4j_namespace: str


@dataclass(frozen=True)
class Neo4jQueryRequest:
    """Concrete, testable representation for one query dispatch."""

    statement: str
    params: dict[str, Any]
    operation: str | None = None
    request_id: str | None = None


def build_upsert_triplets_query() -> str:
    return (
        "UNWIND $rows AS row "
        "MERGE (s:Entity {entity_key: row.s_key, dataset_id: row.dataset_id, "
        "neo4j_namespace: row.neo4j_namespace}) "
        "MERGE (o:Entity {entity_key: row.o_key, dataset_id: row.dataset_id, "
        "neo4j_namespace: row.neo4j_namespace}) "
        "SET s.name = CASE WHEN s.name IS NULL OR s.name = row.s_key THEN row.s ELSE s.name END "
        "SET o.name = CASE WHEN o.name IS NULL OR o.name = row.o_key THEN row.o ELSE o.name END "
        "SET s.doc_ids = coalesce(s.doc_ids, []) + "
        "  CASE WHEN row.doc_id IN coalesce(s.doc_ids, []) THEN [] ELSE [row.doc_id] END "
        "SET o.doc_ids = coalesce(o.doc_ids, []) + "
        "  CASE WHEN row.doc_id IN coalesce(o.doc_ids, []) THEN [] ELSE [row.doc_id] END "
        "WITH row, s, o "
        "OPTIONAL MATCH (o)-[reverse:REL {type: row.r, dataset_id: row.dataset_id, "
        "neo4j_namespace: row.neo4j_namespace}]->(s) "
        "FOREACH (_ IN CASE WHEN reverse IS NULL THEN [1] ELSE [] END | "
        "  MERGE (s)-[r:REL {type: row.r, dataset_id: row.dataset_id, "
        "neo4j_namespace: row.neo4j_namespace}]->(o) "
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


def build_fetch_related_query() -> str:
    return (
        "MATCH (s:Entity)-[r:REL]->(o:Entity) "
        "WHERE s.dataset_id = $dataset_id "
        "  AND s.neo4j_namespace = $neo4j_namespace "
        "  AND o.dataset_id = $dataset_id "
        "  AND o.neo4j_namespace = $neo4j_namespace "
        "  AND r.dataset_id = $dataset_id "
        "  AND r.neo4j_namespace = $neo4j_namespace "
        "  AND coalesce(s.name, s.entity_id) IS NOT NULL "
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


def build_search_structural_query(safe_hops: int, *, include_rel_match: bool) -> str:
    rel_clause = (
        " OR any(rel IN r WHERE toLower(coalesce(rel.type, rel.keywords, rel.description, type(rel), '')) CONTAINS term)"
        if include_rel_match
        else ""
    )
    return (
        "MATCH p=(s:Entity)-[r:REL*1..%d]->(o:Entity) "
        "WHERE s.dataset_id = $dataset_id "
        "  AND s.neo4j_namespace = $neo4j_namespace "
        "  AND o.dataset_id = $dataset_id "
        "  AND o.neo4j_namespace = $neo4j_namespace "
        "  AND all(node IN nodes(p) WHERE "
        "    node.dataset_id = $dataset_id AND node.neo4j_namespace = $neo4j_namespace) "
        "  AND all(rel IN relationships(p) WHERE "
        "    rel.dataset_id = $dataset_id AND rel.neo4j_namespace = $neo4j_namespace) "
        "  AND coalesce(s.name, s.entity_id) IS NOT NULL "
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


def build_delete_doc_edges_query() -> str:
    return (
        "MATCH (:Entity)-[r:REL]->(:Entity) "
        "WHERE r.neo4j_namespace = $neo4j_namespace "
        "  AND (r.doc_id = $doc_id OR $doc_id IN coalesce(r.doc_ids, [])) "
        "SET r.doc_ids = [id IN coalesce(r.doc_ids, []) WHERE id <> $doc_id] "
        "SET r.doc_id = CASE "
        "  WHEN r.doc_id = $doc_id THEN head([id IN coalesce(r.doc_ids, []) WHERE id <> $doc_id]) "
        "  ELSE r.doc_id "
        "END "
        "RETURN count(r) AS c"
    )


def build_cleanup_orphaned_relationships_query() -> str:
    return (
        "MATCH (:Entity)-[r:REL]->(:Entity) "
        "WHERE r.neo4j_namespace = $neo4j_namespace "
        "  AND coalesce(size(r.doc_ids), 0) = 0 "
        "DELETE r"
    )


def build_cleanup_isolated_nodes_query() -> str:
    return (
        "MATCH (n:Entity) "
        "WHERE n.neo4j_namespace = $neo4j_namespace "
        "SET n.doc_ids = [id IN coalesce(n.doc_ids, []) WHERE id <> $doc_id] "
        "WITH n "
        "WHERE NOT (n)--() DELETE n"
    )


def build_count_query(kind: str) -> str:
    if kind == "entities":
        return (
            "MATCH (n) "
            "WHERE coalesce(n.name, n.entity_id) IS NOT NULL "
            "RETURN count(n) AS c"
        )
    if kind == "triplets":
        return (
            "MATCH (s)-[r]->(o) "
            "WHERE coalesce(s.name, s.entity_id) IS NOT NULL "
            "  AND coalesce(o.name, o.entity_id) IS NOT NULL "
            "RETURN count(r) AS c"
        )
    raise ValueError(f"unsupported count kind: {kind}")


def build_row_grouped_query(kind: str) -> str:
    if kind == "relations":
        return "MATCH ()-[r]->() RETURN type(r) AS rel_type, count(r) AS count"
    if kind == "labels":
        return "MATCH (n) RETURN labels(n) AS labels, count(*) AS count"
    raise ValueError(f"unsupported grouped query kind: {kind}")


def normalize_graph_write_scope(
    dataset_id: str | None,
    neo4j_namespace: str | None,
) -> tuple[str, str] | None:
    """Return a complete write scope or reject an incomplete legacy write."""

    normalized_dataset_id = str(dataset_id or "").strip()
    normalized_namespace = str(neo4j_namespace or "").strip()
    if not normalized_dataset_id or not normalized_namespace:
        return None
    return normalized_dataset_id, normalized_namespace


def build_triplet_rows(
    triplets: Iterable[Triplet],
    doc_id: str,
    *,
    dataset_id: str | None = None,
    neo4j_namespace: str | None = None,
) -> list[UpsertRow]:
    scope = normalize_graph_write_scope(dataset_id, neo4j_namespace)
    if scope is None:
        return []
    dataset_id, neo4j_namespace = scope
    rows: list[UpsertRow] = []
    for s, r, o in triplets:
        s_key = canonical_entity_key(s)
        o_key = canonical_entity_key(o)
        if not s or not r or not o or not s_key or not o_key:
            continue
        rows.append(
            {
                "s": s,
                "s_key": s_key,
                "r": r,
                "o": o,
                "o_key": o_key,
                "doc_id": doc_id,
                "dataset_id": dataset_id,
                "neo4j_namespace": neo4j_namespace,
            }
        )
    return rows


def canonical_entity_key(value: str) -> str:
    text = unicodedata.normalize("NFKD", str(value or ""))
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = text.lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def clean_query_terms(terms: Iterable[str]) -> list[str]:
    return [t.strip().lower() for t in terms if t and len(t.strip()) > 2]
