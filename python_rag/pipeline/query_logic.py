"""
Query pipeline extracted from rag_brain: search, graph expansion, rerank, context prep.
"""
from __future__ import annotations

import os
import time
import logging
import re
import unicodedata
from typing import Any, Dict, List, Optional, Tuple

from fastapi import HTTPException

from vectorstore.qdrant_http import QdrantHTTP
from utils.safety_utils import analyze_prompt, enforce_output_safety, sanitize_prompt_text
from utils.text_preprocessor import (
    _extract_terms,
    _normalize_list,
    _normalize_scores,
    _sanitize_context_snippet,
    _terms_from_payload,
    _truncate_to_tokens,
    _estimate_tokens,
    _rewrite_query,
)
from vectorstore.vector_search import run_search, run_high_recall
from graph.graph_utils import (
    fetch_related_terms,
    structural_limit,
    structural_hops,
    build_structural_hits,
)

MAX_CONTEXT_TOKENS_DEFAULT = 2800
ITERATIVE_RETRIEVAL_ENV = "RAG_ITERATIVE_RETRIEVAL"
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
    rewrite_enabled = (not body.fast_mode) and _is_multimodal_query(user_query)
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
    search_mult = max(1, int(os.environ.get("RAG_SEARCH_TOP_K_MULT", "3")))
    search_cap = max(10, int(os.environ.get("RAG_SEARCH_TOP_K_CAP", "50")))
    search_top_k = min(max(body.top_k, body.top_k * search_mult), search_cap)
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
    keyword_hits = _keyword_fallback_search(qdrant, vec, rewritten_query, search_top_k)
    if keyword_hits:
        text_fallback_used = True
        hits = _merge_hits(hits, keyword_hits, max(search_top_k * 2, len(hits) + len(keyword_hits)))
    timings["qdrant_ms"] = (time.perf_counter() - t_qdrant_start) * 1000
    logger.info("query:qdrant hits=%s ms=%.2f", len(hits), timings["qdrant_ms"])

    struct_hops = structural_hops()
    t_graph_start = time.perf_counter()
    structural_hits = [] if body.fast_mode or struct_hops == 0 else build_structural_hits(
        query_terms,
        limit=structural_limit(body.top_k),
        hops=struct_hops,
        include_rel_match=body.smart_lookup,
    )
    timings["graph_ms"] = (time.perf_counter() - t_graph_start) * 1000
    logger.info("query:graph hits=%s ms=%.2f", len(structural_hits), timings["graph_ms"])

    sem_weight = float(os.environ.get("RAG_FUSION_SEM_WEIGHT", "0.6"))
    str_weight = float(os.environ.get("RAG_FUSION_STR_WEIGHT", "0.4"))
    hits = _fuse_hits(hits, structural_hits, sem_weight=sem_weight, str_weight=str_weight)
    hits = [h for h in hits if (h.get("payload") or {}).get("component_type") in (None, "", "chunk")]
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
    min_score = float(os.environ.get("RAG_MIN_SCORE", "0.1"))
    hits_all = list(hits)
    hits_lex = _lexical_boost_hits(hits_all, rewritten_query)
    if hits_lex:
        hits = hits_lex
    else:
        hits = [h for h in hits_all if float(h.get("score") or 0.0) >= min_score]
        if not hits and hits_all:
            fallback_min = float(os.environ.get("RAG_MIN_SCORE_FALLBACK", "0.2"))
            hits = [h for h in hits_all if float(h.get("score") or 0.0) >= fallback_min]
            if not hits:
                hits = hits_all[: body.top_k]
    hits = _dedupe_hits_by_title_or_url(hits)
    logger.info("query:rerank hits=%s ms=%.2f", len(hits), timings.get("rerank_ms", 0.0))
    timings["rerank_ms"] = (time.perf_counter() - t_rerank_start) * 1000

    iterative_enabled = str(os.environ.get(ITERATIVE_RETRIEVAL_ENV, "true")).lower() in ("1", "true", "yes")
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
            hits_lex = _lexical_boost_hits(hits_all, rewritten_query)
            if hits_lex:
                hits = hits_lex
            else:
                hits = [h for h in hits_all if float(h.get("score") or 0.0) >= min_score]
                if not hits and hits_all:
                    fallback_min = float(os.environ.get("RAG_MIN_SCORE_FALLBACK", "0.2"))
                    hits = [h for h in hits_all if float(h.get("score") or 0.0) >= fallback_min]
                    if not hits:
                        hits = hits_all[: body.top_k]
            hits = _dedupe_hits_by_title_or_url(hits)

    max_context_tokens = _int_env("RAG_CONTEXT_TOKENS", MAX_CONTEXT_TOKENS_DEFAULT)
    max_context_docs = _int_env("RAG_CONTEXT_DOCS", 6)

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
    enable_generation = str(os.environ.get("RAG_GENERATE_ANSWER", "false")).lower() in ("1", "true", "yes")
    if enable_generation and context_summaries:
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
    if not hits:
        return True
    lowered = query.lower()
    connectors = any(word in lowered for word in ["first", "second", "then", "anschließend", "compare", "contrast", "schritt", "workflow"])
    scores = [float(h.get("score") or 0.0) for h in hits]
    max_score = max(scores) if scores else 0.0
    low_density = len(hits) < max(3, top_k)
    weak_scores = max_score < 0.42
    return connectors or low_density or weak_scores


