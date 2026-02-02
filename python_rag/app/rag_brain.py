########################################### libs and bibz #####################################
import json
import logging
import math
import os
import re
import time
from datetime import datetime
from typing import Any, Dict, List, Optional, Tuple

from fastapi import HTTPException

from graph.neo4j_graph import Neo4jGraph
from vectorstore.qdrant_http import QdrantHTTP
from vectorstore.qdrant_strategies import (
    semantic_search_basic,
    semantic_search_high_recall,
    optimized_semantic_search,
    semantic_search_smart,
)

from .text_preprocessor import (
    _analyze_prompt_safety,
    _enforce_output_safety,
    _extract_terms,
    _is_multimodal_query,
    _normalize_list,
    _normalize_scores,
    _sanitize_context_snippet,
    _sanitize_prompt_text,
    _strip_control_chars,
    _terms_from_payload,
    _truncate_to_tokens,
    _estimate_tokens,
    ensure_tags,
    split_text,
    _rewrite_query,
)

logger = logging.getLogger(__name__)

MAX_CONTEXT_TOKENS_DEFAULT = 2800
ITERATIVE_RETRIEVAL_ENV = "RAG_ITERATIVE_RETRIEVAL"


def _should_iterate(query: str, hits: List[Dict[str, Any]], top_k: int) -> bool:
    """Decide whether a second retrieval pass is warranted."""
    if not hits:
        return True
    lowered = query.lower()
    connectors = re.search(r"\b(first|second|then|anschließend|compare|contrast|schritt|workflow)\b", lowered)
    scores = [float(h.get("score") or 0.0) for h in hits]
    max_score = max(scores) if scores else 0.0
    low_density = len(hits) < max(3, top_k)
    weak_scores = max_score < 0.42
    return bool(connectors) or low_density or weak_scores


def _collect_expansion_terms(hits: List[Dict[str, Any]], limit: int = 8) -> List[str]:
    seen: set[str] = set()
    terms: List[str] = []
    for h in hits:
        payload = h.get("payload") or {}
        tags = payload.get("tags") or []
        if isinstance(tags, str):
            tags = [tags]
        for tag in tags:
            cleaned = _sanitize_prompt_text(tag)
            if not cleaned:
                continue
            cleaned = cleaned.lower()
            if cleaned in seen:
                continue
            seen.add(cleaned)
            terms.append(cleaned)
            if len(terms) >= limit:
                return terms
        title = payload.get("title") or payload.get("page_title")
        if title:
            for term in _extract_terms(title):
                if term not in seen:
                    seen.add(term)
                    terms.append(term)
                    if len(terms) >= limit:
                        return terms
    return terms


def _merge_hits(primary: List[Dict[str, Any]], secondary: List[Dict[str, Any]], limit: int) -> List[Dict[str, Any]]:
    merged: List[Dict[str, Any]] = []
    seen_ids: set[str] = set()

    def _key(hit: Dict[str, Any]) -> str:
        payload = hit.get("payload") or {}
        return str(hit.get("id") or payload.get("page_url") or payload.get("source_url") or payload.get("title") or id(hit))

    for collection in (primary, secondary):
        for hit in collection:
            key = _key(hit)
            if key in seen_ids:
                continue
            seen_ids.add(key)
            merged.append(hit)
            if len(merged) >= limit:
                return merged
    return merged


def _build_structural_hits(
    terms: List[str],
    *,
    limit: int,
    hops: int,
    include_rel_match: bool = False,
) -> List[Dict[str, Any]]:
    if not terms:
        return []
    g = Neo4jGraph()
    try:
        rows = g.search_structural(terms, limit=limit, hops=hops, include_rel_match=include_rel_match)
    except Exception:
        rows = []
    finally:
        try:
            g.close()
        except Exception:
            pass

    hits: List[Dict[str, Any]] = []
    for row in rows:
        s = row.get("subject") or ""
        r = row.get("relation") or ""
        o = row.get("object") or ""
        hops_used = int(row.get("hops") or 1)
        doc_id = row.get("doc_id")
        content = f"{s} -{r}-> {o}".strip(" -")
        score = 1.0 / max(1, hops_used)
        hits.append(
            {
                "id": f"neo4j:{s}:{r}:{o}:{doc_id or ''}",
                "score": score,
                "payload": {
                    "component_type": "relation",
                    "subject": s,
                    "relation": r,
                    "object": o,
                    "doc_id": doc_id,
                    "content": content,
                    "title": "Graph relation",
                },
                "source": "neo4j",
            }
        )
    return hits


