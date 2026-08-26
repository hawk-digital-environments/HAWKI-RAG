"""Typed application use case for authorized document queries."""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Any

from hawki_model_providers.overrides import apply_provider_overrides
from hawki_rag_contracts.query import QueryRequest, QueryResponse
from hawki_rag_text.safety import (
    analyze_prompt,
    enforce_output_safety,
    sanitize_prompt_text,
)
from hawki_rag_text.terms import extract_terms

from hawki_bridge.application.dependencies import QueryDependencies
from hawki_bridge.application.query.context import (
    build_grounded_answer_prompt,
    prepare_context_summaries,
)
from hawki_bridge.application.query.fallback import keyword_fallback_search
from hawki_bridge.application.query.hits import fuse_hits, merge_hits
from hawki_bridge.application.query.ranking import (
    collect_expansion_terms,
    filter_hits_by_score,
    rerank_and_filter_hits,
    should_iterate,
)
from hawki_bridge.application.query.rewrite import (
    build_query_rewrite,
    build_query_terms,
)
from hawki_bridge.application.query.scope import build_scoped_query_filters
from hawki_bridge.application.query.settings import (
    context_limits,
    fusion_weights,
    generation_enabled,
    iterative_retrieval_enabled,
    score_thresholds,
    search_top_k,
    structural_hops,
    structural_limit,
)
from hawki_bridge.domain.errors import (
    AnswerGenerationError,
    EmbeddingGenerationError,
    InvalidQueryError,
    UnsupportedModelProviderError,
)
from hawki_bridge.domain.ports import ModelProvider, VectorSearchPort

logger = logging.getLogger(__name__)

_KEYWORD_FIELDS = [
    "title",
    "page_url",
    "source_url",
    "canonical_url",
    "tags",
    "content",
    "pdfs",
]


@dataclass(frozen=True, slots=True)
class QueryRuntime:
    """Request-scoped provider, storage client, and authorized filter state."""

    provider: ModelProvider
    vector_search: VectorSearchPort
    user_query: str
    filters: dict[str, Any]


@dataclass(frozen=True, slots=True)
class PreparedQuery:
    """Rewritten query values and embedding shared by retrieval stages."""

    text: str
    vector: list[float]
    terms: list[str]
    rewrite: dict[str, Any]


@dataclass(frozen=True, slots=True)
class RankedEvidence:
    """Ranked hits plus iterative-retrieval telemetry."""

    hits: list[dict[str, Any]]
    iterative_pass: bool
    expansion_terms: list[str]


