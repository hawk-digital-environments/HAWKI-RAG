"""Bridge-owned Qdrant search and fallback policy."""

from __future__ import annotations

from collections.abc import Iterable
import logging
from typing import Any

from hawki_vector_store.client import QdrantHTTP

logger = logging.getLogger(__name__)


def semantic_search_basic(
    qdrant: QdrantHTTP,
    vector: list[float],
    *,
    top_k: int = 5,
    filters: dict[str, Any] | None = None,
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
    filters: dict[str, Any] | None = None,
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
    filters: dict[str, Any] | None = None,
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
    filters: dict[str, Any] | None = None,
    preferred_tags: Iterable[str] | None = None,
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
    filters: dict[str, Any] | None = None,
    keyword_terms: Iterable[str] | None = None,
    keyword_fields: Iterable[str] | None = None,
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
