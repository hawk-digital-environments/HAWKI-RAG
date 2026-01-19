########################################### libs and bibz #####################################
import json
import logging
import math
import os
import re
import time
from datetime import datetime
from collections import Counter
from pathlib import Path
from typing import List, Dict, Any, Optional, Tuple
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from core.rag_service import RAGService
from vectorstore.qdrant_http import QdrantHTTP
from vectorstore.qdrant_strategies import (
    semantic_search_basic,
    semantic_search_high_recall,
    optimized_semantic_search,
    semantic_search_smart,
)
from graph.neo4j_graph import Neo4jGraph
########################################### CONFIG #####################################
logger = logging.getLogger(__name__)
BASE_DIR = Path(__file__).resolve().parent
PYTHON_RAG_ROOT = BASE_DIR.parent
PROJECT_ROOT = PYTHON_RAG_ROOT.parent
PUBLIC_DIR = PROJECT_ROOT / "public"
app = FastAPI(title="LightRAG Service", version="0.2.0")
rag_service = RAGService()

########################################### PREPROCESSING  #####################################
def _load_stopwords() -> set[str]:
    stop_path = PYTHON_RAG_ROOT / "config" / "german_stopwords_plain.txt"
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

# Basic content-safety heuristics to stop obvious prompt-injection and jailbreak attempts.
_PROMPT_INJECTION_PATTERNS = [
    re.compile(r"(?i)\bignore\b.{0,40}\b(previous|earlier)\b.{0,20}\b(instruction|message|directive)s?\b"),
    re.compile(r"(?i)\boverride\b.{0,40}\b(system|safety|guardrails)\b"),
    re.compile(r"(?i)\bdisable\b.{0,40}\b(filter|safety|security)\b"),
    re.compile(r"(?i)\bbypass\b.{0,40}\b(protection|guard|filter)\b"),
    re.compile(r"(?i)\b(as an ai language model).{0,40}\bforget\b"),
    re.compile(r"(?i)\b(system prompt)\b.{0,60}\bexpose\b"),
    re.compile(r"(?i)\bdo not cite\b"),
]
_PROMPT_DISALLOWED_TOKENS = [
    "<script",
    "<iframe",
    "<svg",
    "BEGIN PROMPT INJECTION",
    "<|im_start|>",
    "<|im_end|>",
    "```bash",
    "```sh",
]
_OUTPUT_BLOCK_PATTERNS = [
    re.compile(r"(?i)\b(ignore|override)\b.{0,40}\b(instructions|system)\b"),
    re.compile(r"(?i)\bBEGIN PROMPT INJECTION\b"),
    re.compile(r"(?i)<script"),
    re.compile(r"(?i)\bthis prompt bypasses\b"),
]
_CONTEXT_STRIP_TOKENS = [
    "<<SYS>>",
    "<<SYSTEM>>",
    "<|im_start|>",
    "<|im_end|>",
    "BEGIN PROMPT INJECTION",
]

MAX_CONTEXT_TOKENS_DEFAULT = 2800
ITERATIVE_RETRIEVAL_ENV = "RAG_ITERATIVE_RETRIEVAL"

_MULTIMODAL_HINT_PATTERN = re.compile(
    r"\b(figure|fig\.|image|photo|diagram|chart|table|equation|grafik|abbildung|tabelle|diagramm|bild|foto|gleichung)\b",
    re.IGNORECASE,
)
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


def _is_multimodal_query(text: str | None) -> bool:
    if not text:
        return False
    return bool(_MULTIMODAL_HINT_PATTERN.search(text))


def _strip_control_chars(text: str | None) -> str:
    if text is None:
        return ""
    cleaned_chars = []
    for ch in str(text):
        code = ord(ch)
        if ch in ("\n", "\r", "\t"):
            cleaned_chars.append(ch)
        elif code >= 32:
            cleaned_chars.append(ch)
    return "".join(cleaned_chars)


