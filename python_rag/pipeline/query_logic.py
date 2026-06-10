"""
Query pipeline extracted from rag_brain: search, graph expansion, rerank, context prep.
"""
from __future__ import annotations

import os
import time
import logging
from typing import Any, Dict, List, Tuple

from fastapi import HTTPException

from vectorstore.qdrant_http import QdrantHTTP
from utils.safety_utils import analyze_prompt, enforce_output_safety, sanitize_prompt_text
from utils.text_preprocessor import (
    _extract_terms,
    _normalize_list,
    _terms_from_payload,
    _rewrite_query,
)
from pipeline import query_stages
from pipeline.query_settings import (
    context_limits,
    fusion_weights,
    generation_enabled,
    iterative_retrieval_enabled,
    score_thresholds,
    search_top_k as configured_search_top_k,
)
from vectorstore.vector_search import run_search, run_high_recall
from graph.graph_utils import (
    fetch_related_terms,
    structural_limit,
    structural_hops,
    build_structural_hits,
)

logger = logging.getLogger(__name__)


def query_documents(body: Any, *, rag_service: Any, get_provider) -> Dict[str, Any]:
    timings: Dict[str, float] = {}
    os.environ["RAG_FAST_MODE"] = "true" if body.fast_mode else "false"
    prompt_safety = analyze_prompt(body.query)
    if prompt_safety["blocked"]:
        detail = "Query blocked by content safety filters."
        if prompt_safety["issues"]:
            detail += f" Reasons: {', '.join(prompt_safety['issues'])}."
        raise HTTPException(status_code=400, detail=detail)

    user_query = prompt_safety["sanitized"]
    if not user_query.strip():
        raise HTTPException(status_code=400, detail="Query is empty after sanitization.")

    provider = get_provider(body.provider)
    qdrant = QdrantHTTP()
    text_fallback_used = False
    logger.info("query:start provider=%s top_k=%s fast=%s smart=%s optimized=%s", body.provider, body.top_k, body.fast_mode, body.smart_lookup, body.is_optimized)

    t_rewrite_start = time.perf_counter()
    rewrite_enabled = (not body.fast_mode) and query_stages.is_multimodal_query(user_query)
    rewrite = {} if not rewrite_enabled else _rewrite_query(provider, user_query)
    timings["rewrite_ms"] = (time.perf_counter() - t_rewrite_start) * 1000
    rewritten_query = sanitize_prompt_text(rewrite.get("rewritten_query") or user_query)
    high_level_keys = _normalize_list(rewrite.get("high_level_keys"))
    low_level_keys = _normalize_list(rewrite.get("low_level_keys"))
    modality_hints = _normalize_list(rewrite.get("modality_hints"))
    entity_terms = _normalize_list(rewrite.get("entity_terms"))
    query_terms = list(dict.fromkeys(entity_terms + low_level_keys + high_level_keys + _extract_terms(rewritten_query)))

    t_embed_start = time.perf_counter()
    try:
        vec = provider.embed(rewritten_query)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Embedding failed: {exc}") from exc
    timings["embed_ms"] = (time.perf_counter() - t_embed_start) * 1000

    filters = dict(body.filters) if body.filters else None
    t_qdrant_start = time.perf_counter()
    keyword_fields = ["title", "page_url", "source_url", "canonical_url", "tags", "content", "pdfs"]
    search_top_k = configured_search_top_k(body.top_k)
    hits = run_search(
        qdrant=qdrant,
        vec=vec,
        top_k=search_top_k,
        filters=filters,
        query_terms=query_terms,
        keyword_fields=keyword_fields,
        smart_lookup=body.smart_lookup,
        fast_mode=body.fast_mode,
        is_optimized=body.is_optimized,
        preferred_tags=body.preferred_tags,
    )
    keyword_hits = query_stages.keyword_fallback(qdrant, vec, rewritten_query, search_top_k)
    if keyword_hits:
        text_fallback_used = True
        hits = query_stages.merge_hits_with_limit(hits, keyword_hits, max(search_top_k * 2, len(hits) + len(keyword_hits)))
    timings["qdrant_ms"] = (time.perf_counter() - t_qdrant_start) * 1000
    logger.info("query:qdrant hits=%s ms=%.2f", len(hits), timings["qdrant_ms"])

    # Prefer client-provided structural_hops when set; otherwise fall back to env default.
    struct_hops = body.structural_hops if getattr(body, "structural_hops", None) is not None else structural_hops()
    t_graph_start = time.perf_counter()
    structural_hits = [] if body.fast_mode or struct_hops == 0 else build_structural_hits(
        query_terms,
        limit=structural_limit(body.top_k),
        hops=struct_hops,
        include_rel_match=body.smart_lookup,
    )
    timings["graph_ms"] = (time.perf_counter() - t_graph_start) * 1000
    logger.info("query:graph hits=%s ms=%.2f", len(structural_hits), timings["graph_ms"])

    sem_weight, str_weight = fusion_weights()
    hits = query_stages.build_fused_hits(hits, structural_hits, sem_weight=sem_weight, str_weight=str_weight)
    # Keep graph relation hits (component_type 'relation') so Neo4j triplets can surface when semantic hits are sparse.
    hits = [h for h in hits if (h.get("payload") or {}).get("component_type") in (None, "", "chunk", "relation")]
    t_rerank_start = time.perf_counter()
    hits = rag_service.rerank_hits(
        hits=hits,
        user_query=rewritten_query,
        provider=provider,
        query_vector=vec,
        mode=body.reranker,
        top_n=body.rerank_top_n,
        mix_mode=body.mix_mode,
        mix_weight=body.mix_weight,
    )
    min_score, fallback_min = score_thresholds()
    hits_all = list(hits)
    hits_lex = query_stages.apply_lexical_boost(hits_all, rewritten_query)
    if hits_lex:
        hits = hits_lex
    else:
        hits = [h for h in hits_all if float(h.get("score") or 0.0) >= min_score]
        if not hits and hits_all:
            hits = [h for h in hits_all if float(h.get("score") or 0.0) >= fallback_min]
            if not hits:
                hits = hits_all[: body.top_k]
    hits = _dedupe_hits_by_title_or_url(hits)
    logger.info("query:rerank hits=%s ms=%.2f", len(hits), timings.get("rerank_ms", 0.0))
    timings["rerank_ms"] = (time.perf_counter() - t_rerank_start) * 1000

    iterative_enabled = iterative_retrieval_enabled()
    iteration_used = False
    expansion_terms: List[str] = []
    if iterative_enabled and _should_iterate(rewritten_query, hits, body.top_k):
        iteration_used = True
        expansion_terms = _collect_expansion_terms(hits)
        expanded_query = rewritten_query
        if expansion_terms:
            expanded_query = f"{rewritten_query}\nKey entities: {', '.join(expansion_terms[:6])}"
        try:
            iter_vec = provider.embed(expanded_query) if expansion_terms else vec
        except Exception:
            iter_vec = vec

        secondary_hits = run_high_recall(
            qdrant=qdrant,
            vec=iter_vec,
            top_k=max(body.top_k * 2, len(hits) or body.top_k),
            filters=filters,
            preferred_tags=body.preferred_tags,
        )
        if secondary_hits:
            hits = _merge_hits(hits, secondary_hits, max(body.top_k * 2, 12))
            hits = rag_service.rerank_hits(
                hits=hits,
                user_query=rewritten_query,
                provider=provider,
                query_vector=vec,
                mode=body.reranker,
                top_n=body.rerank_top_n,
                mix_mode=body.mix_mode,
                mix_weight=body.mix_weight,
            )
            hits_all = list(hits)
            hits_lex = query_stages.apply_lexical_boost(hits_all, rewritten_query)
            if hits_lex:
                hits = hits_lex
            else:
                hits = [h for h in hits_all if float(h.get("score") or 0.0) >= min_score]
                if not hits and hits_all:
                    hits = [h for h in hits_all if float(h.get("score") or 0.0) >= fallback_min]
                    if not hits:
                        hits = hits_all[: body.top_k]
            hits = _dedupe_hits_by_title_or_url(hits)

    max_context_tokens, max_context_docs = context_limits()

    final_hit_limit = max(body.top_k, max_context_docs)
    if len(hits) > final_hit_limit:
        hits = hits[:final_hit_limit]

    context_summaries, trimmed_sources, context_tokens_used = _prepare_context_summaries(
        hits,
        max_docs=max_context_docs,
        max_tokens=max_context_tokens,
    )

    kg_facts: List[Dict[str, str]] = []
    t_kg_start = time.perf_counter()
    if hits and not body.fast_mode:
        kg_terms = set(_extract_terms(rewritten_query))
        kg_terms.update(query_terms)
        for h in hits[: body.top_k]:
            payload = h.get("payload") or {}
            kg_terms.update(_terms_from_payload(payload))
            content_sample = (payload.get("content") or "")[:160]
            kg_terms.update(_extract_terms(content_sample))
        limited_terms = list(kg_terms)[:30]
        if limited_terms:
            kg_facts = fetch_related_terms(limited_terms, limit=30)
    logger.info("query:kg facts=%s ms=%.2f", len(kg_facts), timings.get("kg_ms", 0.0))
    timings["kg_ms"] = (time.perf_counter() - t_kg_start) * 1000

    answer = ""
    output_safety = {"blocked": False, "issues": [], "answer": ""}
    if generation_enabled() and context_summaries:
        output_safety = enforce_output_safety(answer)
        answer = output_safety["answer"]

    return {
        "ok": True,
        "count": len(hits),
        "hits": hits,
        "kg": kg_facts,
        "answer": answer,
        "retrieval": {
            "iterative_pass": iteration_used,
            "expansion_terms": expansion_terms if expansion_terms else [],
            "context_tokens_used": context_tokens_used,
            "context_docs": len(context_summaries),
            "context_trimmed": trimmed_sources,
            "max_context_tokens": max_context_tokens,
            "rewrite": {
                "query": rewritten_query if rewritten_query != body.query else None,
                "high_level_keys": high_level_keys,
                "low_level_keys": low_level_keys,
                "entity_terms": entity_terms,
                "modality_hints": modality_hints,
                "enabled": rewrite_enabled,
            },
            "timings_ms": {
                "rewrite": timings.get("rewrite_ms"),
                "embed": timings.get("embed_ms"),
                "qdrant": timings.get("qdrant_ms"),
                "graph": timings.get("graph_ms"),
                "rerank": timings.get("rerank_ms"),
                "kg": timings.get("kg_ms"),
            },
        },
    }


