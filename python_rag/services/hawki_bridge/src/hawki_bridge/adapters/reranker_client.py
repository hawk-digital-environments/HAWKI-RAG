from __future__ import annotations

import logging
import os
from typing import Any, Optional, Protocol, cast

import requests as requests_module
from hawki_rag_text.safety import strip_control_characters

logger = logging.getLogger(__name__)


class _RerankerHTTPResponse(Protocol):
    """Response surface consumed from an external reranker."""

    ok: bool

    def json(self) -> object: ...


class _RerankerHTTPClient(Protocol):
    """HTTP operation required by external reranker modes."""

    def post(
        self,
        url: str,
        *,
        headers: dict[str, str],
        json: dict[str, object],
        timeout: float,
    ) -> _RerankerHTTPResponse: ...


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
    """Scale one reranker signal for ranking or retrieval/reranker blending.

    Unlike retrieval-stage fusion, a tied multi-candidate signal remains neutral
    at 0.5 so it does not add artificial preference during weighted blending.
    """

    import math

    lo, hi = min(values), max(values)
    if math.isclose(lo, hi):
        return [1.0 if len(values) == 1 else 0.5 for _ in values]
    return [(x - lo) / (hi - lo) for x in values]


def _rank_candidates(
    candidates: list[dict[str, Any]],
    scores: list[float],
    hits: list[dict[str, Any]],
    mix_mode: bool,
    mix_weight: float,
    orig_scores: list[float],
) -> list[dict[str, Any]]:
    reranker_scores = _normalize_scores([float(score) for score in scores])
    if mix_mode:
        retrieval_scores = _normalize_scores(orig_scores)
        alpha = max(0.0, min(1.0, float(mix_weight)))
        final_scores = [
            (alpha * retrieval_score) + ((1.0 - alpha) * reranker_score)
            for reranker_score, retrieval_score in zip(
                reranker_scores, retrieval_scores
            )
        ]
    else:
        final_scores = reranker_scores

    ranked = sorted(
        zip(final_scores, candidates),
        key=lambda item: item[0],
        reverse=True,
    )
    ranked_hits = []
    for final_score, hit in ranked:
        ranked_hit = dict(hit)
        ranked_hit["score"] = final_score
        ranked_hits.append(ranked_hit)
    return ranked_hits + hits[len(ranked) :]


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
    http_client: _RerankerHTTPClient | None = None,
) -> list[dict[str, Any]]:
    if not (mode and mode.lower() != "none" and hits):
        return hits

    mode = mode.lower()
    resolved_http_client = (
        cast(_RerankerHTTPClient, requests_module)
        if http_client is None
        else http_client
    )
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
                text = strip_control_characters(
                    payload.get("snippet")
                    or payload.get("content")
                    or payload.get("title")
                    or ""
                )
                if not text:
                    scores.append(0.0)
                    continue
                try:
                    doc_vec = provider.embed(str(text)[:1000])
                except Exception:
                    doc_vec = []
                scores.append(_cosine(base_vec, doc_vec))
            return _rank_candidates(
                candidates, scores, hits, mix_mode, mix_weight, orig_scores
            )

        if mode == "external":
            rr_url = os.environ.get("RERANKER_API_URL", "").strip()
            if rr_url:
                docs: list[str] = []
                for h in candidates:
                    payload = h.get("payload") or {}
                    docs.append(
                        strip_control_characters(
                            (
                                payload.get("snippet")
                                or payload.get("content")
                                or payload.get("title")
                                or ""
                            )[:2000]
                        )
                    )
                headers = {"Content-Type": "application/json"}
                rr_key = os.environ.get("RERANKER_API_KEY", "").strip()
                if rr_key:
                    headers["Authorization"] = f"Bearer {rr_key}"
                response = resolved_http_client.post(
                    rr_url,
                    headers=headers,
                    json={"query": user_query, "documents": docs},
                    timeout=30,
                )
                if response.ok:
                    payload = response.json()
                    order = (
                        payload.get("results") if isinstance(payload, dict) else payload
                    )
                    if isinstance(order, list):

                        def key_for(hit: dict[str, Any]) -> str:
                            p = hit.get("payload") or {}
                            page_url = (
                                p.get("page_url_text")
                                or p.get("page_url")
                                or p.get("source_url")
                            )
                            if isinstance(page_url, list):
                                page_url = page_url[0] if page_url else ""
                            title = p.get("title_text") or p.get("title")
                            if isinstance(title, list):
                                title = title[0] if title else ""
                            return str(page_url or title or "")

                        scores_by_index: dict[int, float] = {}
                        scores_by_id: dict[str, float] = {}
                        for item in order:
                            if not isinstance(item, dict):
                                continue
                            raw_score = item.get(
                                "relevance_score", item.get("score", 0.0)
                            )
                            try:
                                score = float(raw_score)
                            except (TypeError, ValueError):
                                continue

                            try:
                                index = int(item.get("index", -1))
                            except (TypeError, ValueError):
                                index = -1
                            if 0 <= index < len(candidates):
                                scores_by_index[index] = score

                            item_id = item.get("id")
                            if item_id is not None:
                                scores_by_id[str(item_id)] = score

                        scores = [
                            scores_by_index.get(
                                index, scores_by_id.get(key_for(hit), 0.0)
                            )
                            for index, hit in enumerate(candidates)
                        ]
                        return _rank_candidates(
                            candidates, scores, hits, mix_mode, mix_weight, orig_scores
                        )

        if mode == "jina":
            jina_key = os.environ.get("JINA_API_KEY", "").strip()
            if jina_key:
                docs = []
                for h in candidates:
                    payload = h.get("payload") or {}
                    docs.append(
                        strip_control_characters(
                            (
                                payload.get("snippet")
                                or payload.get("content")
                                or payload.get("title")
                                or ""
                            )[:2000]
                        )
                    )
                req_body = {
                    "model": os.environ.get(
                        "JINA_RERANKER_MODEL", "jina-reranker-v2-base-multilingual"
                    ).strip(),
                    "query": user_query,
                    "documents": docs,
                }
                headers = {
                    "Content-Type": "application/json",
                    "Authorization": f"Bearer {jina_key}",
                }
                response = resolved_http_client.post(
                    "https://api.jina.ai/v1/rerank",
                    headers=headers,
                    json=req_body,
                    timeout=30,
                )
                if response.ok:
                    payload = cast(dict[str, Any], response.json())
                    rr_scores = [0.0] * len(candidates)
                    results = payload.get("results") or []
                    for item in results:
                        idx = int(item.get("index", -1))
                        score = float(item.get("relevance_score", 0.0))
                        if 0 <= idx < len(rr_scores):
                            rr_scores[idx] = score
                    return _rank_candidates(
                        candidates, rr_scores, hits, mix_mode, mix_weight, orig_scores
                    )
    except requests_module.RequestException:
        logger.exception("Reranker failed; continuing with original order")
    except Exception:
        logger.exception("Reranker failed; continuing with original order")
    return hits
