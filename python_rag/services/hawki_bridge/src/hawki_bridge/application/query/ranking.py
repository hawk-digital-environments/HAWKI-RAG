"""Ranking and filtering helpers used by query orchestration."""

from __future__ import annotations

from typing import Any, Callable

from hawki_bridge.application.query.lexical import lexical_boost_hits
from hawki_bridge.application.query.hits import dedupe_hits_by_identity
from hawki_bridge.domain.ports import ModelProvider, RerankHitsPort
from hawki_rag_text.terms import extract_terms


DedupeHits = Callable[[list[dict[str, Any]]], list[dict[str, Any]]]
ExtractTerms = Callable[[str], list[str]]


def should_iterate(query: str, hits: list[dict[str, Any]], top_k: int) -> bool:
    """Decide whether query should trigger second-pass retrieval."""
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


def filter_hits_by_score(
    hits: list[dict[str, Any]],
    *,
    query: str,
    min_score: float,
    fallback_min: float,
    top_k: int,
    apply_lexical_boost: Callable[
        [list[dict[str, Any]], str], list[dict[str, Any]]
    ] = lexical_boost_hits,
    dedupe_hits: DedupeHits = dedupe_hits_by_identity,
) -> list[dict[str, Any]]:
    """Apply lexical boost, then score thresholds and deduplication."""
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
    extract_terms_fn: ExtractTerms = extract_terms,
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
