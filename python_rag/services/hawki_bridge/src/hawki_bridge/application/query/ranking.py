"""Ranking and filtering helpers used by query orchestration."""

from __future__ import annotations

import math
from collections.abc import Iterable
from typing import Any, Callable

from hawki_bridge.application.query.hits import dedupe_hits_by_identity
from hawki_bridge.application.query.lexical import boost_lexical_hits, document_terms
from hawki_bridge.domain.ports import ModelProvider, RerankHitsPort


DedupeHits = Callable[[list[dict[str, Any]]], list[dict[str, Any]]]
ExtractTerms = Callable[[str | None], Iterable[str]]


def validate_vector(vector: object, expected_dim: int) -> str | None:
    """Return a bounded reason when an expansion vector is unsafe to search."""

    if not isinstance(vector, list):
        return "shape"
    if not vector:
        return "empty"
    if len(vector) != expected_dim:
        return "dimension"
    if any(
        isinstance(value, bool) or not isinstance(value, (int, float))
        for value in vector
    ):
        return "type"
    try:
        if any(not math.isfinite(value) for value in vector):
            return "non_finite"
    except OverflowError:
        return "non_finite"
    return None


def should_expand_retrieval(query: str, hits: list[dict[str, Any]], top_k: int) -> bool:
    """Return whether weak or multi-step results need a second retrieval pass.

    No hits, sequencing or comparison language, low hit density, or a weak
    maximum score trigger expansion.
    """
    if not hits:
        return True
    lowered = query.lower()
    connectors = any(
        word in lowered
        for word in [
            "first",
            "second",
            "then",
            "anschließend",
            "compare",
            "contrast",
            "schritt",
            "workflow",
        ]
    )
    scores = [float(hit.get("score") or 0.0) for hit in hits]
    max_score = max(scores) if scores else 0.0
    low_density = len(hits) < max(3, top_k)
    weak_scores = max_score < 0.42
    return connectors or low_density or weak_scores


def rerank_and_filter_hits(
    hits: list[dict[str, Any]],
    *,
    user_query: str,
    provider: ModelProvider,
    query_vector: list[float],
    rerank_hits: RerankHitsPort,
    mode: str,
    top_n: int,
    mix_mode: bool,
    mix_weight: float,
    min_score: float,
    fallback_min: float,
    top_k: int,
    filter_hits: Callable[..., list[dict[str, Any]]],
) -> list[dict[str, Any]]:
    """Apply reranker and score-based filtering in one policy step."""
    ranked_hits = rerank_hits(
        hits=hits,
        user_query=user_query,
        provider=provider,
        query_vector=query_vector,
        mode=mode,
        top_n=top_n,
        mix_mode=mix_mode,
        mix_weight=mix_weight,
    )
    return filter_hits(
        ranked_hits,
        query=user_query,
        min_score=min_score,
        fallback_min=fallback_min,
        top_k=top_k,
    )


def select_ranked_hits(
    hits: list[dict[str, Any]],
    *,
    query: str,
    min_score: float,
    fallback_min: float,
    top_k: int,
    apply_lexical_boost: Callable[
        [list[dict[str, Any]], str], list[dict[str, Any]]
    ] = boost_lexical_hits,
    dedupe_hits: DedupeHits = dedupe_hits_by_identity,
) -> list[dict[str, Any]]:
    """Select final hits using lexical, threshold, fallback, and dedupe policy.

    Lexical matches win when present, followed by primary and fallback score
    thresholds; otherwise the top-k prefix is retained.
    """
    ranked = list(hits)
    lexical = apply_lexical_boost(ranked, query)
    if lexical:
        return dedupe_hits(lexical)

    primary = [hit for hit in ranked if float(hit.get("score") or 0.0) >= min_score]
    if primary:
        return dedupe_hits(primary)

    fallback = [hit for hit in ranked if float(hit.get("score") or 0.0) >= fallback_min]
    if fallback:
        return dedupe_hits(fallback)

    return dedupe_hits(ranked[:top_k])


def collect_expansion_terms(
    hits: list[dict[str, Any]],
    limit: int = 8,
    *,
    extract_terms_fn: ExtractTerms = document_terms,
) -> list[str]:
    """Collect unique terms from ranked content snippets for iterative retrieval."""
    seen: set[str] = set()
    terms: list[str] = []
    for h in hits:
        payload = h.get("payload") or {}
        for term in extract_terms_fn(payload.get("content") or ""):
            if term not in seen:
                seen.add(term)
                terms.append(term)
            if len(terms) >= limit:
                return terms
    return terms