def _collect_expansion_terms(hits: List[Dict[str, Any]], limit: int = 8) -> List[str]:
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


def _fuse_hits(sem_hits: List[Dict[str, Any]], struct_hits: List[Dict[str, Any]], *, sem_weight: float, str_weight: float) -> List[Dict[str, Any]]:
    by_id: Dict[str, Dict[str, Any]] = {}
    for h in sem_hits or []:
        doc_id = str((h.get("payload") or {}).get("doc_id") or h.get("id") or "")
        if not doc_id:
            continue
        h = dict(h)
        h["score"] = float(h.get("score") or 0.0) * sem_weight
        by_id[doc_id] = h
    for h in struct_hits or []:
        doc_id = str((h.get("payload") or {}).get("doc_id") or h.get("id") or "")
        if not doc_id:
            continue
        score = float(h.get("score") or 0.0) * str_weight
        if doc_id in by_id:
            by_id[doc_id]["score"] = (by_id[doc_id]["score"] or 0.0) + score
        else:
            h = dict(h)
            h["score"] = score
            by_id[doc_id] = h
    merged = list(by_id.values())
    merged.sort(key=lambda x: float(x.get("score") or 0.0), reverse=True)
    return merged


def _merge_hits(primary: List[Dict[str, Any]], secondary: List[Dict[str, Any]], limit: int) -> List[Dict[str, Any]]:
    by_id: Dict[str, Dict[str, Any]] = {}
    for h in primary or []:
        doc_id = str((h.get("payload") or {}).get("doc_id") or h.get("id") or "")
        if doc_id:
            by_id[doc_id] = h
    for h in secondary or []:
        doc_id = str((h.get("payload") or {}).get("doc_id") or h.get("id") or "")
        if not doc_id:
            continue
        if doc_id not in by_id:
            by_id[doc_id] = h
    merged = list(by_id.values())
    merged.sort(key=lambda x: float(x.get("score") or 0.0), reverse=True)
    return merged[:limit]


