"""Query-stage helper functions factored out from `query_logic`."""
from __future__ import annotations

from typing import Any, Dict, List

from vectorstore.qdrant_http import QdrantHTTP
from pipeline.query_context import prepare_context_summaries
from pipeline import query_ranking, query_rewrite
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
from utils.text_preprocessor import (
    _extract_terms,
    _is_multimodal_query,
    _normalize_list,
    _rewrite_query,
)


def normalize_int_env(name: str, default: int) -> int:
    return query_rewrite.normalize_int_env(name, default)


def build_query_rewrite(
    provider: Any,
    query: str,
    *,
    fast_mode: bool,
) -> Dict[str, Any]:
    return query_rewrite.build_query_rewrite(
        provider,
        query,
        fast_mode=fast_mode,
        is_multimodal_query=_is_multimodal_query,
        rewrite_query=_rewrite_query,
        normalize_list=_normalize_list,
    )


def build_query_terms(
    rewritten_query: str,
    high_level_keys: List[str],
    low_level_keys: List[str],
    entity_terms: List[str],
) -> List[str]:
    return query_rewrite.build_query_terms(
        rewritten_query,
        high_level_keys,
        low_level_keys,
        entity_terms,
        extract_terms=_extract_terms,
    )


def should_iterate(query: str, hits: List[Dict[str, Any]], top_k: int) -> bool:
    return query_ranking.should_iterate(query, hits, top_k)


def rerank_and_filter_hits(
    hits: List[Dict[str, Any]],
    *,
    user_query: str,
    provider: Any,
    query_vector: List[float],
    rag_service,
    mode: str,
    top_n: int,
    mix_mode: bool,
    mix_weight: float,
    min_score: float,
    fallback_min: float,
    top_k: int,
) -> List[Dict[str, Any]]:
    return query_ranking.rerank_and_filter_hits(
        hits,
        user_query=user_query,
        provider=provider,
        query_vector=query_vector,
        rag_service=rag_service,
        mode=mode,
        top_n=top_n,
        mix_mode=mix_mode,
        mix_weight=mix_weight,
        min_score=min_score,
        fallback_min=fallback_min,
        top_k=top_k,
        filter_hits=filter_hits_by_score,
    )


def filter_hits_by_score(
    hits: List[Dict[str, Any]],
    *,
    query: str,
    min_score: float,
    fallback_min: float,
    top_k: int,
) -> List[Dict[str, Any]]:
    return query_ranking.filter_hits_by_score(
        hits,
        query=query,
        min_score=min_score,
        fallback_min=fallback_min,
        top_k=top_k,
        apply_lexical_boost=apply_lexical_boost,
        dedupe_hits=dedupe_hits,
    )


def collect_expansion_terms(hits: List[Dict[str, Any]], limit: int = 8) -> List[str]:
    return query_ranking.collect_expansion_terms(hits, limit=limit, extract_terms=_extract_terms)


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
