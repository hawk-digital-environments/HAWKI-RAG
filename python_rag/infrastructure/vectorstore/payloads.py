"""Pure helpers for Qdrant request payload construction."""
from __future__ import annotations

from collections.abc import Iterable, Sequence
from typing import Any, Dict, Iterator, List, Optional


def iter_batches(items: Sequence[Any], batch_size: int) -> Iterator[list[Any]]:
    size = max(1, int(batch_size))
    for index in range(0, len(items), size):
        yield list(items[index:index + size])


def build_match_filter(filters: Optional[dict[str, Any] | list[Any]]) -> dict[str, Any]:
    if not filters:
        return {}
    match_filter = _build_canonical_filter(filters)
    if "key" in match_filter and "match" in match_filter:
        return {"must": [match_filter]}
    return match_filter


def _build_canonical_filter(node: dict[str, Any] | list[Any]) -> dict[str, Any]:
    if not node:
        return {}

    if isinstance(node, list):
        if _is_operator_node(node):
            return _build_operator_clause(str(node[0]).strip().upper(), node[1])

        if _is_leaf_node(node):
            return _build_leaf_clause(str(node[0]), node[1])

        clauses = [
            _build_canonical_filter(child)
            for child in node
            if isinstance(child, (dict, list))
        ]
        clauses = [clause for clause in clauses if clause]
        return {"must": clauses} if clauses else {}

    operators = [key for key in ("AND", "OR", "NOT") if key in node]
    if operators:
        operator = operators[0]
        if operator == "NOT":
            child = node.get("NOT")
            if not isinstance(child, (dict, list)):
                return {}
            clause = _build_canonical_filter(child)
            return {"must_not": [clause]} if clause else {}

        clauses = [
            _build_canonical_filter(child)
            for child in node.get(operator, [])
            if isinstance(child, (dict, list))
        ]
        clauses = [clause for clause in clauses if clause]
        if not clauses:
            return {}
        return {"must" if operator == "AND" else "should": clauses}

    clauses = [_build_leaf_clause(key, value) for key, value in node.items()]
    clauses = [clause for clause in clauses if clause]
    if not clauses:
        return {}
    if len(clauses) == 1:
        return clauses[0]
    return {"must": clauses}


def _build_operator_clause(operator: str, value: Any) -> dict[str, Any]:
    if operator == "NOT":
        if not isinstance(value, (dict, list)):
            return {}
        clause = _build_canonical_filter(value)
        return {"must_not": [clause]} if clause else {}

    if not isinstance(value, list):
        return {}

    clauses = [
        _build_canonical_filter(child)
        for child in value
        if isinstance(child, (dict, list))
    ]
    clauses = [clause for clause in clauses if clause]
    if not clauses:
        return {}
    return {"must" if operator == "AND" else "should": clauses}


def _is_leaf_node(node: list[Any]) -> bool:
    return (
        len(node) == 2
        and isinstance(node[0], str)
        and str(node[0]).strip().upper() not in {"AND", "OR", "NOT"}
    )


def _is_operator_node(node: list[Any]) -> bool:
    return (
        len(node) == 2
        and isinstance(node[0], str)
        and str(node[0]).strip().upper() in {"AND", "OR", "NOT"}
    )


def _build_leaf_clause(field: str, value: Any) -> dict[str, Any]:
    if isinstance(value, list):
        conditions: list[dict[str, Any]] = []
        for candidate in value:
            conditions.extend(_conditions_for_field(field, candidate))
        return {"should": conditions} if conditions else {}

    conditions = _conditions_for_field(field, value)
    if not conditions:
        return {}
    if len(conditions) == 1:
        return conditions[0]
    return {"should": conditions}


def _conditions_for_field(field: str, value: Any) -> list[dict[str, Any]]:
    normalized_value = _normalize_scalar(value)
    if field == "document_id":
        return [
            {"key": "document_id", "match": {"value": normalized_value}},
            {"key": "doc_id", "match": {"value": normalized_value}},
        ]
    return [{"key": field, "match": {"value": normalized_value}}]


def _normalize_scalar(value: Any) -> Any:
    if isinstance(value, (bool, int, float)) or value is None:
        return value
    return str(value)


def build_keyword_should_filter(
    terms: Iterable[str] | None,
    fields: Iterable[str] | None,
    *,
    max_terms: int = 6,
    match_key: str = "value",
) -> list[dict[str, Any]]:
    clean_terms = [str(term).strip() for term in (terms or []) if str(term).strip()]
    clean_fields = [str(field).strip() for field in (fields or []) if str(field).strip()]
    should: list[dict[str, Any]] = []
    for term in clean_terms[: max(0, int(max_terms))]:
        for field in clean_fields:
            should.append({"key": field, "match": {match_key: term}})
    return should


def build_text_filter(
    terms: Iterable[str] | None,
    fields: Iterable[str] | None,
    *,
    max_terms: int,
    require_all: bool,
) -> dict[str, Any]:
    clean_terms = [str(term).strip() for term in (terms or []) if str(term).strip()]
    clean_fields = [str(field).strip() for field in (fields or []) if str(field).strip()]
    clauses = [
        {"should": [{"key": field, "match": {"text": term}} for field in clean_fields]}
        for term in clean_terms[: max(0, int(max_terms))]
    ]
    clauses = [clause for clause in clauses if clause["should"]]
    if not clauses:
        return {}
    return {"must": clauses} if require_all else {"should": clauses}


def build_search_body(
    vector: list[float],
    *,
    top_k: int,
    filters: Optional[dict[str, Any] | list[Any]] = None,
    score_threshold: Optional[float] = None,
    params: Optional[dict[str, Any]] = None,
    with_payload: bool = True,
    with_vector: bool = False,
    payload_projection: Optional[list[str]] = None,
    keyword_terms: Optional[list[str]] = None,
    keyword_fields: Optional[list[str]] = None,
) -> dict[str, Any]:
    body: dict[str, Any] = {
        "vector": vector,
        "limit": int(top_k),
        "with_payload": with_payload,
        "with_vector": with_vector,
    }
    match_filter = build_match_filter(filters)
    if match_filter:
        body["filter"] = match_filter
    keyword_should = build_keyword_should_filter(keyword_terms, keyword_fields)
    if keyword_should:
        body.setdefault("filter", {})
        body["filter"].setdefault("should", [])
        body["filter"]["should"].extend(keyword_should)
    if score_threshold is not None:
        body["score_threshold"] = float(score_threshold)
    if params:
        body["params"] = params
    if payload_projection:
        body["with_payload"] = {"include": payload_projection}
    return body


def build_vector_search_body(vector: list[float], *, top_k: int, filter_body: dict[str, Any]) -> dict[str, Any]:
    return {
        "vector": vector,
        "limit": int(top_k),
        "with_payload": True,
        "with_vector": False,
        "filter": filter_body,
    }


def build_scroll_body(
    *,
    limit: int,
    filter_body: dict[str, Any],
    offset: str | None = None,
) -> dict[str, Any]:
    body: dict[str, Any] = {
        "limit": int(limit),
        "with_payload": True,
        "with_vector": False,
        "filter": filter_body,
    }
    if offset:
        body["offset"] = offset
    return body


def build_delete_filter(doc_id: str) -> dict[str, Any]:
    return {"must": [{"key": "doc_id", "match": {"value": str(doc_id)}}]}