def _prepare_context_summaries(
    hits: List[Dict[str, Any]],
    *,
    max_docs: int,
    max_tokens: int,
) -> Tuple[List[Dict[str, Any]], List[int], int]:
    summaries: List[Dict[str, Any]] = []
    trimmed: List[int] = []
    used_tokens = 0

    for idx, hit in enumerate(hits[: max_docs], start=1):
        payload = hit.get("payload") or {}
        title_raw = payload.get("title") or payload.get("page_title") or "Untitled"
        url_raw = payload.get("page_url") or payload.get("source_url") or ""
        snippet_raw = (payload.get("snippet") or payload.get("content") or "")[:1200]
        component_type = payload.get("component_type") or payload.get("type") or "chunk"
        source_format = payload.get("source_format") or payload.get("format")

        title = _sanitize_context_snippet(title_raw) or "Untitled"
        url = _sanitize_context_snippet(url_raw)
        clean_snippet = _sanitize_context_snippet(snippet_raw)
        base_tokens = _estimate_tokens(title) + _estimate_tokens(url)

        remaining = max_tokens - used_tokens - base_tokens
        if remaining <= 0:
            trimmed.append(idx)
            continue

        snippet = _truncate_to_tokens(clean_snippet, remaining)
        if snippet != clean_snippet:
            trimmed.append(idx)
        if not snippet:
            snippet = "[Excerpt removed by content safety]"

        doc_tokens = base_tokens + _estimate_tokens(snippet)
        used_tokens += doc_tokens
        summaries.append({
            "idx": idx,
            "title": title,
            "url": url,
            "snippet": snippet,
            "component_type": component_type,
            "source_format": source_format,
        })
        if used_tokens >= max_tokens:
            break

    return summaries, trimmed, used_tokens


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except Exception:
        return default


def _normalize_title(value: Any) -> str:
    if not value:
        return ""
    return re.sub(r"\\s+", " ", str(value)).strip().lower()


def _normalize_url(value: Any) -> str:
    if isinstance(value, list):
        value = value[0] if value else ""
    if not value:
        return ""
    url = str(value).strip().lower()
    if not url:
        return ""
    url = re.sub(r"^https?://", "", url)
    url = re.sub(r"^www\\.", "", url)
    if "#" in url:
        url = url.split("#", 1)[0]
    return url.rstrip("/")


def _dedupe_hits_by_title_or_url(hits: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    if not hits:
        return hits
    seen_titles: set[str] = set()
    seen_urls: set[str] = set()
    deduped: List[Dict[str, Any]] = []
    for h in hits:
        payload = h.get("payload") or {}
        title = _normalize_title(payload.get("title"))
        page_url = _normalize_url(payload.get("page_url"))
        source_url = _normalize_url(payload.get("source_url"))
        url_key = page_url or source_url
        if title and title in seen_titles:
            continue
        if url_key and url_key in seen_urls:
            continue
        if title:
            seen_titles.add(title)
        if url_key:
            seen_urls.add(url_key)
        deduped.append(h)
    return deduped


def _fold_text(value: Any) -> str:
    text = str(value or "").lower()
    if not text:
        return ""
    text = text.replace("ß", "ss")
    # German digraph normalization for common umlaut spellings.
    text = text.replace("ae", "a").replace("oe", "o").replace("ue", "u")
    normalized = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def _extract_query_terms_for_lexical(query: str) -> List[str]:
    terms = _extract_terms(query)
    if not terms:
        parts = [p for p in re.split(r"[\\W_]+", query) if len(p) >= 3]
        terms = [p.lower() for p in parts]
    seen: set[str] = set()
    out: List[str] = []
    for t in terms:
        raw = str(t or "").strip().lower()
        if raw and raw not in seen:
            seen.add(raw)
            out.append(raw)
        folded = _fold_text(t)
        if folded and folded not in seen:
            seen.add(folded)
            out.append(folded)
    return out


def _lexical_boost_hits(hits: List[Dict[str, Any]], query: str) -> List[Dict[str, Any]]:
    if not hits:
        return hits
    terms = _extract_query_terms_for_lexical(query)
    if not terms:
        return hits
    min_required = _min_lexical_match_count(terms)
    boosted: List[Dict[str, Any]] = []
    for h in hits:
        payload = h.get("payload") or {}
        fields = [
            payload.get("content"),
            payload.get("snippet"),
            payload.get("title"),
            payload.get("page_url"),
            payload.get("source_url"),
        ]
        pdfs = payload.get("pdfs")
        if isinstance(pdfs, list):
            fields.extend([str(p) for p in pdfs if p])
        combined = _fold_text(" ".join(str(f) for f in fields if f))
        if not combined:
            continue
        words = _tokenize_words(combined)
        match_count = 0
        for t in terms:
            if t in combined:
                match_count += 1
            elif _fuzzy_term_in_words(t, words):
                match_count += 1
        if match_count < min_required:
            continue
        title = _fold_text(payload.get("title") or "")
        url = _fold_text(payload.get("page_url") or payload.get("source_url") or "")
        bonus = 0.03 * match_count
        if title and any(t in title for t in terms):
            bonus += 0.06
        if url and any(t in url for t in terms):
            bonus += 0.03
        h2 = dict(h)
        h2["score"] = float(h.get("score") or 0.0) + bonus
        boosted.append(h2)
    boosted.sort(key=lambda x: float(x.get("score") or 0.0), reverse=True)
    return boosted


def _min_lexical_match_count(terms: List[str]) -> int:
    count = len(terms)
    if count <= 1:
        return 1
    if count == 2:
        return 2
    if count == 3:
        return 2
    return max(2, int((count * 0.6) + 0.999))


def _tokenize_words(text: str) -> List[str]:
    if not text:
        return []
    return re.findall(r"[a-z0-9]{2,}", text)


def _levenshtein_with_limit(a: str, b: str, limit: int = 1) -> int:
    if a == b:
        return 0
    if abs(len(a) - len(b)) > limit:
        return limit + 1
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, start=1):
        curr = [i]
        min_row = curr[0]
        for j, cb in enumerate(b, start=1):
            cost = 0 if ca == cb else 1
            val = min(
                prev[j] + 1,
                curr[j - 1] + 1,
                prev[j - 1] + cost,
            )
            curr.append(val)
            if val < min_row:
                min_row = val
        if min_row > limit:
            return limit + 1
        prev = curr
    return prev[-1]


