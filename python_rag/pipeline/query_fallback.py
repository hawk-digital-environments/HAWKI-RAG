"""Text fallback retrieval for query search."""
from __future__ import annotations

import logging
import os
from collections.abc import Callable
from typing import Any, Dict, List

from pipeline.query_hits import merge_hits
from pipeline.query_lexical import extract_query_terms_for_lexical

logger = logging.getLogger(__name__)


def _text_scroll_limit_default(top_k: int) -> int:
    fallback_limit = max(top_k * 4, 20)
    try:
        limit_env = int(os.environ.get("QDRANT_TEXT_SCROLL_LIMIT", "200"))
        if limit_env > 0:
            return min(fallback_limit, limit_env)
        return 0
    except Exception:
        return min(fallback_limit, 200)


def _exhaustive_text_default() -> bool:
    return os.environ.get("RAG_EXHAUSTIVE_TEXT", "false").strip().lower() in ("1", "true", "yes")


def keyword_fallback_search(
    qdrant: Any,
    vec: List[float],
    query: str,
    top_k: int,
    *,
    text_scroll_limit_fn: Callable[[int], int] | None = None,
    exhaustive_text_fn: Callable[[], bool] | None = None,
) -> List[Dict[str, Any]]:
    """Run lexical fallback search and merge vector+scroll candidates.

    The two callables are injectable to make tests deterministic without touching
    process environment.
    """
    terms = extract_query_terms_for_lexical(query)
    if not terms:
        return []

    fields = ["content", "title", "page_url", "source_url", "canonical_url", "tags", "pdfs"]
    limit_fn = text_scroll_limit_fn or _text_scroll_limit_default
    exhaustive_fn = exhaustive_text_fn or _exhaustive_text_default
    fallback_limit = limit_fn(top_k)

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
        exhaustive_text=exhaustive_fn(),
    )
    if hits and scroll_hits:
        return merge_hits(hits, scroll_hits, max(top_k * 2, len(hits) + len(scroll_hits)))
    return hits or scroll_hits


def text_scroll_limit(top_k: int) -> int:
    """Backward-compatible fallback for env-driven scroll limit."""
    return _text_scroll_limit_default(top_k)


def fallback_scroll_hits(
    qdrant: Any,
    *,
    terms: List[str],
    fields: List[str],
    fallback_limit: int,
    exhaustive_text: bool | None = None,
    exhaustive_text_fn: Callable[[], bool] | None = None,
) -> List[Dict[str, Any]]:
    if exhaustive_text is None:
        exhaustive_text = exhaustive_text_fn() if exhaustive_text_fn is not None else _exhaustive_text_default()

    try:
        if exhaustive_text:
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
