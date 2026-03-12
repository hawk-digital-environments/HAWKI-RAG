"""
Qdrant search strategies and hit fusion utilities.
"""
from __future__ import annotations

import logging
from typing import Any, Dict, List, Optional

from vectorstore.qdrant_http import QdrantHTTP
from vectorstore.qdrant_strategies import (
    semantic_search_basic,
    semantic_search_high_recall,
    optimized_semantic_search,
    semantic_search_smart,
)

logger = logging.getLogger(__name__)

def run_search(
    *,
    qdrant: QdrantHTTP,
    vec: List[float],
    top_k: int,
    filters: Optional[Dict[str, Any]],
    query_terms: List[str],
    keyword_fields: List[str],
    smart_lookup: bool,
    fast_mode: bool,
    is_optimized: bool,
    preferred_tags: Optional[List[str]],
) -> List[Dict[str, Any]]:
    if smart_lookup and not fast_mode:
        hits = semantic_search_smart(
            qdrant,
            vec,
            top_k=top_k,
            filters=filters,
            keyword_terms=query_terms,
            keyword_fields=keyword_fields,
        )
        if not hits:
            hits = semantic_search_basic(
                qdrant,
                vec,
                top_k=top_k,
                filters=filters,
            )
        logger.info("search:smart hits=%s", len(hits))
    elif is_optimized and not fast_mode:
        hits = optimized_semantic_search(
            qdrant,
            vec,
            top_k=top_k,
            filters=filters,
            preferred_tags=preferred_tags,
        )
        logger.info("search:optimized hits=%s", len(hits))
    else:
        hits = semantic_search_basic(
            qdrant,
            vec,
            top_k=top_k,
            filters=filters,
        )
        logger.info("search:basic hits=%s", len(hits))
    return hits


def run_high_recall(
    *,
    qdrant: QdrantHTTP,
    vec: List[float],
    top_k: int,
    filters: Optional[Dict[str, Any]],
    preferred_tags: Optional[List[str]],
) -> List[Dict[str, Any]]:
    hits = semantic_search_high_recall(
        qdrant,
        vec,
        top_k=top_k,
        filters=filters,
    )
    if not hits:
        hits = optimized_semantic_search(
            qdrant,
            vec,
            top_k=top_k,
            filters=filters,
            preferred_tags=preferred_tags,
        )
    logger.info("search:high_recall hits=%s", len(hits))
    return hits
