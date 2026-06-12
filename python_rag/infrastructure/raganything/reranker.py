from __future__ import annotations

import logging
import os
from typing import Any, Dict, List, Optional

from common.optional_imports import import_required_module

logger = logging.getLogger(__name__)


def _requests_module() -> Any:
    return import_required_module(
        "requests",
        install_hint="Install python_rag/requirements.txt to use remote reranking.",
    )


def _strip_control_chars(text: str | None) -> str:
    if text is None:
        return ""
    cleaned_chars: list[str] = []
    for ch in str(text):
        code = ord(ch)
        if ch in ("\n", "\r", "\t"):
            cleaned_chars.append(ch)
        elif code >= 32:
            cleaned_chars.append(ch)
    return "".join(cleaned_chars)


def _cosine(a: list[float], b: list[float]) -> float:
    import math

    if not a or not b or len(a) != len(b):
        return 0.0
    dp = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a))
    nb = math.sqrt(sum(y * y for y in b))
    if na == 0 or nb == 0:
        return 0.0
    return dp / (na * nb)


def _normalize_scores(values: list[float]) -> list[float]:
    import math

    lo, hi = min(values), max(values)
    if math.isclose(lo, hi):
        return [0.0 for _ in values]
    return [(x - lo) / (hi - lo) for x in values]


def _rank_candidates(
    candidates: list[dict[str, Any]],
    scores: list[float],
    hits: list[dict[str, Any]],
    mix_mode: bool,
    mix_weight: float,
    orig_scores: list[float],
) -> list[dict[str, Any]]:
    if mix_mode:
        rr_scores = [float(s) for s in scores]
        nr = _normalize_scores(rr_scores)
        no = _normalize_scores(orig_scores)
        alpha = max(0.0, min(1.0, float(mix_weight)))
        mixed = [(alpha * o + (1.0 - alpha) * r, h) for r, h, o in zip(nr, candidates, no)]
        mixed.sort(key=lambda item: item[0], reverse=True)
        return [h for _, h in mixed] + hits[len(mixed) :]

    paired = list(zip(scores, candidates))
    paired.sort(key=lambda item: item[0], reverse=True)
    return [h for _, h in paired] + hits[len(paired) :]


def rerank_hits(
    *,
    hits: list[dict[str, Any]],
    user_query: str,
    provider: Any,
    query_vector: Optional[list[float]],
    mode: str | None,
    top_n: int,
    mix_mode: bool,
    mix_weight: float,
) -> list[dict[str, Any]]:
    if not (mode and mode.lower() != "none" and hits):
        return hits

    mode = mode.lower()
    candidates = hits[: max(1, min(top_n, len(hits)))]
    orig_scores = [float(hit.get("score") or 0.0) for hit in candidates]
    logger.info(
        "Reranker active (mode=%s, candidates=%d, total_hits=%d)",
        mode,
        len(candidates),
        len(hits),
    )

    try:
        if mode == "cosine":
            base_vec = query_vector
            if base_vec is None:
                try:
                    base_vec = provider.embed(user_query)
                except Exception:
                    base_vec = []
            scores = []
            for h in candidates:
                payload = h.get("payload") or {}
                text = _strip_control_chars(
                    payload.get("snippet") or payload.get("content") or payload.get("title") or ""
                )
                if not text:
                    scores.append(0.0)
                    continue
                try:
                    doc_vec = provider.embed(str(text)[:1000])
                except Exception:
                    doc_vec = []
                scores.append(_cosine(base_vec, doc_vec))
            return _rank_candidates(candidates, scores, hits, mix_mode, mix_weight, orig_scores)

        if mode == "external":
            rr_url = os.environ.get("RERANKER_API_URL", "").strip()
            if rr_url:
                requests = _requests_module()
                docs = []
                for h in candidates:
                    payload = h.get("payload") or {}
                    docs.append(
                        {
                            "id": payload.get("page_url") or payload.get("source_url") or str(payload.get("title") or ""),
                            "text": _strip_control_chars(
                                (payload.get("snippet") or payload.get("content") or payload.get("title") or "")[:2000]
                            ),
                        }
                    )
                headers = {"Content-Type": "application/json"}
                rr_key = os.environ.get("RERANKER_API_KEY", "").strip()
                if rr_key:
                    headers["Authorization"] = f"Bearer {rr_key}"
                response = requests.post(rr_url, headers=headers, json={"query": user_query, "documents": docs}, timeout=30)
                if response.ok:
                    payload = response.json()
                    order = payload.get("results") or payload
                    if isinstance(order, list):
                        score_map = {str(item.get("id")): float(item.get("score", 0.0)) for item in order}

                        def key_for(hit: dict[str, Any]) -> str:
                            p = hit.get("payload") or {}
                            page_url = p.get("page_url_text") or p.get("page_url") or p.get("source_url")
                            if isinstance(page_url, list):
                                page_url = page_url[0] if page_url else ""
                            title = p.get("title_text") or p.get("title")
                            if isinstance(title, list):
                                title = title[0] if title else ""
                            return str(page_url or title or "")

                        scores = [float(score_map.get(key_for(h), 0.0)) for h in candidates]
                        return _rank_candidates(candidates, scores, hits, mix_mode, mix_weight, orig_scores)

        if mode == "jina":
            jina_key = os.environ.get("JINA_API_KEY", "").strip()
            if jina_key:
                requests = _requests_module()
                docs = []
                for h in candidates:
                    payload = h.get("payload") or {}
                    docs.append(
                        _strip_control_chars(
                            (payload.get("snippet") or payload.get("content") or payload.get("title") or "")[:2000]
                        )
                    )
                req_body = {
                    "model": os.environ.get("JINA_RERANKER_MODEL", "jina-reranker-v2-base-multilingual").strip(),
                    "query": user_query,
                    "documents": docs,
                }
                headers = {"Content-Type": "application/json", "Authorization": f"Bearer {jina_key}"}
                response = requests.post("https://api.jina.ai/v1/rerank", headers=headers, json=req_body, timeout=30)
                if response.ok:
                    payload = response.json()
                    rr_scores = [0.0] * len(candidates)
                    results = payload.get("results") or []
                    for item in results:
                        idx = int(item.get("index", -1))
                        score = float(item.get("relevance_score", 0.0))
                        if 0 <= idx < len(rr_scores):
                            rr_scores[idx] = score
                    return _rank_candidates(candidates, rr_scores, hits, mix_mode, mix_weight, orig_scores)
    except Exception:
        logger.exception("Reranker failed; continuing with original order")
    return hits
