"""Text fallback retrieval for query search."""

from __future__ import annotations

import logging
import os
from collections.abc import Callable
from typing import Any

from hawki_bridge.application.query.hits import merge_retrieval_hits
from hawki_bridge.application.query.lexical import query_terms
from hawki_bridge.domain.errors import DatasetVectorStoreNotReadyError
from hawki_bridge.domain.ports import ScopedFilters, VectorSearchPort

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
    return os.environ.get("RAG_EXHAUSTIVE_TEXT", "false").strip().lower() in (
        "1",
        "true",
        "yes",
    )


def retrieve_lexical_hits(
    qdrant: VectorSearchPort,
    vec: list[float],
    query: str,
    top_k: int,
    *,
    filters: ScopedFilters | None = None,
    text_scroll_limit_fn: Callable[[int], int] | None = None,
    exhaustive_text_fn: Callable[[], bool] | None = None,
) -> list[dict[str, Any]]:
    """Retrieve lexical hits through scoped search and scroll fallbacks.

    Mandatory dataset filters are forwarded to every path. A missing scoped
    collection propagates, while other search or scroll failures degrade to the
    remaining results.
    """
    terms = list(query_terms(query).folded)
    if not terms:
        return []

    fields = [
        "content",
        "title",
        "page_url",
        "source_url",
        "canonical_url",
        "tags",
        "pdfs",
    ]
    limit_fn = text_scroll_limit_fn or _text_scroll_limit_default
    exhaustive_fn = exhaustive_text_fn or _exhaustive_text_default
    fallback_limit = limit_fn(top_k)

    hits: list[dict[str, Any]] = []
    try:
        search_kwargs: dict[str, Any] = {
            "top_k": top_k,
            "terms": terms,
            "fields": fields,
        }
        if filters:
            search_kwargs["filters"] = filters
        hits = qdrant.search_with_text(vec, **search_kwargs)
    except DatasetVectorStoreNotReadyError:
        raise
    except Exception as exc:
        logger.warning("query:text-fallback search failed: %s", exc)

    scroll_hits = scroll_lexical_hits(
        qdrant,
        terms=terms,
        fields=fields,
        fallback_limit=fallback_limit,
        exhaustive_text=exhaustive_fn(),
        filters=filters,
    )
    if hits and scroll_hits:
        return merge_retrieval_hits(
            hits, scroll_hits, max(top_k * 2, len(hits) + len(scroll_hits))
        )
    return hits or scroll_hits


def scroll_lexical_hits(
    qdrant: VectorSearchPort,
    *,
    terms: list[str],
    fields: list[str],
    fallback_limit: int,
    exhaustive_text: bool | None = None,
    exhaustive_text_fn: Callable[[], bool] | None = None,
    filters: ScopedFilters | None = None,
) -> list[dict[str, Any]]:
    """Scroll scoped lexical hits with strict-then-relaxed term matching.

    Exhaustive mode selects the all-pages operation. A missing scoped collection
    propagates; other scroll failures degrade to an empty result.
    """
    if exhaustive_text is None:
        exhaustive_text = (
            exhaustive_text_fn()
            if exhaustive_text_fn is not None
            else _exhaustive_text_default()
        )

    try:
        scroll_kwargs: dict[str, Any] = {
            "terms": terms,
            "fields": fields,
            "limit": fallback_limit,
            "require_all": True,
        }
        if filters:
            scroll_kwargs["filters"] = filters

        if exhaustive_text:
            scroll_hits = qdrant.scroll_with_text_all(**scroll_kwargs)
            if not scroll_hits:
                scroll_kwargs["require_all"] = False
                scroll_hits = qdrant.scroll_with_text_all(**scroll_kwargs)
            return scroll_hits

        scroll_hits = qdrant.scroll_with_text(**scroll_kwargs)
        if not scroll_hits:
            scroll_kwargs["require_all"] = False
            scroll_hits = qdrant.scroll_with_text(**scroll_kwargs)
        return scroll_hits
    except DatasetVectorStoreNotReadyError:
        raise
    except Exception as exc:
        logger.warning("query:text-fallback scroll failed: %s", exc)
        return []