def _should_iterate(query: str, hits: List[Dict[str, Any]], top_k: int) -> bool:
    return query_stages.should_iterate(query, hits, top_k)


def _collect_expansion_terms(hits: List[Dict[str, Any]], limit: int = 8) -> List[str]:
    return query_stages.collect_expansion_terms(hits, limit=limit)


def _fuse_hits(sem_hits: List[Dict[str, Any]], struct_hits: List[Dict[str, Any]], *, sem_weight: float, str_weight: float) -> List[Dict[str, Any]]:
    return query_stages.build_fused_hits(sem_hits, struct_hits, sem_weight=sem_weight, str_weight=str_weight)


def _merge_hits(primary: List[Dict[str, Any]], secondary: List[Dict[str, Any]], limit: int) -> List[Dict[str, Any]]:
    return query_stages.merge_hits_with_limit(primary, secondary, limit=limit)


def _prepare_context_summaries(
    hits: List[Dict[str, Any]],
    *,
    max_docs: int,
    max_tokens: int,
) -> Tuple[List[Dict[str, Any]], List[int], int]:
    return query_stages.prepare_context(hits, max_docs=max_docs, max_tokens=max_tokens)


def _int_env(name: str, default: int) -> int:
    return query_stages.normalize_int_env(name, default)


def _normalize_title(value: Any) -> str:
    return query_stages.normalize_title(value)