def _sanitize_prompt_text(text: str | None) -> str:
    cleaned = _strip_control_chars(text)
    # Collapse excessive whitespace but preserve newlines for readability
    cleaned = re.sub(r"[^\S\r\n]+", " ", cleaned)
    return cleaned.strip()


def _parse_json_from_text(text: str) -> Dict[str, Any]:
    if not text:
        return {}
    try:
        return json.loads(text)
    except Exception:
        pass
    match = re.search(r"\{.*\}", text, flags=re.DOTALL)
    if not match:
        return {}
    try:
        return json.loads(match.group(0))
    except Exception:
        return {}


def _rewrite_query(provider: Any, query: str) -> Dict[str, Any]:
    system = (
        "You are a RAG-Anything query interpreter. "
        "Return JSON only with keys: "
        "rewritten_query (string), high_level_keys (array of strings), "
        "low_level_keys (array of strings), modality_hints (array of strings), "
        "entity_terms (array of strings). "
        "Use modality_hints like: text, table, figure, chart, equation, image. "
        "Keep rewritten_query concise and faithful."
    )
    try:
        raw = provider.chat(system, [{"role": "user", "content": query}])
    except Exception as exc:
        logger.warning("Query rewrite failed: %s", exc)
        return {}
    data = _parse_json_from_text(raw)
    if not isinstance(data, dict):
        return {}
    return data


def _normalize_list(value: Any) -> List[str]:
    if not value:
        return []
    if isinstance(value, str):
        return [value.strip()] if value.strip() else []
    if isinstance(value, list):
        out = []
        for item in value:
            if isinstance(item, str) and item.strip():
                out.append(item.strip())
        return out
    return []


def _normalize_scores(scores: List[float]) -> List[float]:
    if not scores:
        return []
    lo = min(scores)
    hi = max(scores)
    if math.isclose(lo, hi):
        return [0.0 for _ in scores]
    return [(s - lo) / (hi - lo) for s in scores]


def _sanitize_context_snippet(text: str | None) -> str:
    cleaned = _strip_control_chars(text)
    for token in _CONTEXT_STRIP_TOKENS:
        cleaned = cleaned.replace(token, "")
    cleaned = re.sub(r"(?i)prompt injection:", "", cleaned)
    return cleaned.strip()


def _analyze_prompt_safety(text: str) -> Dict[str, Any]:
    sanitized = _strip_control_chars(text)
    lowered = sanitized.lower()
    issues: List[str] = []

    for pattern in _PROMPT_INJECTION_PATTERNS:
        if pattern.search(sanitized):
            issues.append("prompt_injection_pattern")
            break

    for token in _PROMPT_DISALLOWED_TOKENS:
        if token.lower() in lowered:
            issues.append(f"disallowed_token:{token}")

    if len(sanitized) > 8000:
        issues.append("prompt_too_long")

    blocked = any(issue.startswith("prompt_injection") or issue.startswith("disallowed_token") for issue in issues)
    sanitized_trimmed = _sanitize_prompt_text(sanitized)
    return {
        "sanitized": sanitized_trimmed,
        "issues": issues,
        "blocked": blocked,
    }


def _enforce_output_safety(answer: str) -> Dict[str, Any]:
    sanitized = _strip_control_chars(answer)
    issues: List[str] = []
    for pattern in _OUTPUT_BLOCK_PATTERNS:
        if pattern.search(sanitized):
            issues.append("unsafe_output_pattern")
            break
    blocked = bool(issues)
    final_answer = (
        "The generated answer was blocked by content safety. Please try a different question."
        if blocked
        else sanitized.strip()
    )
    return {
        "blocked": blocked,
        "issues": issues,
        "answer": final_answer,
    }


