"""Query pipeline facade."""

from __future__ import annotations

from typing import Any

from hawki_rag_stores.qdrant.client import QdrantHTTP
from hawki_bridge.adapters.qdrant_reader import run_high_recall, run_search
from hawki_rag_stores.neo4j.traversal import (
    build_structural_hits,
    fetch_related_terms,
    structural_hops,
    structural_limit,
)
from hawki_bridge.application.query import stages as query_stages
from hawki_bridge.application.query.execution import run_query_documents
from hawki_bridge.application.query.settings import (
    context_limits,
    fusion_weights,
    generation_enabled,
    iterative_retrieval_enabled,
    score_thresholds,
    search_top_k as configured_search_top_k,
)
from hawki_rag_text.safety import (
    analyze_prompt,
    enforce_output_safety,
    sanitize_prompt_text,
)
from hawki_rag_text.preprocessing import _extract_terms, _terms_from_payload


def query_documents(body: Any, *, rag_service: Any, get_provider) -> dict[str, Any]:
    """Run the full query pipeline using injectable collaborators."""
    return run_query_documents(
        body,
        rag_service=rag_service,
        get_provider=get_provider,
        qdrant_ctor=QdrantHTTP,
        analyze_prompt_fn=analyze_prompt,
        enforce_output_safety_fn=enforce_output_safety,
        sanitize_prompt_text_fn=sanitize_prompt_text,
        build_query_rewrite_fn=query_stages.build_query_rewrite,
        build_query_terms_fn=query_stages.build_query_terms,
        run_search_fn=run_search,
        keyword_fallback_fn=_keyword_fallback_search,
        build_structural_hits_fn=build_structural_hits,
        structural_hops_fn=structural_hops,
        structural_limit_fn=structural_limit,
        fusion_weights_fn=fusion_weights,
        rerank_and_filter_hits_fn=query_stages.rerank_and_filter_hits,
        should_iterate_fn=_should_iterate,
        collect_expansion_terms_fn=_collect_expansion_terms,
        merge_hits_fn=_merge_hits,
        build_fused_hits_fn=_fuse_hits,
        prepare_context_fn=_prepare_context_summaries,
        run_high_recall_fn=run_high_recall,
        fetch_related_terms_fn=fetch_related_terms,
        context_limits_fn=context_limits,
        score_thresholds_fn=score_thresholds,
        iterative_retrieval_enabled_fn=iterative_retrieval_enabled,
        generation_enabled_fn=generation_enabled,
        configured_search_top_k_fn=configured_search_top_k,
        extract_terms_fn=_extract_terms,
        terms_from_payload_fn=_terms_from_payload,
    )


def _should_iterate(query: str, hits: list[dict[str, Any]], top_k: int) -> bool:
    return query_stages.should_iterate(query, hits, top_k)


def _collect_expansion_terms(hits: list[dict[str, Any]], limit: int = 8) -> list[str]:
    return query_stages.collect_expansion_terms(hits, limit=limit)


def _fuse_hits(
    sem_hits: list[dict[str, Any]],
    struct_hits: list[dict[str, Any]],
    *,
    sem_weight: float,
    str_weight: float,
) -> list[dict[str, Any]]:
    return query_stages.build_fused_hits(
        sem_hits, struct_hits, sem_weight=sem_weight, str_weight=str_weight
    )


def _merge_hits(
    primary: list[dict[str, Any]], secondary: list[dict[str, Any]], limit: int
) -> list[dict[str, Any]]:
    return query_stages.merge_hits_with_limit(primary, secondary, limit=limit)


def _prepare_context_summaries(
    hits: list[dict[str, Any]],
    *,
    max_docs: int,
    max_tokens: int,
) -> tuple[list[dict[str, Any]], list[int], int]:
    return query_stages.prepare_context(hits, max_docs=max_docs, max_tokens=max_tokens)


def _keyword_fallback_search(
    qdrant: QdrantHTTP,
    vec: list[float],
    query: str,
    top_k: int,
    *,
    filters: dict[str, Any] | None = None,
) -> list[dict[str, Any]]:
    return query_stages.keyword_fallback(qdrant, vec, query, top_k, filters=filters)
