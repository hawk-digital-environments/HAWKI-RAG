"""Composable query orchestration for query documents."""
from __future__ import annotations

import os
import logging
import time
from typing import Any, Callable, Dict, List, Tuple

from fastapi import HTTPException

from infrastructure.vectorstore.vector_search import run_high_recall, run_search
from infrastructure.vectorstore.qdrant_http import QdrantHTTP
from shared.safety_utils import analyze_prompt, enforce_output_safety, sanitize_prompt_text
from shared.text_preprocessor import _extract_terms, _terms_from_payload
from infrastructure.graph.graph_utils import fetch_related_terms, structural_hops, structural_limit, build_structural_hits
from application.use_cases.pipeline.query_settings import (
    context_limits,
    fusion_weights,
    generation_enabled,
    iterative_retrieval_enabled,
    score_thresholds,
    search_top_k as configured_search_top_k,
)

logger = logging.getLogger(__name__)

VectorSearch = Callable[..., list[dict[str, Any]]]


def _set_fast_mode_env(enabled: bool) -> None:
    """Set query fast mode flag in process environment (legacy contract)."""
    os.environ["RAG_FAST_MODE"] = "true" if enabled else "false"


def run_query_documents(
    body: Any,
    *,
    rag_service: Any,
    get_provider: Callable[[str], Any],
    qdrant_ctor: Callable[[], Any] = QdrantHTTP,
    analyze_prompt_fn: Callable[[str], dict[str, Any]] = analyze_prompt,
    enforce_output_safety_fn: Callable[[str], dict[str, Any]] = enforce_output_safety,
    sanitize_prompt_text_fn: Callable[[str], str] = sanitize_prompt_text,
    build_query_rewrite_fn: Callable[..., dict[str, Any]] = lambda provider, query, **kwargs: {},
    build_query_terms_fn: Callable[[str, list[str], list[str], list[str]], list[str]] = lambda *args: [],
    run_search_fn: VectorSearch = run_search,
    keyword_fallback_fn: VectorSearch = lambda *args, **kwargs: [],
    build_structural_hits_fn: Callable[..., list[dict[str, Any]]] = build_structural_hits,
    structural_hops_fn: Callable[[], int] = structural_hops,
    structural_limit_fn: Callable[[int], int] = structural_limit,
    fusion_weights_fn: Callable[[], tuple[float, float]] = fusion_weights,
    rerank_and_filter_hits_fn: Callable[..., list[dict[str, Any]]] = lambda hits, **kwargs: hits,
    should_iterate_fn: Callable[[str, list[dict[str, Any]], int], bool] = lambda q, h, k: False,
    collect_expansion_terms_fn: Callable[[list[dict[str, Any]], int], list[str]] = lambda hits, limit=8: [],
    merge_hits_fn: Callable[[list[dict[str, Any]], list[dict[str, Any]], int], list[dict[str, Any]]] = (
        lambda primary, secondary, limit: secondary
    ),
    build_fused_hits_fn: Callable[[list[dict[str, Any]], list[dict[str, Any]], float, float], list[dict[str, Any]]] = (
        lambda sem_hits, struct_hits, sem_weight=0.0, str_weight=0.0: sem_hits
    ),
    prepare_context_fn: Callable[[list[dict[str, Any]], Any, Any], tuple[list[dict[str, Any]], list[int], int]] = (
        lambda hits, max_docs, max_tokens: ([], [], 0)
    ),
    run_high_recall_fn: VectorSearch = run_high_recall,
    fetch_related_terms_fn: Callable[[list[str], int], list[dict[str, str]]] = fetch_related_terms,
    context_limits_fn: Callable[[], tuple[int, int]] = context_limits,
    score_thresholds_fn: Callable[[], tuple[float, float]] = score_thresholds,
    iterative_retrieval_enabled_fn: Callable[[], bool] = iterative_retrieval_enabled,
    generation_enabled_fn: Callable[[], bool] = generation_enabled,
    configured_search_top_k_fn: Callable[[int], int] = configured_search_top_k,
    extract_terms_fn: Callable[[str], list[str]] = _extract_terms,
    terms_from_payload_fn: Callable[[dict[str, Any]], list[str]] = _terms_from_payload,
    set_fast_mode_fn: Callable[[bool], None] = _set_fast_mode_env,
) -> dict[str, Any]:
    """Run the query orchestration used by `/query` with injectable collaborators."""
    timings: dict[str, float] = {}
    set_fast_mode_fn(body.fast_mode)
    prompt_safety = analyze_prompt_fn(body.query)
    if prompt_safety["blocked"]:
        detail = "Query blocked by content safety filters."
        if prompt_safety["issues"]:
            detail += f" Reasons: {', '.join(prompt_safety['issues'])}."
        raise HTTPException(status_code=400, detail=detail)

    user_query = prompt_safety["sanitized"]
    if not user_query.strip():
        raise HTTPException(status_code=400, detail="Query is empty after sanitization.")

    provider = get_provider(body.provider)
    qdrant = qdrant_ctor()
    logger.info(
        "query:start provider=%s top_k=%s fast=%s smart=%s optimized=%s",
        body.provider,
        body.top_k,
        body.fast_mode,
        body.smart_lookup,
        body.is_optimized,
    )

    t_rewrite_start = time.perf_counter()
    rewrite = build_query_rewrite_fn(
        provider,
        user_query,
        fast_mode=body.fast_mode,
    )
    rewrite_enabled = rewrite["enabled"]
    timings["rewrite_ms"] = (time.perf_counter() - t_rewrite_start) * 1000
    rewritten_query = sanitize_prompt_text_fn(rewrite.get("rewritten_query") or user_query)
    high_level_keys = rewrite["high_level_keys"]
    low_level_keys = rewrite["low_level_keys"]
    modality_hints = rewrite["modality_hints"]
    entity_terms = rewrite["entity_terms"]
    query_terms = build_query_terms_fn(rewritten_query, high_level_keys, low_level_keys, entity_terms)

    t_embed_start = time.perf_counter()
    try:
        vec = provider.embed(rewritten_query)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Embedding failed: {exc}") from exc
    timings["embed_ms"] = (time.perf_counter() - t_embed_start) * 1000

    filters = dict(body.filters) if body.filters else None
    t_qdrant_start = time.perf_counter()
    keyword_fields = ["title", "page_url", "source_url", "canonical_url", "tags", "content", "pdfs"]
    search_top_k = configured_search_top_k_fn(body.top_k)
    hits = run_search_fn(
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
    keyword_hits = keyword_fallback_fn(qdrant, vec, rewritten_query, search_top_k)
    if keyword_hits:
        hits = merge_hits_fn(hits, keyword_hits, max(search_top_k * 2, len(hits) + len(keyword_hits)))
    timings["qdrant_ms"] = (time.perf_counter() - t_qdrant_start) * 1000
    logger.info("query:qdrant hits=%s ms=%.2f", len(hits), timings["qdrant_ms"])

    struct_hops = body.structural_hops if getattr(body, "structural_hops", None) is not None else structural_hops_fn()
    t_graph_start = time.perf_counter()
    structural_hits = [] if body.fast_mode or struct_hops == 0 else build_structural_hits_fn(
        query_terms,
        limit=structural_limit_fn(body.top_k),
        hops=struct_hops,
        include_rel_match=body.smart_lookup,
    )
    timings["graph_ms"] = (time.perf_counter() - t_graph_start) * 1000
    logger.info("query:graph hits=%s ms=%.2f", len(structural_hits), timings["graph_ms"])

    sem_weight, str_weight = fusion_weights_fn()
    hits = build_fused_hits_fn(
        hits,
        structural_hits,
        sem_weight=sem_weight,
        str_weight=str_weight,
    )
    hits = [h for h in hits if (h.get("payload") or {}).get("component_type") in (None, "", "chunk", "relation")]

    t_rerank_start = time.perf_counter()
    min_score, fallback_min = score_thresholds_fn()
    hits = rerank_and_filter_hits_fn(
        hits,
        user_query=rewritten_query,
        provider=provider,
        query_vector=vec,
        rag_service=rag_service,
        mode=body.reranker,
        top_n=body.rerank_top_n,
        mix_mode=body.mix_mode,
        mix_weight=body.mix_weight,
        min_score=min_score,
        fallback_min=fallback_min,
        top_k=body.top_k,
    )
    timings["rerank_ms"] = (time.perf_counter() - t_rerank_start) * 1000
    logger.info("query:rerank hits=%s ms=%.2f", len(hits), timings["rerank_ms"])

    iterative_enabled = iterative_retrieval_enabled_fn()
    iteration_used = False
    expansion_terms: list[str] = []
    if iterative_enabled and should_iterate_fn(rewritten_query, hits, body.top_k):
        iteration_used = True
        expansion_terms = collect_expansion_terms_fn(hits)
        expanded_query = rewritten_query
        if expansion_terms:
            expanded_query = f"{rewritten_query}\nKey entities: {', '.join(expansion_terms[:6])}"
        try:
            iter_vec = provider.embed(expanded_query) if expansion_terms else vec
        except Exception:
            iter_vec = vec

        secondary_hits = run_high_recall_fn(
            qdrant=qdrant,
            vec=iter_vec,
            top_k=max(body.top_k * 2, len(hits) or body.top_k),
            filters=filters,
            preferred_tags=body.preferred_tags,
        )
        if secondary_hits:
            hits = merge_hits_fn(hits, secondary_hits, max(body.top_k * 2, 12))
            hits = rerank_and_filter_hits_fn(
                hits,
                user_query=rewritten_query,
                provider=provider,
                query_vector=vec,
                rag_service=rag_service,
                mode=body.reranker,
                top_n=body.rerank_top_n,
                mix_mode=body.mix_mode,
                mix_weight=body.mix_weight,
                min_score=min_score,
                fallback_min=fallback_min,
                top_k=body.top_k,
            )

    max_context_tokens, max_context_docs = context_limits_fn()

    final_hit_limit = max(body.top_k, max_context_docs)
    if len(hits) > final_hit_limit:
        hits = hits[:final_hit_limit]

    context_summaries, trimmed_sources, context_tokens_used = prepare_context_fn(
        hits,
        max_docs=max_context_docs,
        max_tokens=max_context_tokens,
    )

    kg_facts: list[dict[str, str]] = []
    t_kg_start = time.perf_counter()
    if hits and not body.fast_mode:
        kg_terms = set(extract_terms_fn(rewritten_query))
        kg_terms.update(query_terms)
        for h in hits[: body.top_k]:
            payload = h.get("payload") or {}
            kg_terms.update(terms_from_payload_fn(payload))
            content_sample = (payload.get("content") or "")[:160]
            kg_terms.update(extract_terms_fn(content_sample))
        limited_terms = list(kg_terms)[:30]
        if limited_terms:
            kg_facts = fetch_related_terms_fn(limited_terms, limit=30)
    logger.info("query:kg facts=%s ms=%.2f", len(kg_facts), timings.get("kg_ms", 0.0))
    timings["kg_ms"] = (time.perf_counter() - t_kg_start) * 1000

    answer = ""
    output_safety = {"blocked": False, "issues": [], "answer": ""}
    if generation_enabled_fn() and context_summaries:
        output_safety = enforce_output_safety_fn(answer)
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
                "query": rewritten_query if rewritten_query != user_query else None,
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