def execute_authorized_query(
    request: QueryRequest,
    *,
    dependencies: QueryDependencies,
) -> QueryResponse:
    """Execute the bridge query workflow and return its stable contract.

    1. Validate and sanitize the query, then bind the authorized provider and
       vector collection.
    2. Optionally rewrite the query, create its embedding, and retrieve scoped
       semantic, lexical, and structural candidates.
    3. Fuse, rerank, and optionally expand weak retrieval results.
    4. Build bounded evidence, load related graph facts, and optionally produce
       a grounded answer.
    """

    timings: dict[str, float] = {}
    runtime = _initialize_query_runtime(request, dependencies)
    prepared = _rewrite_and_embed(request, runtime, timings)
    initial_hits = _retrieve_initial_evidence(
        request,
        runtime,
        prepared,
        dependencies,
        timings,
    )
    ranked = _rank_and_expand_evidence(
        request,
        runtime,
        prepared,
        initial_hits,
        dependencies,
        timings,
    )

    max_context_tokens, max_context_docs = context_limits()
    final_hits = ranked.hits[: max(request.top_k, max_context_docs)]
    context_summaries, trimmed_sources, context_tokens_used = prepare_context_summaries(
        final_hits,
        max_docs=max_context_docs,
        max_tokens=max_context_tokens,
    )
    graph_facts = _load_related_graph_facts(
        request,
        prepared,
        final_hits,
        dependencies,
        timings,
    )
    answer = _generate_grounded_answer(
        request,
        runtime.provider,
        prepared.text,
        context_summaries,
        graph_facts,
        timings,
    )

    return QueryResponse.model_validate(
        {
            "ok": True,
            "count": len(final_hits),
            "hits": final_hits,
            "kg": graph_facts,
            "answer": answer,
            "retrieval": {
                "dataset_id": request.authorized_scope.dataset_id,
                "graph_enabled": request.authorized_scope.graph_enabled,
                "graph_disabled_reason": None
                if request.authorized_scope.graph_enabled
                else "dataset_scope_not_enforced",
                "iterative_pass": ranked.iterative_pass,
                "expansion_terms": ranked.expansion_terms,
                "context_tokens_used": context_tokens_used,
                "context_docs": len(context_summaries),
                "context_trimmed": trimmed_sources,
                "max_context_tokens": max_context_tokens,
                "rewrite": {
                    "query": prepared.text
                    if prepared.text != runtime.user_query
                    else None,
                    "high_level_keys": prepared.rewrite["high_level_keys"],
                    "low_level_keys": prepared.rewrite["low_level_keys"],
                    "entity_terms": prepared.rewrite["entity_terms"],
                    "modality_hints": prepared.rewrite["modality_hints"],
                    "enabled": prepared.rewrite["enabled"],
                },
                "timings_ms": {
                    "rewrite": timings.get("rewrite_ms"),
                    "embed": timings.get("embed_ms"),
                    "qdrant": timings.get("qdrant_ms"),
                    "graph": timings.get("graph_ms"),
                    "rerank": timings.get("rerank_ms"),
                    "kg": timings.get("kg_ms"),
                    "generation": timings.get("generation_ms"),
                },
            },
        }
    )


def _initialize_query_runtime(
    request: QueryRequest,
    dependencies: QueryDependencies,
) -> QueryRuntime:
    prompt_safety = analyze_prompt(request.query)
    if prompt_safety["blocked"]:
        message = "Query blocked by content safety filters."
        if prompt_safety["issues"]:
            message += f" Reasons: {', '.join(prompt_safety['issues'])}."
        raise InvalidQueryError(message)

    user_query = str(prompt_safety["sanitized"])
    if not user_query.strip():
        raise InvalidQueryError("Query is empty after sanitization.")

    try:
        provider = dependencies.resolve_model_provider(request.provider)
    except ValueError as exc:
        raise UnsupportedModelProviderError(str(exc)) from exc
    apply_provider_overrides(provider, request)

    vector_search = dependencies.vector_search_factory()
    vector_search.select_scoped_collection(request.authorized_scope.qdrant_collection)
    filters = build_scoped_query_filters(
        request.authorized_scope.dataset_id,
        request.filters,
    )
    logger.info(
        "query:start provider=%s dataset_id=%s top_k=%s fast=%s smart=%s optimized=%s",
        request.provider,
        request.authorized_scope.dataset_id,
        request.top_k,
        request.fast_mode,
        request.smart_lookup,
        request.is_optimized,
    )
    return QueryRuntime(provider, vector_search, user_query, filters)


def _rewrite_and_embed(
    request: QueryRequest,
    runtime: QueryRuntime,
    timings: dict[str, float],
) -> PreparedQuery:
    started = time.perf_counter()
    rewrite = build_query_rewrite(
        runtime.provider,
        runtime.user_query,
        fast_mode=request.fast_mode,
    )
    timings["rewrite_ms"] = (time.perf_counter() - started) * 1000

    rewritten_query = sanitize_prompt_text(
        str(rewrite.get("rewritten_query") or runtime.user_query)
    )
    query_terms = build_query_terms(
        rewritten_query,
        rewrite["high_level_keys"],
        rewrite["low_level_keys"],
        rewrite["entity_terms"],
    )

    started = time.perf_counter()
    try:
        vector = runtime.provider.embed(rewritten_query)
    except Exception as exc:
        logger.exception("query:embedding failed")
        raise EmbeddingGenerationError("Embedding generation failed.") from exc
    timings["embed_ms"] = (time.perf_counter() - started) * 1000
    return PreparedQuery(rewritten_query, vector, query_terms, rewrite)


