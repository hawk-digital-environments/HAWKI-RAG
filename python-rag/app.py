########################################### libs and bibz #####################################
import json
import logging
import math
import os
import re
from datetime import datetime
from collections import Counter
from pathlib import Path
from typing import List, Dict, Any, Optional
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from providers.ollama_provider import OllamaProvider
from providers.gwdg_provider import GWDGProvider
from qdrant_http import QdrantHTTP
from qdrant_strategies import optimized_semantic_search, semantic_search_basic
from neo4j_graph import Neo4jGraph
from lightrag_impl import extract_triplets_fallback, extract_triplets_with_lightrag
########################################### CONFIG #####################################
logger = logging.getLogger(__name__)
BASE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = BASE_DIR.parent
PUBLIC_DIR = PROJECT_ROOT / "public"
app = FastAPI(title="LightRAG Service", version="0.2.0")

########################################### PREPROCESSING  #####################################
def _load_stopwords() -> set[str]:
    stop_path = BASE_DIR / "german_stopwords_plain.txt"
    try:
        content = stop_path.read_text(encoding="utf-8")
    except FileNotFoundError:
        return print("Stopwords not found")
    words = {
        line.strip().lower()
        for line in content.splitlines()
        if line.strip() and not line.strip().startswith("#")
    }
    return words 
STOPWORDS = _load_stopwords()
TERM_PATTERN = re.compile(r"[A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß0-9_-]{3,}")
########################################### TOKENIZATION AND TERM EXTRACTION  #####################################
def _extract_terms(text: str | None) -> List[str]:
    if not text:
        return []
    tokens = []
    for match in TERM_PATTERN.findall(str(text)):
        token = match.lower()
        if token not in STOPWORDS and len(token) >= 4:
            tokens.append(token)
    return tokens

def _terms_from_payload(payload: Dict[str, Any]) -> List[str]:
    terms: List[str] = []
    tags = payload.get("tags")
    if isinstance(tags, str):
        terms.extend(_extract_terms(tags))
    elif isinstance(tags, list):
        for tag in tags:
            terms.extend(_extract_terms(str(tag)))
    for key in ("title", "page_url", "source_url"):
        terms.extend(_extract_terms(payload.get(key)))
    return terms

def _flatten_keywords(raw) -> List[str]:
    if raw is None:
        return []
    if isinstance(raw, (list, tuple, set)):
        out: List[str] = []
        for item in raw:
            out.extend(_flatten_keywords(item))
        return out
    s = str(raw)
    cleaned = re.sub(r"^[^\n:]{0,200}:\s*", "", s)
    cleaned = re.sub(r"-\s*\d+\s*[\.-]?\s*", "\n", cleaned)
    cleaned = re.sub(r"\s*\d+\s*[\.\)\:\-]\s*", "\n", cleaned)
    parts: List[str] = []
    for line in re.split(r"[\r\n]+", cleaned):
        line = re.sub(r"^\s*[\-\*\•\u2022]?\s*", "", line)
        parts.extend(re.split(r"[,;]+", line))
    return [p.strip() for p in parts if p.strip()]


def _normalize_tags(candidates: List[str], limit: int = 10) -> List[str]:
    out: List[str] = []
    seen = set()
    for cand in candidates:
        cand = cand.replace("-", " ")
        cand = re.sub(r"[^\w\s]", " ", cand, flags=re.UNICODE)
        cand = re.sub(r"\s+", " ", cand).strip().lower()
        if not cand or len(cand) < 2:
            continue
        if cand not in seen:
            seen.add(cand)
            out.append(cand)
        if len(out) >= limit:
            break
    return out


def _fallback_tags(text: str, limit: int = 10) -> List[str]:
    if not text:
        return []
    words = re.findall(r"[a-z\u00c0-\u024f]+", text.lower())
    counts: Counter[str] = Counter()
    for w in words:
        if len(w) < 4 or w in STOPWORDS:
            continue
        counts[w] += 1
    tags: List[str] = []
    for word, _ in counts.most_common(limit * 2):
        if word not in tags:
            tags.append(word)
        if len(tags) >= limit:
            break
    return tags


def ensure_tags(payload: Dict[str, Any], text: str) -> None:
    raw = payload.get("tags")
    candidates = _flatten_keywords(raw)
    for key in ("keywords", "labels"):
        if key in payload and payload[key]:
            candidates.extend(_flatten_keywords(payload[key]))
    tags = _normalize_tags(candidates)
    if not tags:
        tags = _fallback_tags(text)
    payload["tags"] = tags or None

###################################### INGESTION AND PROVIDER CONFIG ###############################
def get_provider(name: str):
    if name == "ollama":
        return OllamaProvider()
    if name == "gwdg":
        return GWDGProvider()
    raise HTTPException(status_code=400, detail=f"Unknown provider: {name}")