def _estimate_tokens(text: str) -> int:
    """Cheap heuristic: assume ~4 characters per token."""
    if not text:
        return 0
    return max(1, len(text) // 4)


def _truncate_to_tokens(text: str, token_budget: int) -> str:
    if token_budget <= 0 or not text:
        return ""
    approx_chars = token_budget * 4
    truncated = text[: approx_chars + 32]
    return truncated.strip()

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


###################################### INGESTION AND PROVIDER CONFIG ###############################
def get_provider(name: str):
    try:
        return rag_service.get_provider(name)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

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
    graph_engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "raganything"))
    dry_run: bool = False
    dry_include_graph: bool = False

class QueryRequest(BaseModel):
    query: str
    top_k: int = 5
    provider: str = Field(default=os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
    filters: Dict[str, Any] = Field(default_factory=dict)
    generate: bool = True
    is_optimized: bool = False
    fast_mode: bool = False
    smart_lookup: bool = False
    preferred_tags: List[str] | None = None
    # Reranker options: none | cosine | external | jina
    reranker: str = Field(default=os.environ.get("RERANKER_MODE", "none"))
    rerank_top_n: int = 20
    # Mix mode: blend original vector score with reranker score
    mix_mode: bool = Field(default=bool(os.environ.get("RERANKER_MIX_MODE", "true").lower() in ("1","true","yes")))
    mix_weight: float = Field(default=float(os.environ.get("RERANKER_MIX_WEIGHT", 0.5)))  # 0..1, weight on original score

class GraphRequest(BaseModel):
    text: str
    engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "raganything"))

class DocumentUpsertRequest(BaseModel):
    text: str
    payload: Dict[str, Any] = Field(default_factory=dict)
    provider: str | None = None
    collection: str | None = None
    distance: str | None = None
    chunk_chars: int | None = None
    chunk_overlap: int | None = None
    graph: bool = False
    graph_engine: str | None = None
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
            PUBLIC_DIR.mkdir(parents=True, exist_ok=True)
            summary_path = PUBLIC_DIR / "ingest_summary.json"
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
        qdrant.upsert(points[i:i+batch_size])

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
        PUBLIC_DIR.mkdir(parents=True, exist_ok=True)
        summary_path = PUBLIC_DIR / "ingest_summary.json"
        summary_path.write_text(preview + "\n", encoding="utf-8")
        summary["summary_file"] = str(summary_path)
    except Exception as exc:
        summary["summary_file_error"] = str(exc)

    return {"ok": True, "points": len(points), "summary": summary}


@app.delete("/documents/{doc_id}")
def delete_document(doc_id: str):
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    result = _delete_document_entries(doc_id)
    return {"ok": True, "doc_id": str(doc_id), "qdrant": result["qdrant"], "neo4j": result["neo4j"]}


@app.put("/documents/{doc_id}")
def replace_document(doc_id: str, body: DocumentUpsertRequest):
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    if not (body.text and body.text.strip()):
        raise HTTPException(status_code=400, detail="text is required to replace a document")

    deletion = _delete_document_entries(doc_id)

    ingest_doc = IngestDoc(id=doc_id, text=body.text, payload=body.payload)
    ingest_request = IngestRequest(
        docs=[ingest_doc],
        provider=body.provider or os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"),
        collection=body.collection,
        distance=body.distance or os.environ.get("QDRANT_DISTANCE", "Cosine"),
        chunk_chars=body.chunk_chars or 3200,
        chunk_overlap=body.chunk_overlap or 250,
        graph=body.graph,
        graph_engine=body.graph_engine or os.environ.get("GRAPH_ENGINE", "fallback"),
    )

    ingest_response = ingest(ingest_request)
    ingest_response["replaced_doc_id"] = str(doc_id)
    ingest_response["deleted"] = deletion
    return ingest_response

@app.post("/query")
def query(body: QueryRequest):
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

@app.post("/graph/from-text")
def graph_from_text(body: GraphRequest):
    triplets = rag_service.extract_triplets(body.text, body.engine)
    g = Neo4jGraph()
    g.upsert_triplets(triplets)
    g.close()
    return {"ok": True, "triplets": len(triplets)}