def _retrieve_initial_evidence(
    request: QueryRequest,
    runtime: QueryRuntime,
    prepared: PreparedQuery,
    dependencies: QueryDependencies,
    timings: dict[str, float],
) -> list[dict[str, Any]]:
    candidate_limit = search_top_k(request.top_k)
    started = time.perf_counter()
    hits = runtime.vector_search.search_candidates(
        vector=prepared.vector,
        top_k=candidate_limit,
        filters=runtime.filters,
        query_terms=prepared.terms,
        keyword_fields=_KEYWORD_FIELDS,
        smart_lookup=request.smart_lookup,
        fast_mode=request.fast_mode,
        is_optimized=request.is_optimized,
        preferred_tags=request.preferred_tags,
    )
    keyword_hits = keyword_fallback_search(
        runtime.vector_search,
        prepared.vector,
        prepared.text,
        candidate_limit,
        filters=runtime.filters,
    )
    if keyword_hits:
        hits = merge_hits(
            hits,
            keyword_hits,
            max(candidate_limit * 2, len(hits) + len(keyword_hits)),
        )
    timings["qdrant_ms"] = (time.perf_counter() - started) * 1000
    logger.info("query:qdrant hits=%s ms=%.2f", len(hits), timings["qdrant_ms"])

    hops = request.structural_hops
    if hops is None:
        hops = structural_hops()
    started = time.perf_counter()
    structural_hits = (
        []
        if not request.authorized_scope.graph_enabled or request.fast_mode or hops == 0
        else dependencies.graph_search.build_structural_hits(
            prepared.terms,
            dataset_id=request.authorized_scope.dataset_id,
            neo4j_namespace=str(request.authorized_scope.neo4j_namespace),
            limit=structural_limit(request.top_k),
            hops=hops,
            include_rel_match=request.smart_lookup,
        )
    )
    timings["graph_ms"] = (time.perf_counter() - started) * 1000
    logger.info(
        "query:graph hits=%s ms=%.2f", len(structural_hits), timings["graph_ms"]
    )

    semantic_weight, graph_weight = fusion_weights()
    fused = fuse_hits(
        hits,
        structural_hits,
        sem_weight=semantic_weight,
        str_weight=graph_weight,
    )
    return [
        hit
        for hit in fused
        if (hit.get("payload") or {}).get("component_type")
        in (None, "", "chunk", "relation")
    ]


def _rank_and_expand_evidence(
    request: QueryRequest,
    runtime: QueryRuntime,
    prepared: PreparedQuery,
    hits: list[dict[str, Any]],
    dependencies: QueryDependencies,
    timings: dict[str, float],
) -> RankedEvidence:
    minimum_score, fallback_minimum = score_thresholds()
    started = time.perf_counter()
    ranked_hits = rerank_and_filter_hits(
        hits,
        user_query=prepared.text,
        provider=runtime.provider,
        query_vector=prepared.vector,
        rerank_hits=dependencies.rerank_hits,
        mode=request.reranker,
        top_n=request.rerank_top_n,
        mix_mode=request.mix_mode,
        mix_weight=request.mix_weight,
        min_score=minimum_score,
        fallback_min=fallback_minimum,
        top_k=request.top_k,
        filter_hits=filter_hits_by_score,
    )
    timings["rerank_ms"] = (time.perf_counter() - started) * 1000
    logger.info("query:rerank hits=%s ms=%.2f", len(ranked_hits), timings["rerank_ms"])

    if not iterative_retrieval_enabled() or not should_iterate(
        prepared.text, ranked_hits, request.top_k
    ):
        return RankedEvidence(ranked_hits, False, [])

    expansion_terms = collect_expansion_terms(ranked_hits)
    expanded_query = prepared.text
    if expansion_terms:
        expanded_query = (
            f"{prepared.text}\nKey entities: {', '.join(expansion_terms[:6])}"
        )
    try:
        expanded_vector = (
            runtime.provider.embed(expanded_query)
            if expansion_terms
            else prepared.vector
        )
    except Exception:
        expanded_vector = prepared.vector

    secondary_hits = runtime.vector_search.search_high_recall(
        vector=expanded_vector,
        top_k=max(request.top_k * 2, len(ranked_hits) or request.top_k),
        filters=runtime.filters,
        preferred_tags=request.preferred_tags,
    )
    if secondary_hits:
        ranked_hits = merge_hits(
            ranked_hits,
            secondary_hits,
            max(request.top_k * 2, 12),
        )
        ranked_hits = rerank_and_filter_hits(
            ranked_hits,
            user_query=prepared.text,
            provider=runtime.provider,
            query_vector=prepared.vector,
            rerank_hits=dependencies.rerank_hits,
            mode=request.reranker,
            top_n=request.rerank_top_n,
            mix_mode=request.mix_mode,
            mix_weight=request.mix_weight,
            min_score=minimum_score,
            fallback_min=fallback_minimum,
            top_k=request.top_k,
            filter_hits=filter_hits_by_score,
        )
    return RankedEvidence(ranked_hits, True, expansion_terms)