def _normalize_url(value: Any) -> str:
    return query_stages.normalize_url(value)


def _dedupe_hits_by_title_or_url(hits: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    return query_stages.dedupe_hits(hits)


def _fold_text(value: Any) -> str:
    return query_stages.text_fold(value)


def _extract_query_terms_for_lexical(query: str) -> List[str]:
    return query_stages.sanitize_query_terms_for_lexical(query)


def _lexical_boost_hits(hits: List[Dict[str, Any]], query: str) -> List[Dict[str, Any]]:
    return query_stages.apply_lexical_boost(hits, query)


def _min_lexical_match_count(terms: List[str]) -> int:
    return query_stages.lexical_min_match_count(terms)


def _tokenize_words(text: str) -> List[str]:
    return query_stages.split_lexical_words(text)


def _levenshtein_with_limit(a: str, b: str, limit: int = 1) -> int:
    return query_stages.levenshtein_limit(a, b, limit=limit)


def _fuzzy_term_in_words(term: str, words: List[str]) -> bool:
    return query_stages.apply_fuzzy_term_match(term, words)


def _keyword_fallback_search(qdrant: QdrantHTTP, vec: List[float], query: str, top_k: int) -> List[Dict[str, Any]]:
    return query_stages.keyword_fallback(qdrant, vec, query, top_k)


def _is_multimodal_query(text: str) -> bool:
    return query_stages.is_multimodal_query(text)