def _fuzzy_term_in_words(term: str, words: List[str]) -> bool:
    if term in words:
        return True
    if len(term) < 4:
        return False
    for w in words:
        if abs(len(w) - len(term)) > 1:
            continue
        if w[0] != term[0]:
            continue
        if _levenshtein_with_limit(term, w, 1) <= 1:
            return True
    return False


def _keyword_fallback_search(qdrant: QdrantHTTP, vec: List[float], query: str, top_k: int) -> List[Dict[str, Any]]:
    terms = _extract_query_terms_for_lexical(query)
    if not terms:
        return []
    fields = ["content", "title", "page_url", "source_url", "canonical_url", "tags", "pdfs"]
    fallback_limit = max(top_k * 4, 20)
    try:
        limit_env = int(os.environ.get("QDRANT_TEXT_SCROLL_LIMIT", "200"))
        if limit_env > 0:
            fallback_limit = min(fallback_limit, limit_env)
        else:
            fallback_limit = 0
    except Exception:
        fallback_limit = min(fallback_limit, 200)
    hits: List[Dict[str, Any]] = []
    try:
        hits = qdrant.search_with_text(vec, top_k=top_k, terms=terms, fields=fields)
    except Exception as exc:
        logger.warning("query:text-fallback search failed: %s", exc)
    scroll_hits: List[Dict[str, Any]] = []
    exhaustive = os.environ.get("RAG_EXHAUSTIVE_TEXT", "false").lower() in ("1", "true", "yes")
    try:
        if exhaustive:
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
        else:
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
    except Exception as exc:
        logger.warning("query:text-fallback scroll failed: %s", exc)
    if hits and scroll_hits:
        return _merge_hits(hits, scroll_hits, max(top_k * 2, len(hits) + len(scroll_hits)))
    return hits or scroll_hits


def _is_multimodal_query(text: str) -> bool:
    lowered = text.lower()
    return any(word in lowered for word in ["image", "bild", "diagram", "table", "chart", "photo", "fig"])
