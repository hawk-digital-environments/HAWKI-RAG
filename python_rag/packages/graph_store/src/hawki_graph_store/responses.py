"""Typed Neo4j response adapters and parsing helpers."""

from __future__ import annotations

import base64
import hashlib
import json
from typing import Any

from hawki_graph_store.contracts import GraphFact, GraphStructuralHit
from hawki_graph_store.normalization import dedupe_one_way_triplets


def scoped_node_id(dataset_id: str, namespace: str, key: str) -> str:
    """Bind a domain entity key to its scope without disclosing physical scope."""
    scope = hashlib.sha256(json.dumps([dataset_id, namespace]).encode()).hexdigest()
    encoded = base64.urlsafe_b64encode(key.encode()).decode().rstrip("=")
    return f"entity:{scope}:{encoded}"


def scoped_entity_key(node_id: str, dataset_id: str, namespace: str) -> str | None:
    try:
        prefix, digest, encoded = node_id.split(":", 2)
        key = base64.b64decode(
            encoded + "=" * (-len(encoded) % 4), altchars=b"-_", validate=True
        ).decode()
        if prefix == "entity" and scoped_node_id(dataset_id, namespace, key) == node_id:
            return key
    except (ValueError, UnicodeError):
        pass
    return None


def ops_properties(properties: dict) -> dict:
    return {
        key: value
        for key, value in properties.items()
        if key
        not in {
            "dataset_id",
            "neo4j_namespace",
            "collection",
            "qdrant_collection",
            "embedding",
            "vector",
            "elementId",
        }
    }


def ops_node(raw: dict, dataset_id: str, namespace: str) -> dict:
    properties = ops_properties(dict(raw["properties"]))
    docs = properties.get("doc_ids") or properties.get("doc_id") or []
    return {
        "id": scoped_node_id(dataset_id, namespace, raw["key"]),
        "label": properties.get("name") or properties.get("entity_id") or raw["key"],
        "type": (raw.get("labels") or ["Entity"])[0],
        "properties": properties,
        "score": raw.get("score"),
        "highlighted": False,
        "source_document_ids": docs if isinstance(docs, list) else [docs],
    }


def ops_edge(raw: dict, dataset_id: str, namespace: str) -> dict:
    properties = ops_properties(dict(raw["properties"]))
    source = scoped_node_id(dataset_id, namespace, raw["source"])
    target = scoped_node_id(dataset_id, namespace, raw["target"])
    relation = properties.get("type") or properties.get("relation") or raw["type"]
    identity = json.dumps([source, target, raw["type"], relation])
    return {
        "id": "edge:" + hashlib.sha256(identity.encode()).hexdigest(),
        "source": source,
        "target": target,
        "type": relation,
        "properties": properties,
        "weight": max(1, len(properties.get("doc_ids") or [])),
    }


Neo4jFactRow = GraphFact
Neo4jStructuralRow = GraphStructuralHit


def _get(record: Any, key: str, default: Any = None) -> Any:
    if hasattr(record, "get"):
        try:
            return record.get(key, default)
        except Exception:
            return default
    return default


def parse_count(record: Any) -> int:
    """Normalize Neo4j count responses to integer.

    Returns 0 when the record is missing or malformed.
    """
    raw = _get(record, "c", 0)
    try:
        return int(raw)
    except Exception:
        return 0


def parse_relation_counts(rows: Any) -> list[dict[str, Any]]:
    """Normalize relation-count rows to JSON-like mappings."""
    out: list[dict[str, Any]] = []
    if not rows:
        return out
    for row in rows:
        raw_type = _get(row, "rel_type")
        count = _get(row, "count", 0)
        out.append({"type": raw_type, "count": parse_count({"c": count})})
    return out


def parse_label_counts(rows: Any) -> list[dict[str, Any]]:
    """Normalize label-count rows to JSON-like mappings."""
    out: list[dict[str, Any]] = []
    if not rows:
        return out
    for row in rows:
        labels = _get(row, "labels", [])
        if not isinstance(labels, list):
            labels = list(labels) if labels is not None else []
        count = _get(row, "count", 0)
        out.append({"labels": list(labels), "count": parse_count({"c": count})})
    return out


def parse_fact_rows(rows: Any) -> list[Neo4jFactRow]:
    """Normalize fetched rows and return each logical fact in one direction."""
    triplets: list[tuple[str, str, str]] = []
    if not rows:
        return []
    for row in rows:
        subject = _get(row, "subject")
        relation = _get(row, "relation")
        obj = _get(row, "object")
        if subject is None or relation is None or obj is None:
            continue
        triplets.append((str(subject), str(relation), str(obj)))

    return [
        {"subject": subject, "relation": relation, "object": obj}
        for subject, relation, obj in dedupe_one_way_triplets(triplets)
    ]


def parse_structural_rows(rows: Any) -> list[Neo4jStructuralRow]:
    """Normalize structural rows to explicit hop docs."""
    out: list[Neo4jStructuralRow] = []
    if not rows:
        return out
    for row in rows:
        subject = _get(row, "subject")
        relation = _get(row, "relation")
        obj = _get(row, "object")
        if subject is None or relation is None or obj is None:
            continue
        doc_id = _get(row, "doc_id")
        hops = _get(row, "hops", 1)
        try:
            hops_i = int(hops)
        except Exception:
            hops_i = 1
        out.append(
            {
                "subject": subject,
                "relation": relation,
                "object": obj,
                "doc_id": doc_id,
                "hops": max(1, hops_i),
            }
        )
    return out


def parse_delete_count(record: Any) -> int:
    """Normalize a delete row count."""
    return parse_count(record)
