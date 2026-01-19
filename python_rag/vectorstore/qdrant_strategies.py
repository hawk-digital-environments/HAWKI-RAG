"""Reusable Qdrant search strategies for the FastAPI bridge.

Each function encapsulates one retrieval approach so the caller can
choose the behaviour that best matches the query. When `is_optimized`
is enabled we can switch to the `optimized_semantic_search` helper.
"""
from __future__ import annotations

from typing import Any, Dict, Iterable, List, Optional

from .qdrant_http import QdrantHTTP


def semantic_search_basic(
    qdrant: QdrantHTTP,
    vector: List[float],
    *,
    top_k: int = 5,
    filters: Optional[Dict[str, Any]] = None,
) -> List[Dict[str, Any]]:
    """Plain dense vector search with whatever filters the caller provides."""
    return qdrant.search(vector, top_k=top_k, filters=filters)


def semantic_search_high_recall(
    qdrant: QdrantHTTP,
    vector: List[float],
    *,
    top_k: int = 5,
    filters: Optional[Dict[str, Any]] = None,
    ef_multiplier: int = 6,
) -> List[Dict[str, Any]]:
    """Increase `hnsw_ef` to favour recall over latency."""
    params = {"hnsw_ef": max(64, top_k * max(ef_multiplier, 1))}
    return qdrant.search(vector, top_k=top_k, filters=filters, params=params)


def semantic_search_with_threshold(
    qdrant: QdrantHTTP,
    vector: List[float],
    *,
    top_k: int = 5,
    filters: Optional[Dict[str, Any]] = None,
    score_threshold: float = 0.32,
    ef_multiplier: int = 6,
) -> List[Dict[str, Any]]:
    """Apply a minimum similarity threshold and bump `hnsw_ef`."""
    params = {"hnsw_ef": max(64, top_k * max(ef_multiplier, 1))}
    return qdrant.search(
        vector,
        top_k=top_k,
        filters=filters,
        score_threshold=score_threshold,
        params=params,
    )


def semantic_search_with_payload_projection(
    qdrant: QdrantHTTP,
    vector: List[float],
    *,
    top_k: int = 5,
    filters: Optional[Dict[str, Any]] = None,
    payload_fields: Optional[Iterable[str]] = None,
) -> List[Dict[str, Any]]:
    """Return only the requested payload fields to reduce response size."""
    projection = list(payload_fields) if payload_fields else None
    return qdrant.search(
        vector,
        top_k=top_k,
        filters=filters,
        payload_projection=projection,
    )


def optimized_semantic_search(
    qdrant: QdrantHTTP,
    vector: List[float],
    *,
    top_k: int = 5,
    filters: Optional[Dict[str, Any]] = None,
    preferred_tags: Optional[Iterable[str]] = None,
    score_threshold: float = 0.28,
) -> List[Dict[str, Any]]:
    """Composite search tuned for quality: metadata filter + threshold + high recall."""
    combined_filters: Dict[str, Any] = dict(filters or {})
    if preferred_tags:
        combined_filters.setdefault("tags", list(preferred_tags))

    hits = semantic_search_with_threshold(
        qdrant,
        vector,
        top_k=top_k,
        filters=combined_filters or None,
        score_threshold=score_threshold,
        ef_multiplier=8,
    )

    if hits:
        return hits

    # Fallback without threshold if nothing passed the bar.
    return semantic_search_high_recall(
        qdrant,
        vector,
        top_k=top_k,
        filters=combined_filters or None,
        ef_multiplier=8,
    )


def semantic_search_smart(
    qdrant: QdrantHTTP,
    vector: List[float],
    *,
    top_k: int = 5,
    filters: Optional[Dict[str, Any]] = None,
    keyword_terms: Optional[Iterable[str]] = None,
    keyword_fields: Optional[Iterable[str]] = None,
) -> List[Dict[str, Any]]:
    """Semantic search with keyword-aware filtering across payload fields."""
    terms = list(keyword_terms or [])
    fields = list(keyword_fields or [])
    return qdrant.search(
        vector,
        top_k=top_k,
        filters=filters,
        keyword_terms=terms or None,
        keyword_fields=fields or None,
    )
