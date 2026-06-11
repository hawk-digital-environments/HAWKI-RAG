"""Typed Neo4j response adapters and parsing helpers."""
from __future__ import annotations

from typing import Any, Dict, List, TypedDict


class Neo4jCountResult(TypedDict, total=False):
    c: int


class Neo4jRelationCount(TypedDict):
    type: str | None
    count: int


class Neo4jLabelCount(TypedDict):
    labels: list[str]
    count: int


class Neo4jFactRow(TypedDict, total=False):
    subject: str
    relation: str
    object: str


class Neo4jStructuralRow(TypedDict, total=False):
    subject: str
    relation: str
    object: str
    doc_id: str | None
    hops: int


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
    """Normalize fetched related rows to a stable dict shape."""
    out: list[Neo4jFactRow] = []
    if not rows:
        return out
    for row in rows:
        subject = _get(row, "subject")
        relation = _get(row, "relation")
        obj = _get(row, "object")
        if subject is None or relation is None or obj is None:
            continue
        out.append({"subject": subject, "relation": relation, "object": obj})
    return out


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