def _hit_key(hit: Dict[str, Any]) -> str:
    payload = hit.get("payload") or {}
    doc_id = payload.get("doc_id") or ""
    component = payload.get("component_type") or ""
    content = payload.get("content") or payload.get("title") or hit.get("id") or ""
    return f"{doc_id}|{component}|{content}".strip()


def _fuse_hits(
    semantic_hits: List[Dict[str, Any]],
    structural_hits: List[Dict[str, Any]],
    *,
    sem_weight: float,
    str_weight: float,
) -> List[Dict[str, Any]]:
    merged: Dict[str, Dict[str, Any]] = {}

    sem_scores = _normalize_scores([float(h.get("score") or 0.0) for h in semantic_hits])
    for hit, norm_score in zip(semantic_hits, sem_scores):
        key = _hit_key(hit)
        fused = dict(hit)
        fused["score"] = max(0.0, min(1.0, sem_weight * norm_score))
        fused.setdefault("signals", {})["semantic"] = norm_score
        merged[key] = fused

    for hit in structural_hits:
        key = _hit_key(hit)
        base = max(0.0, min(1.0, float(hit.get("score") or 0.0)))
        weighted = str_weight * base
        if key in merged:
            merged_hit = merged[key]
            merged_hit["score"] = float(merged_hit.get("score") or 0.0) + weighted
            merged_hit.setdefault("signals", {})["structural"] = base
        else:
            fused = dict(hit)
            fused["score"] = weighted
            fused.setdefault("signals", {})["structural"] = base
            merged[key] = fused

    return sorted(merged.values(), key=lambda h: float(h.get("score") or 0.0), reverse=True)


