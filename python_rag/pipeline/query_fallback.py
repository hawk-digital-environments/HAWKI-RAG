"""Text fallback retrieval for query search."""
from __future__ import annotations

import logging
import os
from typing import Any, Dict, List

from pipeline.query_hits import merge_hits
from pipeline.query_lexical import extract_query_terms_for_lexical

logger = logging.getLogger(__name__)


def keyword_fallback_search(qdrant: Any, vec: List[float], query: str, top_k: int) -> List[Dict[str, Any]]:
    terms = extract_query_terms_for_lexical(query)
    if not terms:
        return []
    fields = ["content", "title", "page_url", "source_url", "canonical_url", "tags", "pdfs"]
    fallback_limit = text_scroll_limit(top_k)
    hits: List[Dict[str, Any]] = []
    try:
        hits = qdrant.search_with_text(vec, top_k=top_k, terms=terms, fields=fields)
    except Exception as exc:
        logger.warning("query:text-fallback search failed: %s", exc)

    scroll_hits = fallback_scroll_hits(
        qdrant,
        terms=terms,
        fields=fields,
        fallback_limit=fallback_limit,
    )
    if hits and scroll_hits:
        return merge_hits(hits, scroll_hits, max(top_k * 2, len(hits) + len(scroll_hits)))
    return hits or scroll_hits


def text_scroll_limit(top_k: int) -> int:
    fallback_limit = max(top_k * 4, 20)
    try:
        limit_env = int(os.environ.get("QDRANT_TEXT_SCROLL_LIMIT", "200"))
        if limit_env > 0:
            return min(fallback_limit, limit_env)
        return 0
    except Exception:
        return min(fallback_limit, 200)


def fallback_scroll_hits(
    qdrant: Any,
    *,
    terms: List[str],
    fields: List[str],
    fallback_limit: int,
) -> List[Dict[str, Any]]:
    exhaustive = os.environ.get("RAG_EXHAUSTIVE_TEXT", "false").lower() in ("1", "true", "yes")
    try:
        if exhaustive:
            scroll_hits = qdrant.scroll_with_text_all(
                terms=terms,
                fields=fields,
                limit=fallback_limit,
                require_all=True,
            )
            if not scroll_hits:
                scroll_hits = qdrant.scroll_with_text_all(
                    terms=terms,
                    fields=fields,
                    limit=fallback_limit,
                    require_all=False,
                )
            return scroll_hits

        scroll_hits = qdrant.scroll_with_text(
            terms=terms,
            fields=fields,
            limit=fallback_limit,
            require_all=True,
        )
        if not scroll_hits:
            scroll_hits = qdrant.scroll_with_text(
                terms=terms,
                fields=fields,
                limit=fallback_limit,
                require_all=False,
            )
        return scroll_hits
    except Exception as exc:
        logger.warning("query:text-fallback scroll failed: %s", exc)
        return []
