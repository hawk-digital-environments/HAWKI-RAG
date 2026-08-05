"""
Reusable Qdrant search strategies for the FastAPI bridge.
Each function encapsulates one retrieval approach so the caller can
choose the behaviour that best matches the query. When `is_optimized`
is enabled we can switch to the `optimized_semantic_search` helper.
"""

from __future__ import annotations

from typing import Any, Iterable, Optional
import logging

from hawki_rag_stores.qdrant.client import QdrantHTTP

logger = logging.getLogger(__name__)


def semantic_search_basic(
    qdrant: QdrantHTTP,
    vector: list[float],
    *,
    top_k: int = 5,
    filters: Optional[dict[str, Any]] = None,
) -> list[dict[str, Any]]:
    """Plain dense vector search with whatever filters the caller provides."""
    result = qdrant.search(vector, top_k=top_k, filters=filters)
    logger.debug("qdrant:basic hits=%s", len(result))
    return result


def semantic_search_high_recall(
    qdrant: QdrantHTTP,
    vector: list[float],
    *,
    top_k: int = 5,
    filters: Optional[dict[str, Any]] = None,
    ef_multiplier: int = 6,
) -> list[dict[str, Any]]:
    """Increase `hnsw_ef` to favour recall over latency."""
    params = {"hnsw_ef": max(64, top_k * max(ef_multiplier, 1))}
    result = qdrant.search(vector, top_k=top_k, filters=filters, params=params)
    logger.debug("qdrant:high_recall hits=%s", len(result))
    return result


def semantic_search_with_threshold(
    qdrant: QdrantHTTP,
    vector: list[float],
    *,
    top_k: int = 5,
    filters: Optional[dict[str, Any]] = None,
    score_threshold: float = 0.32,
    ef_multiplier: int = 6,
) -> list[dict[str, Any]]:
    """Apply a minimum similarity threshold and bump `hnsw_ef`."""
    params = {"hnsw_ef": max(64, top_k * max(ef_multiplier, 1))}
    result = qdrant.search(
        vector,
        top_k=top_k,
        filters=filters,
        score_threshold=score_threshold,
        params=params,
    )
    logger.debug("qdrant:threshold hits=%s", len(result))
    return result


def optimized_semantic_search(
    qdrant: QdrantHTTP,
    vector: list[float],
    *,
    top_k: int = 5,
    filters: Optional[dict[str, Any]] = None,
    preferred_tags: Optional[Iterable[str]] = None,
    score_threshold: float = 0.28,
) -> list[dict[str, Any]]:
    """Composite search tuned for quality: metadata filter + threshold + high recall."""
    combined_filters: dict[str, Any] = dict(filters or {})
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
        logger.debug("qdrant:optimized hits=%s", len(hits))
        return hits

    # Fallback without threshold if nothing passed the bar.
    result = semantic_search_high_recall(
        qdrant,
        vector,
        top_k=top_k,
        filters=combined_filters or None,
        ef_multiplier=8,
    )
    logger.debug("qdrant:optimized fallback hits=%s", len(result))
    return result


def semantic_search_smart(
    qdrant: QdrantHTTP,
    vector: list[float],
    *,
    top_k: int = 5,
    filters: Optional[dict[str, Any]] = None,
    keyword_terms: Optional[Iterable[str]] = None,
    keyword_fields: Optional[Iterable[str]] = None,
) -> list[dict[str, Any]]:
    """Semantic search with keyword-aware filtering across payload fields."""
    terms = list(keyword_terms or [])
    fields = list(keyword_fields or [])
    result = qdrant.search(
        vector,
        top_k=top_k,
        filters=filters,
        keyword_terms=terms or None,
        keyword_fields=fields or None,
    )
    logger.debug("qdrant:smart hits=%s", len(result))
    return result