def _prepare_context_summaries(
    hits: List[Dict[str, Any]], *, max_docs: int, max_tokens: int
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


def _delete_document_entries(doc_id: str) -> Dict[str, Any]:
    qdrant = QdrantHTTP()
    qdrant_result = qdrant.delete_by_doc_id(doc_id)
    graph = Neo4jGraph()
    try:
        neo4j_result = graph.delete_by_doc_id(doc_id)
    finally:
        try:
            graph.close()
        except Exception:
            pass
    return {"qdrant": qdrant_result, "neo4j": neo4j_result}


def ingest_documents(
    body: Any,
    *,
    rag_service: Any,
    get_provider,
    public_dir,
) -> Dict[str, Any]:
    dry_run = bool(body.dry_run)

    qdrant = QdrantHTTP()
    if body.collection:
        qdrant.collection = body.collection

    chunk_records: List[Dict[str, Any]] = []
    doc_stats: Dict[str, Any] = {
        "total_docs": len(body.docs),
        "processed_docs": 0,
        "skipped_docs": 0,
        "by_format": {},
        "doc_ids": [],
    }

    for d in body.docs:
        chunks = split_text(d.text, body.chunk_chars, body.chunk_overlap) or [d.text]
        doc_processed = False
        fmt: Optional[str] = None
        chunk_count = 0

        for idx, ch in enumerate(chunks):
            if not isinstance(ch, str) or not ch.strip():
                continue
            payload = dict(d.payload)
            payload.update({
                "content": ch,
                "chunk_index": idx,
                "source_format": payload.get("source_format", "text"),
            })
            payload["doc_id"] = str(d.id)
            payload.setdefault("component_type", "chunk")
            ensure_tags(payload, ch)
            chunk_records.append({
                "doc_id": str(d.id),
                "content": ch,
                "payload": payload,
            })
            doc_processed = True
            chunk_count += 1
            if not fmt:
                fmt = payload.get("source_format") or "unknown"

        if doc_processed:
            doc_stats["processed_docs"] += 1
            doc_stats["doc_ids"].append(str(d.id))
            fmt_key = fmt or "unknown"
            by_fmt = doc_stats["by_format"]
            by_fmt[fmt_key] = by_fmt.get(fmt_key, 0) + 1
            if chunk_count:
                chunks_map = doc_stats.setdefault("chunks_per_doc", {})
                chunks_map[str(d.id)] = chunk_count
        else:
            doc_stats["skipped_docs"] += 1

    total_chunks = len(chunk_records)
    doc_stats["total_chunks"] = total_chunks

    if total_chunks == 0:
        raise HTTPException(400, detail="No valid content to ingest")

    batch_size = 64

    if dry_run:
        summary: Dict[str, Any] = {
            "timestamp": datetime.utcnow().isoformat() + "Z",
            "dry_run": True,
            "planned_points": total_chunks,
            "documents": doc_stats,
        }
        summary["qdrant_preview"] = {
            "collection": qdrant.collection,
            "batch_size": batch_size,
            "planned_batches": math.ceil(total_chunks / batch_size),
            "planned_points": total_chunks,
        }

        if body.graph and body.dry_include_graph:
            texts = [rec["content"] for rec in chunk_records]
            joined = "\n\n".join(texts)
            if joined:
                triplets = rag_service.extract_triplets(joined, body.graph_engine)
                entity_ids = set()
                relation_counts: Dict[str, int] = {}
                for s, r, o in triplets:
                    if isinstance(s, str) and s.strip():
                        entity_ids.add(s.strip())
                    if isinstance(o, str) and o.strip():
                        entity_ids.add(o.strip())
                    rel = (r or "").strip() if isinstance(r, str) else str(r or "").strip()
                    if rel:
                        relation_counts[rel] = relation_counts.get(rel, 0) + 1
                summary["graph_preview"] = {
                    "planned_triplets": len(triplets),
                    "planned_entities": len(entity_ids),
                    "relation_counts": relation_counts,
                }
        elif body.graph:
            summary["graph_preview_skipped"] = "Set dry_include_graph=true to estimate Neo4j impact during dry run."

        preview = json.dumps(summary, indent=2, ensure_ascii=False)
        print(preview)
        logger.info("Dry-run ingest summary:\n%s", preview)

        try:
            public_dir.mkdir(parents=True, exist_ok=True)
            summary_path = public_dir / "ingest_summary.json"
            summary_path.write_text(preview + "\n", encoding="utf-8")
            summary["summary_file"] = str(summary_path)
        except Exception as exc:
            summary["summary_file_error"] = str(exc)

        return {"ok": True, "dry_run": True, "points": total_chunks, "summary": summary}

    graph_triplets_by_doc: Dict[str, List[tuple[str, str, str]]] = {}
    entity_records: List[Dict[str, Any]] = []
    relation_records: List[Dict[str, Any]] = []
    if body.graph:
        entity_seen: set[tuple[str, str]] = set()
        relation_seen: set[tuple[str, str]] = set()
        for record in chunk_records:
            text = record.get("content") or ""
            if not text.strip():
                continue
            doc_id = str(record.get("doc_id") or "")
            if not doc_id:
                continue
            triplets = rag_service.extract_triplets(text, body.graph_engine)
            if not triplets:
                continue
            graph_triplets_by_doc.setdefault(doc_id, []).extend(triplets)
            for s, r, o in triplets:
                s_txt = str(s).strip()
                o_txt = str(o).strip()
                r_txt = str(r).strip()
                if s_txt:
                    key = (doc_id, s_txt.lower())
                    if key not in entity_seen:
                        entity_seen.add(key)
                        entity_records.append({
                            "doc_id": doc_id,
                            "content": s_txt,
                            "payload": {
                                "doc_id": doc_id,
                                "component_type": "entity",
                                "entity_name": s_txt,
                            },
                        })
                if o_txt:
                    key = (doc_id, o_txt.lower())
                    if key not in entity_seen:
                        entity_seen.add(key)
                        entity_records.append({
                            "doc_id": doc_id,
                            "content": o_txt,
                            "payload": {
                                "doc_id": doc_id,
                                "component_type": "entity",
                                "entity_name": o_txt,
                            },
                        })
                if s_txt and r_txt and o_txt:
                    rel_text = f"{s_txt} -{r_txt}-> {o_txt}"
                    key = (doc_id, rel_text.lower())
                    if key not in relation_seen:
                        relation_seen.add(key)
                        relation_records.append({
                            "doc_id": doc_id,
                            "content": rel_text,
                            "payload": {
                                "doc_id": doc_id,
                                "component_type": "relation",
                                "relation": r_txt,
                                "subject": s_txt,
                                "object": o_txt,
                            },
                        })

    provider = get_provider(body.provider)
    if body.embedding_model and hasattr(provider, "embed_model"):
        provider.embed_model = body.embedding_model.strip()
    points: List[Dict[str, Any]] = []
    vector_size: int | None = None
    point_counter = 1

    component_records = chunk_records + entity_records + relation_records
    for record in component_records:
        try:
            vec = provider.embed(record["content"])
        except Exception as exc:
            raise HTTPException(status_code=500, detail=f"Embedding failed: {exc}") from exc
        vector_size = vector_size or len(vec)
        payload = dict(record["payload"])
        points.append({
            "id": point_counter,
            "vector": vec,
            "payload": payload,
        })
        point_counter += 1

    qdrant.ensure_collection(vector_size or 1024, distance=body.distance)
    for i in range(0, len(points), batch_size):
        qdrant.upsert(points[i:i + batch_size])

    if body.graph:
        g = Neo4jGraph()
        try:
            for doc_id, triplets in graph_triplets_by_doc.items():
                if triplets:
                    g.upsert_triplets(triplets, doc_id=doc_id)
        except Exception as e:
            raise HTTPException(500, detail=f"Graph build failed: {e}")
        finally:
            try:
                g.close()
            except Exception:
                pass

    summary: Dict[str, Any] = {
        "timestamp": datetime.utcnow().isoformat() + "Z",
        "ingested_points": len(points),
    }

    summary["documents"] = doc_stats
    if body.graph:
        summary["graph_components"] = {
            "entity_points": len(entity_records),
            "relation_points": len(relation_records),
        }

    qdrant_stats: Dict[str, Any] = {
        "primary_collection": qdrant.collection,
    }
    primary_count = qdrant.count_points()
    if primary_count is not None:
        qdrant_stats["primary_point_count"] = primary_count

    auxiliary_collections = {}
    for col in ("hawki_chunks", "hawki_entities", "hawki_relationships"):
        cnt = qdrant.count_points(col)
        if cnt is not None:
            auxiliary_collections[col] = cnt
    if auxiliary_collections:
        qdrant_stats["auxiliary_collections"] = auxiliary_collections

    summary["qdrant"] = qdrant_stats

    graph_client: Optional[Neo4jGraph] = None
    try:
        graph_client = Neo4jGraph()
        neo4j_stats = {
            "entity_count": graph_client.count_entities(),
            "triplet_count": graph_client.count_triplets(),
            "relationship_counts": graph_client.count_relationships_by_type(),
            "label_counts": graph_client.count_nodes_by_label(),
        }
        summary["neo4j"] = neo4j_stats
    except Exception as exc:
        summary["neo4j_error"] = str(exc)
    finally:
        if graph_client:
            try:
                graph_client.close()
            except Exception:
                pass

    summary["dry_run"] = False
    summary.setdefault("planned_points", total_chunks)
    preview = json.dumps(summary, indent=2, ensure_ascii=False)
    print(preview)
    logger.info("Ingest summary:\n%s", preview)

    try:
        public_dir.mkdir(parents=True, exist_ok=True)
        summary_path = public_dir / "ingest_summary.json"
        summary_path.write_text(preview + "\n", encoding="utf-8")
        summary["summary_file"] = str(summary_path)
    except Exception as exc:
        summary["summary_file_error"] = str(exc)

    return {"ok": True, "points": len(points), "summary": summary}


def delete_document(doc_id: str) -> Dict[str, Any]:
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    return _delete_document_entries(doc_id)


def replace_document(doc_id: str, body: Any, *, ingest_fn) -> Dict[str, Any]:
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    if not (body.text and body.text.strip()):
        raise HTTPException(status_code=400, detail="text is required to replace a document")

    deletion = _delete_document_entries(doc_id)

    ingest_response = ingest_fn(body, doc_id=doc_id)
    ingest_response["replaced_doc_id"] = str(doc_id)
    ingest_response["deleted"] = deletion
    return ingest_response


def query_documents(body: Any, *, rag_service: Any, get_provider) -> Dict[str, Any]:
    timings: Dict[str, float] = {}
    t0 = time.perf_counter()
    os.environ["RAG_FAST_MODE"] = "true" if body.fast_mode else "false"
    prompt_safety = _analyze_prompt_safety(body.query)
    if prompt_safety["blocked"]:
        logger.warning("Blocked query by content safety: %s", prompt_safety["issues"])
        detail = "Query blocked by content safety filters."
        if prompt_safety["issues"]:
            detail += f" Reasons: {', '.join(prompt_safety['issues'])}."
        raise HTTPException(status_code=400, detail=detail)

    user_query = prompt_safety["sanitized"]
    if not user_query.strip():
        raise HTTPException(status_code=400, detail="Query is empty after sanitization.")

    provider = get_provider(body.provider)
    qdrant = QdrantHTTP()
    t_rewrite_start = time.perf_counter()
    rewrite_enabled = (not body.fast_mode) and _is_multimodal_query(user_query)
    rewrite = {} if not rewrite_enabled else _rewrite_query(provider, user_query)
    timings["rewrite_ms"] = (time.perf_counter() - t_rewrite_start) * 1000
    rewritten_query = _sanitize_prompt_text(rewrite.get("rewritten_query") or user_query)
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
    keyword_fields = [
        "title_text",
        "page_url_text",
        "source_url",
        "tags",
        "content",
        "pdfs",
    ]
    if body.smart_lookup and not body.fast_mode:
        hits = semantic_search_smart(
            qdrant,
            vec,
            top_k=body.top_k,
            filters=filters,
            keyword_terms=query_terms,
            keyword_fields=keyword_fields,
        )
        if not hits:
            hits = semantic_search_basic(
                qdrant,
                vec,
                top_k=body.top_k,
                filters=filters,
            )
    elif body.is_optimized and not body.fast_mode:
        hits = optimized_semantic_search(
            qdrant,
            vec,
            top_k=body.top_k,
            filters=filters,
            preferred_tags=body.preferred_tags,
        )
    else:
        hits = semantic_search_basic(
            qdrant,
            vec,
            top_k=body.top_k,
            filters=filters,
        )
    timings["qdrant_ms"] = (time.perf_counter() - t_qdrant_start) * 1000

    structural_limit = int(os.environ.get("RAG_STRUCTURAL_LIMIT", max(body.top_k * 2, 12)))
    structural_hops = int(os.environ.get("RAG_STRUCTURAL_HOPS", "2"))
    t_graph_start = time.perf_counter()
    structural_hits = [] if body.fast_mode else _build_structural_hits(
        query_terms,
        limit=structural_limit,
        hops=structural_hops,
        include_rel_match=body.smart_lookup,
    )
    timings["graph_ms"] = (time.perf_counter() - t_graph_start) * 1000

    sem_weight = float(os.environ.get("RAG_FUSION_SEM_WEIGHT", "0.6"))
    str_weight = float(os.environ.get("RAG_FUSION_STR_WEIGHT", "0.4"))
    hits = _fuse_hits(hits, structural_hits, sem_weight=sem_weight, str_weight=str_weight)
    # Only return chunk hits to downstream consumers (clean retrieval payload).
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
    hits = [h for h in hits if float(h.get("score") or 0.0) >= 0.4]
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

        secondary_hits = semantic_search_high_recall(
            qdrant,
            iter_vec,
            top_k=max(body.top_k * 2, len(hits) or body.top_k),
            filters=filters,
        )
        if not secondary_hits:
            secondary_hits = optimized_semantic_search(
                qdrant,
                iter_vec,
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

    try:
        max_context_tokens = int(os.environ.get("RAG_CONTEXT_TOKENS", MAX_CONTEXT_TOKENS_DEFAULT))
    except Exception:
        max_context_tokens = MAX_CONTEXT_TOKENS_DEFAULT
    try:
        max_context_docs = int(os.environ.get("RAG_CONTEXT_DOCS", 6))
    except Exception:
        max_context_docs = 6

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
            content_sample = _strip_control_chars((payload.get("content") or "")[:160])
            kg_terms.update(_extract_terms(content_sample))
        limited_terms = list(kg_terms)[:30]
        if limited_terms:
            g = Neo4jGraph()
            try:
                kg_facts = g.fetch_related(limited_terms, limit=30)
            except Exception:
                kg_facts = []
            finally:
                g.close()
    timings["kg_ms"] = (time.perf_counter() - t_kg_start) * 1000

    answer = ""
    if False and context_summaries:
        context_docs = []
        for summary in context_summaries:
            meta_bits = []
            if summary.get("component_type"):
                meta_bits.append(f"type: {summary['component_type']}")
            if summary.get("source_format"):
                meta_bits.append(f"format: {summary['source_format']}")
            meta_line = f"\nMeta: {' · '.join(meta_bits)}" if meta_bits else ""
            context_docs.append(
                f"Source {summary['idx']}: {summary['title']}\nURL: {summary['url'] or 'n/a'}{meta_line}\nExcerpt:\n{summary['snippet']}"
            )
        graph_section = ""
        if kg_facts:
            lines = [f"- {fact['subject']} —{fact['relation']}→ {fact['object']}" for fact in kg_facts[:10]]
            graph_section = "\n\nGraph relationships:\n" + "\n".join(lines)
        system = (
            "You are a grounded assistant that must answer strictly from the provided context.\n"
            "Guidelines:\n"
            "- Base every statement on the supplied sources; cite each claim with the source title and URL.\n"
            '- If the sources conflict or do not contain the answer, reply with "I\'m not able to answer from the provided sources."\n'
            "- Summarise concisely, then list a short Sources section with the referenced titles and URLs.\n"
            "- When first mentioning a page, include its title and URL inline.\n\n"
            "Context documents:\n"
            + "\n\n".join(context_docs)
            + graph_section
        )
        messages = [{"role": "user", "content": rewritten_query}]
        raw_answer = provider.chat(system, messages)
        output_safety = _enforce_output_safety(raw_answer)
        answer = output_safety["answer"]
    else:
        output_safety = {"blocked": False, "issues": [], "answer": ""}
        answer = ""

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
                "rewrite": round(timings.get("rewrite_ms", 0.0), 2),
                "embed": round(timings.get("embed_ms", 0.0), 2),
                "qdrant": round(timings.get("qdrant_ms", 0.0), 2),
                "graph": round(timings.get("graph_ms", 0.0), 2),
                "rerank": round(timings.get("rerank_ms", 0.0), 2),
                "kg": round(timings.get("kg_ms", 0.0), 2),
                "total": round((time.perf_counter() - t0) * 1000, 2),
            },
        },
        "safety": {
            "prompt": {
                "issues": prompt_safety["issues"],
                "modified": user_query != body.query,
                "sanitized": user_query if user_query != body.query else None,
            },
            "output": {
                "blocked": output_safety["blocked"],
                "issues": output_safety["issues"],
            },
        },
    }


def graph_from_text(body: Any, *, rag_service: Any) -> Dict[str, Any]:
    triplets = rag_service.extract_triplets(body.text, body.engine)
    g = Neo4jGraph()
    g.upsert_triplets(triplets)
    g.close()
    return {"ok": True, "triplets": len(triplets)}