def _load_related_graph_facts(
    request: QueryRequest,
    prepared: PreparedQuery,
    hits: list[dict[str, Any]],
    dependencies: QueryDependencies,
    timings: dict[str, float],
) -> list[dict[str, str]]:
    started = time.perf_counter()
    facts: list[dict[str, str]] = []
    if request.authorized_scope.graph_enabled and hits and not request.fast_mode:
        terms: list[str] = []
        seen: set[str] = set()
        _extend_unique_terms(terms, seen, extract_terms(prepared.text))
        _extend_unique_terms(terms, seen, prepared.terms)
        for hit in hits[: request.top_k]:
            payload = hit.get("payload") or {}
            _extend_unique_terms(terms, seen, _terms_from_payload(payload))
            _extend_unique_terms(
                terms,
                seen,
                extract_terms(str(payload.get("content") or "")[:160]),
            )
        if terms:
            facts = dependencies.graph_search.fetch_related_terms(
                terms[:30],
                dataset_id=request.authorized_scope.dataset_id,
                neo4j_namespace=str(request.authorized_scope.neo4j_namespace),
                limit=30,
            )
    timings["kg_ms"] = (time.perf_counter() - started) * 1000
    logger.info("query:kg facts=%s ms=%.2f", len(facts), timings["kg_ms"])
    return facts


def _generate_grounded_answer(
    request: QueryRequest,
    provider: ModelProvider,
    query: str,
    context_summaries: list[dict[str, Any]],
    graph_facts: list[dict[str, str]],
    timings: dict[str, float],
) -> str:
    started = time.perf_counter()
    answer = ""
    if request.generate and generation_enabled() and context_summaries:
        system_prompt, user_prompt = build_grounded_answer_prompt(
            query,
            context_summaries,
            graph_facts,
        )
        try:
            generated = provider.chat(
                system_prompt,
                [{"role": "user", "content": user_prompt}],
                temperature=0.0,
            )
        except Exception as exc:
            logger.exception("query:generation failed")
            raise AnswerGenerationError("Answer generation failed.") from exc
        answer = str(enforce_output_safety(str(generated or ""))["answer"])
    timings["generation_ms"] = (time.perf_counter() - started) * 1000
    return answer


def _terms_from_payload(payload: dict[str, Any]) -> list[str]:
    terms: list[str] = []
    tags = payload.get("tags")
    if isinstance(tags, str):
        terms.extend(extract_terms(tags))
    elif isinstance(tags, list):
        for tag in tags:
            terms.extend(extract_terms(str(tag)))
    for key in ("title", "page_url", "source_url"):
        terms.extend(extract_terms(str(payload.get(key) or "")))
    return terms


def _extend_unique_terms(
    target: list[str],
    seen: set[str],
    candidates: list[str],
) -> None:
    for candidate in candidates:
        term = str(candidate or "").strip()
        if not term or term in seen:
            continue
        seen.add(term)
        target.append(term)


__all__ = ["execute_authorized_query"]
