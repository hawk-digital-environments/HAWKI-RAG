"""Query-stage helper functions factored out from `query_logic`."""
from __future__ import annotations

from typing import Any, Dict, List

from vectorstore.qdrant_http import QdrantHTTP
from pipeline.query_context import prepare_context_summaries
from pipeline.query_fallback import keyword_fallback_search
from pipeline.query_hits import (
    dedupe_hits_by_title_or_url,
    normalize_title as normalize_title_raw,
    normalize_url as normalize_url_raw,
    fuse_hits,
    merge_hits,
)
from pipeline.query_lexical import (
    extract_query_terms_for_lexical,
    fold_text,
    levenshtein_with_limit,
    lexical_boost_hits,
    min_lexical_match_count,
    fuzzy_term_in_words,
    tokenize_words,
)
from utils.text_preprocessor import _is_multimodal_query, _extract_terms


def normalize_int_env(name: str, default: int) -> int:
    from pipeline.query_settings import int_env

    return int_env(name, default)


def should_iterate(query: str, hits: List[Dict[str, Any]], top_k: int) -> bool:
    if not hits:
        return True
    lowered = query.lower()
    connectors = any(
        word in lowered
        for word in ["first", "second", "then", "anschließend", "compare", "contrast", "schritt", "workflow"]
    )
    scores = [float(h.get("score") or 0.0) for h in hits]
    max_score = max(scores) if scores else 0.0
    low_density = len(hits) < max(3, top_k)
    weak_scores = max_score < 0.42
    return connectors or low_density or weak_scores


def collect_expansion_terms(hits: List[Dict[str, Any]], limit: int = 8) -> List[str]:
    seen: set[str] = set()
    terms: List[str] = []
    for h in hits:
        payload = h.get("payload") or {}
        for term in _extract_terms(payload.get("content") or ""):
            if term not in seen:
                seen.add(term)
                terms.append(term)
            if len(terms) >= limit:
                return terms
    return terms


def build_fused_hits(sem_hits: List[Dict[str, Any]], struct_hits: List[Dict[str, Any]], *, sem_weight: float, str_weight: float) -> List[Dict[str, Any]]:
    return fuse_hits(sem_hits, struct_hits, sem_weight=sem_weight, str_weight=str_weight)


def merge_hits_with_limit(primary: List[Dict[str, Any]], secondary: List[Dict[str, Any]], limit: int) -> List[Dict[str, Any]]:
    return merge_hits(primary, secondary, limit)


def prepare_context(
    hits: List[Dict[str, Any]],
    *,
    max_docs: int,
    max_tokens: int,
) -> tuple[List[Dict[str, Any]], List[int], int]:
    return prepare_context_summaries(hits, max_docs=max_docs, max_tokens=max_tokens)


def keyword_fallback(
    qdrant: QdrantHTTP,
    vec: List[float],
    query: str,
    top_k: int,
) -> List[Dict[str, Any]]:
    return keyword_fallback_search(qdrant, vec, query, top_k)


def is_multimodal_query(text: str) -> bool:
    return _is_multimodal_query(text)


def normalize_title(value: Any) -> str:
    return normalize_title_raw(value)


def normalize_url(value: Any) -> str:
    return normalize_url_raw(value)


def dedupe_hits(hits: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    return dedupe_hits_by_title_or_url(hits)


def text_fold(value: Any) -> str:
    return fold_text(value)


def apply_fuzzy_term_match(term: str, words: List[str]) -> bool:
    return fuzzy_term_in_words(term, words)


def sanitize_query_terms_for_lexical(query: str) -> List[str]:
    return extract_query_terms_for_lexical(query)


def apply_lexical_boost(hits: List[Dict[str, Any]], query: str) -> List[Dict[str, Any]]:
    return lexical_boost_hits(hits, query)


def lexical_min_match_count(terms: List[str]) -> int:
    return min_lexical_match_count(terms)


def split_lexical_words(text: str) -> List[str]:
    return tokenize_words(text)


def levenshtein_limit(a: str, b: str, limit: int = 1) -> int:
    return levenshtein_with_limit(a, b, limit)


def fuzzy_term(term: str, words: List[str]) -> bool:
    return fuzzy_term_in_words(term, words)