class IngestDoc(BaseModel):
    id: str | int
    text: str
    payload: Dict[str, Any] = Field(default_factory=dict)

class IngestRequest(BaseModel):
    docs: List[IngestDoc]
    provider: str = Field(default=os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
    collection: str | None = None
    distance: str = Field(default=os.environ.get("QDRANT_DISTANCE", "Cosine"))
    chunk_chars: int = 3200
    chunk_overlap: int = 250
    graph: bool = False
    graph_engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "fallback"))  # fallback|lightrag
    dry_run: bool = False
    dry_include_graph: bool = False

class QueryRequest(BaseModel):
    query: str
    top_k: int = 5
    provider: str = Field(default=os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
    filters: Dict[str, Any] = Field(default_factory=dict)
    generate: bool = True
    is_optimized: bool = False
    preferred_tags: List[str] | None = None
    # Reranker options: none | cosine | external | jina
    reranker: str = Field(default=os.environ.get("RERANKER_MODE", "none"))
    rerank_top_n: int = 20
    # Mix mode: blend original vector score with reranker score
    mix_mode: bool = Field(default=bool(os.environ.get("RERANKER_MIX_MODE", "true").lower() in ("1","true","yes")))
    mix_weight: float = Field(default=float(os.environ.get("RERANKER_MIX_WEIGHT", 0.5)))  # 0..1, weight on original score

class GraphRequest(BaseModel):
    text: str
    engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "fallback"))
##################################. DOC CHUNKING//PDF SLICING #################################
def split_text(txt: str, target: int, overlap: int) -> List[str]:
    txt = (txt or "").strip()
    if not txt:
        return []
    if len(txt) <= target:
        return [txt]
    out: List[str] = []
    start = 0
    L = len(txt)
    while start < L:
        end = min(L, start + target)
        slice_ = txt[start:end]
        cut = slice_.rfind("\n\n")
        if cut != -1 and cut > int(target * 0.6):
            end = start + cut
        chunk = txt[start:end].strip()
        if chunk:
            out.append(chunk)
        if end >= L:
            break
        start = max(0, end - overlap)
    return out

@app.get("/health")
def health():
    return {"ok": True}
################################## PROVIDER AND RERANKER SETUP  #################################
@app.get("/config")
def config():
    # Active provider and embedding model
    provider_name = os.environ.get("RAG_DEFAULT_PROVIDER", "ollama").strip()
    try:
        provider = get_provider(provider_name)
        embed_model = getattr(provider, "embed_model", None)
    except Exception:
        embed_model = None

    # Reranker settings
    reranker_mode = os.environ.get("RERANKER_MODE", "none")
    mix_mode = str(os.environ.get("RERANKER_MIX_MODE", "true")).lower() in ("1", "true", "yes")
    try:
        mix_weight = float(os.environ.get("RERANKER_MIX_WEIGHT", 0.5))
    except Exception:
        mix_weight = 0.5
    jina_model = os.environ.get("JINA_RERANKER_MODEL", "jina-reranker-v2-base-multilingual")
    external_url = os.environ.get("RERANKER_API_URL", "")

    # Qdrant collection and vector size
    q = QdrantHTTP()
    qdrant_collection = q.collection
    vector_size = q.get_vector_size()

    return {
        "provider": provider_name,
        "embedding_model": embed_model,
        "qdrant_collection": qdrant_collection,
        "qdrant_vector_size": vector_size,
        "reranker": {
            "mode": reranker_mode,
            "mix_mode": mix_mode,
            "mix_weight": mix_weight,
            "jina_model": jina_model,
            "external_url": external_url,
        },
    }
################################## INGESTION LOGIC #################################
@app.post("/ingest")
def ingest(body: IngestRequest):
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
                if body.graph_engine == "lightrag":
                    triplets = extract_triplets_with_lightrag(joined)
                else:
                    triplets = extract_triplets_fallback(joined)
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
            PUBLIC_DIR.mkdir(parents=True, exist_ok=True)
            summary_path = PUBLIC_DIR / "ingest_summary.json"
            summary_path.write_text(preview + "\n", encoding="utf-8")
            summary["summary_file"] = str(summary_path)
        except Exception as exc:
            summary["summary_file_error"] = str(exc)

        return {"ok": True, "dry_run": True, "points": total_chunks, "summary": summary}

    provider = get_provider(body.provider)
    points: List[Dict[str, Any]] = []
    vector_size: int | None = None
    point_counter = 1

    for record in chunk_records:
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
        qdrant.upsert(points[i:i+batch_size])

    if body.graph:
        try:
            g = Neo4jGraph()
            texts = [rec["content"] for rec in chunk_records]
            joined = "\n\n".join(texts)
            if body.graph_engine == "lightrag":
                triplets = extract_triplets_with_lightrag(joined)
            else:
                triplets = extract_triplets_fallback(joined)
            g.upsert_triplets(triplets)
            g.close()
        except Exception as e:
            raise HTTPException(500, detail=f"Graph build failed: {e}")

    summary: Dict[str, Any] = {
        "timestamp": datetime.utcnow().isoformat() + "Z",
        "ingested_points": len(points),
    }

    summary["documents"] = doc_stats

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
        PUBLIC_DIR.mkdir(parents=True, exist_ok=True)
        summary_path = PUBLIC_DIR / "ingest_summary.json"
        summary_path.write_text(preview + "\n", encoding="utf-8")
        summary["summary_file"] = str(summary_path)
    except Exception as exc:
        summary["summary_file_error"] = str(exc)

    return {"ok": True, "points": len(points), "summary": summary}

