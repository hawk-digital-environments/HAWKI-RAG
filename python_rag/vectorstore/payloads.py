"""Pure helpers for Qdrant request payload construction."""
from __future__ import annotations

from collections.abc import Iterable, Sequence
from typing import Any, Dict, Iterator, List, Optional


def iter_batches(items: Sequence[Any], batch_size: int) -> Iterator[list[Any]]:
    size = max(1, int(batch_size))
    for index in range(0, len(items), size):
        yield list(items[index:index + size])


def build_match_filter(filters: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    if not filters:
        return {}
    return {"must": [{"key": key, "match": {"value": value}} for key, value in filters.items()]}


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
) -> Dict[str, Any]:
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
    vector: List[float],
    *,
    top_k: int,
    filters: Optional[Dict[str, Any]] = None,
    score_threshold: Optional[float] = None,
    params: Optional[Dict[str, Any]] = None,
    with_payload: bool = True,
    with_vector: bool = False,
    payload_projection: Optional[List[str]] = None,
    keyword_terms: Optional[List[str]] = None,
    keyword_fields: Optional[List[str]] = None,
) -> Dict[str, Any]:
    body: Dict[str, Any] = {
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


def build_vector_search_body(vector: List[float], *, top_k: int, filter_body: Dict[str, Any]) -> Dict[str, Any]:
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
    filter_body: Dict[str, Any],
    offset: str | None = None,
) -> Dict[str, Any]:
    body: Dict[str, Any] = {
        "limit": int(limit),
        "with_payload": True,
        "with_vector": False,
        "filter": filter_body,
    }
    if offset:
        body["offset"] = offset
    return body


def build_delete_filter(doc_id: str) -> Dict[str, Any]:
    return {"must": [{"key": "doc_id", "match": {"value": str(doc_id)}}]}