def _cosine(a: list[float], b: list[float]) -> float:
    import math
    if not a or not b or len(a) != len(b):
        return 0.0
    dp = sum(x*y for x, y in zip(a, b))
    na = math.sqrt(sum(x*x for x in a))
    nb = math.sqrt(sum(y*y for y in b))
    if na == 0 or nb == 0:
        return 0.0
    return dp / (na * nb)

@app.post("/query")
def query(body: QueryRequest):
    provider = get_provider(body.provider)
    qdrant = QdrantHTTP()
    try:
        vec = provider.embed(body.query)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Embedding failed: {exc}") from exc

    filters = dict(body.filters) if body.filters else None
    if body.is_optimized:
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
    # reranking stage
    if body.reranker and body.reranker.lower() != "none" and hits:
        mode = body.reranker.lower()
        # slice candidates to re-rank
        cand = hits[: max(1, min(body.rerank_top_n, len(hits)))]
        # Keep original scores for mix mode
        orig_scores = [float(h.get("score") or 0.0) for h in cand]
        try:
            if mode == "cosine":
                # Recompute embedding for each candidate snippet/content and sort by cosine to query
                scored = []
                for h in cand:
                    p = h.get("payload") or {}
                    text = (p.get("snippet") or p.get("content") or p.get("title") or "")
                    if not text:
                        scored.append((0.0, h))
                        continue
                    try:
                        dv = provider.embed(str(text)[:1000])
                    except Exception:
                        dv = []
                    score = _cosine(vec, dv)
                    scored.append((float(score), h))
                # mix (normalize both lists 0..1)
                if body.mix_mode:
                    import math
                    rr_scores = [s for s, _ in scored]
                    def norm(lst: list[float]) -> list[float]:
                        lo, hi = min(lst), max(lst)
                        if math.isclose(lo, hi):
                            return [0.0 for _ in lst]
                        return [(x - lo) / (hi - lo) for x in lst]
                    nr = norm(rr_scores)
                    no = norm(orig_scores)
                    alpha = max(0.0, min(1.0, float(body.mix_weight)))
                    mixed = [(alpha*o + (1.0-alpha)*r, h) for r, (s, h), o in zip(nr, scored, no)]
                    mixed.sort(key=lambda x: x[0], reverse=True)
                    hits = [h for _, h in mixed] + hits[len(mixed):]
                else:
                    scored.sort(key=lambda x: x[0], reverse=True)
                    hits = [h for _, h in scored] + hits[len(scored):]
            elif mode == "external":
                # Call an external reranker API if configured
                import requests
                rr_url = os.environ.get("RERANKER_API_URL", "").strip()
                rr_key = os.environ.get("RERANKER_API_KEY", "").strip()
                if rr_url:
                    docs = []
                    for h in cand:
                        p = h.get("payload") or {}
                        docs.append({
                            "id": p.get("page_url") or p.get("source_url") or str(p.get("title") or ""),
                            "text": (p.get("snippet") or p.get("content") or p.get("title") or "")[:2000]
                        })
                    headers = {"Content-Type": "application/json"}
                    if rr_key:
                        headers["Authorization"] = f"Bearer {rr_key}"
                    r = requests.post(rr_url, headers=headers, json={"query": body.query, "documents": docs}, timeout=30)
                    if r.ok:
                        j = r.json()
                        # Expecting list of {id, score}; map back to hits by URL/id
                        order = j.get("results") or j
                        if isinstance(order, list):
                            score_map = {str(item.get("id")): float(item.get("score", 0.0)) for item in order}
                            def key_for(h):
                                p = h.get("payload") or {}
                                return str(p.get("page_url") or p.get("source_url") or p.get("title") or "")
                            scored = [(score_map.get(key_for(h), 0.0), h) for h in cand]
                            if body.mix_mode:
                                import math
                                rr_scores = [s for s, _ in scored]
                                def norm(lst: list[float]) -> list[float]:
                                    lo, hi = min(lst), max(lst)
                                    if math.isclose(lo, hi):
                                        return [0.0 for _ in lst]
                                    return [(x - lo) / (hi - lo) for x in lst]
                                nr = norm(rr_scores)
                                no = norm(orig_scores)
                                alpha = max(0.0, min(1.0, float(body.mix_weight)))
                                mixed = [(alpha*o + (1.0-alpha)*r, h) for r, (s, h), o in zip(nr, scored, no)]
                                mixed.sort(key=lambda x: x[0], reverse=True)
                                hits = [h for _, h in mixed] + hits[len(mixed):]
                            else:
                                scored.sort(key=lambda x: x[0], reverse=True)
                                hits = [h for _, h in scored] + hits[len(scored):]
            elif mode == "jina":
                import requests
                jina_key = os.environ.get("JINA_API_KEY", "").strip()
                jina_model = os.environ.get("JINA_RERANKER_MODEL", "jina-reranker-v2-base-multilingual").strip()
                if jina_key:
                    docs = []
                    for h in cand:
                        p = h.get("payload") or {}
                        docs.append((p.get("snippet") or p.get("content") or p.get("title") or "")[:2000])
                    headers = {
                        "Content-Type": "application/json",
                        "Authorization": f"Bearer {jina_key}"
                    }
                    body_req = {"model": jina_model, "query": body.query, "documents": docs}
                    r = requests.post("https://api.jina.ai/v1/rerank", headers=headers, json=body_req, timeout=30)
                    if r.ok:
                        j = r.json()
                        results = j.get("results") or []
                        # results has [{index, document, relevance_score}]
                        rr_scores = [0.0]*len(cand)
                        for item in results:
                            idx = int(item.get("index", -1))
                            sc = float(item.get("relevance_score", 0.0))
                            if 0 <= idx < len(rr_scores):
                                rr_scores[idx] = sc
                        # Build pairs for mix/sort
                        pairs = list(zip(rr_scores, cand))
                        if body.mix_mode:
                            import math
                            def norm(lst: list[float]) -> list[float]:
                                lo, hi = min(lst), max(lst)
                                if math.isclose(lo, hi):
                                    return [0.0 for _ in lst]
                                return [(x - lo) / (hi - lo) for x in lst]
                            nr = norm(rr_scores)
                            no = norm(orig_scores)
                            alpha = max(0.0, min(1.0, float(body.mix_weight)))
                            mixed = [(alpha*o + (1.0-alpha)*r, h) for r, h, o in zip(nr, cand, no)]
                            mixed.sort(key=lambda x: x[0], reverse=True)
                            hits = [h for _, h in mixed] + hits[len(mixed):]
                        else:
                            pairs.sort(key=lambda x: x[0], reverse=True)
                            hits = [h for _, h in pairs] + hits[len(pairs):]
        except Exception:
            # ignore reranker failures
            pass

    kg_facts: List[Dict[str, str]] = []
    if hits:
        kg_terms = set(_extract_terms(body.query))
        for h in hits[: body.top_k]:
            payload = h.get("payload") or {}
            kg_terms.update(_terms_from_payload(payload))
            content_sample = (payload.get("content") or "")[:160]
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

    answer = ""
    if body.generate and hits:
        items = []
        for h in hits[:5]:
            p = h.get("payload") or {}
            title = p.get("title") or p.get("page_title") or "Untitled"
            url = p.get("page_url") or p.get("source_url") or ""
            snippet = (p.get("snippet") or p.get("content") or "")[:800]
            items.append(f"- {title}\n{snippet}\n{url}")
        graph_section = ""
        if kg_facts:
            lines = [f"- {fact['subject']} —{fact['relation']}→ {fact['object']}" for fact in kg_facts[:10]]
            graph_section = "\n\nGraph relationships:\n" + "\n".join(lines)
        system = (
            "You are a helpful assistant that answers using these pages only.\n\n"
            + "\n\n".join(items)
            + graph_section
            + "\n\nWhen first mentioning a page, include its title and URL."
        )
        messages = [{"role": "user", "content": body.query}]
        answer = provider.chat(system, messages)

    return {"ok": True, "count": len(hits), "hits": hits, "kg": kg_facts, "answer": answer}

@app.post("/graph/from-text")
def graph_from_text(body: GraphRequest):
    if body.engine == "lightrag":
        triplets = extract_triplets_with_lightrag(body.text)
    else:
        triplets = extract_triplets_fallback(body.text)
    g = Neo4jGraph()
    g.upsert_triplets(triplets)
    g.close()
    return {"ok": True, "triplets": len(triplets)}
