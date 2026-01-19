import logging
import os
from typing import Any, Dict, List, Optional

from config.settings import load_settings
from core.providers.ollama_provider import OllamaProvider
from core.providers.gwdg_provider import GWDGProvider
from lightrag_ext.lightrag_impl import extract_triplets_fallback, extract_triplets_with_lightrag

logger = logging.getLogger(__name__)


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


def _normalize_triplets(obj: Any) -> List[tuple[str, str, str]]:
    out: List[tuple[str, str, str]] = []
    if obj is None:
        return out
    if isinstance(obj, list):
        for item in obj:
            if isinstance(item, (list, tuple)) and len(item) >= 3:
                s, r, o = item[0], item[1], item[2]
                if s and r and o:
                    out.append((str(s), str(r), str(o)))
            elif isinstance(item, dict):
                s = item.get("subject") or item.get("s") or item.get("head")
                r = item.get("relation") or item.get("r") or item.get("type")
                o = item.get("object") or item.get("o") or item.get("tail")
                if s and r and o:
                    out.append((str(s), str(r), str(o)))
    return out


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


class RAGService:
    """Orchestrates RAG components and shared configuration."""

    def __init__(self) -> None:
        self.settings = load_settings()
        self.working_dir = self.settings.rag_working_dir
        self.working_dir.mkdir(parents=True, exist_ok=True)
        self.raganything = self._init_raganything()

    def _init_raganything(self) -> Optional[Any]:
        try:
            import raganything  # type: ignore
        except Exception as exc:
            logger.info("RAG-Anything not available: %s", exc)
            return None

        candidate = None
        for name in ("RAGAnything", "RAGAnythingClient", "RAGAnythingService"):
            if hasattr(raganything, name):
                candidate = getattr(raganything, name)
                break

        if candidate is None:
            return None

        for builder in ("from_working_dir", "from_env"):
            if hasattr(candidate, builder):
                try:
                    return getattr(candidate, builder)(str(self.working_dir))
                except TypeError:
                    try:
                        return getattr(candidate, builder)()
                    except Exception:
                        pass

        try:
            return candidate(working_dir=str(self.working_dir))
        except TypeError:
            try:
                return candidate()
            except Exception as exc:
                logger.info("RAG-Anything init failed: %s", exc)
                return None

    def get_provider(self, name: str):
        key = (name or "").strip().lower()
        if key == "ollama":
            return OllamaProvider()
        if key == "gwdg":
            return GWDGProvider()
        raise ValueError(f"Unknown provider: {name}")

    def extract_triplets(self, text: str, engine: str | None) -> List[tuple[str, str, str]]:
        mode = (engine or "raganything").strip().lower()
        if mode != "raganything":
            logger.warning("Graph engine '%s' requested; enforcing raganything.", mode)
        if self.raganything is None:
            raise RuntimeError("RAG-Anything is required but not available.")
        return self._extract_triplets_raganything(text)

    def _extract_triplets_raganything(self, text: str) -> List[tuple[str, str, str]]:
        if self.raganything is None:
            return []
        for method in ("extract_triplets", "extract_kg", "build_knowledge_graph"):
            if hasattr(self.raganything, method):
                try:
                    res = getattr(self.raganything, method)(text)
                except Exception as exc:
                    logger.info("RAG-Anything %s failed: %s", method, exc)
                    continue
                trips = _normalize_triplets(res)
                return trips
        return []

    def rerank_hits(
        self,
        *,
        hits: List[Dict[str, Any]],
        user_query: str,
        provider: Any,
        query_vector: Optional[List[float]],
        mode: str | None,
        top_n: int,
        mix_mode: bool,
        mix_weight: float,
    ) -> List[Dict[str, Any]]:
        if not (mode and mode.lower() != "none" and hits):
            return hits
        mode = mode.lower()
        candidates = hits[: max(1, min(top_n, len(hits)))]
        orig_scores = [float(h.get("score") or 0.0) for h in candidates]
        logger.info(
            "Reranker active (mode=%s, candidates=%d, total_hits=%d)",
            mode,
            len(candidates),
            len(hits),
        )

        def norm(lst: List[float]) -> List[float]:
            import math

            lo, hi = min(lst), max(lst)
            if math.isclose(lo, hi):
                return [0.0 for _ in lst]
            return [(x - lo) / (hi - lo) for x in lst]

        try:
            if mode == "cosine":
                base_vec = query_vector
                if base_vec is None:
                    try:
                        base_vec = provider.embed(user_query)
                    except Exception:
                        base_vec = []
                scored = []
                for h in candidates:
                    payload = h.get("payload") or {}
                    text = _strip_control_chars(
                        payload.get("snippet") or payload.get("content") or payload.get("title") or ""
                    )
                    if not text:
                        scored.append((0.0, h))
                        continue
                    try:
                        dv = provider.embed(str(text)[:1000])
                    except Exception:
                        dv = []
                    scored.append((_cosine(base_vec, dv), h))
                if mix_mode:
                    rr_scores = [float(s) for s, _ in scored]
                    nr = norm(rr_scores)
                    no = norm(orig_scores)
                    alpha = max(0.0, min(1.0, float(mix_weight)))
                    mixed = [(alpha * o + (1.0 - alpha) * r, h) for r, (s, h), o in zip(nr, scored, no)]
                    mixed.sort(key=lambda x: x[0], reverse=True)
                    return [h for _, h in mixed] + hits[len(mixed):]
                scored.sort(key=lambda x: x[0], reverse=True)
                return [h for _, h in scored] + hits[len(scored):]
            if mode == "external":
                import requests

                rr_url = os.environ.get("RERANKER_API_URL", "").strip()
                rr_key = os.environ.get("RERANKER_API_KEY", "").strip()
                if rr_url:
                    docs = []
                    for h in candidates:
                        payload = h.get("payload") or {}
                        docs.append(
                            {
                                "id": payload.get("page_url")
                                or payload.get("source_url")
                                or str(payload.get("title") or ""),
                                "text": _strip_control_chars(
                                    (payload.get("snippet") or payload.get("content") or payload.get("title") or "")[
                                        :2000
                                    ]
                                ),
                            }
                        )
                    headers = {"Content-Type": "application/json"}
                    if rr_key:
                        headers["Authorization"] = f"Bearer {rr_key}"
                    response = requests.post(
                        rr_url,
                        headers=headers,
                        json={"query": user_query, "documents": docs},
                        timeout=30,
                    )
                    if response.ok:
                        payload = response.json()
                        order = payload.get("results") or payload
                        if isinstance(order, list):
                            score_map = {str(item.get("id")): float(item.get("score", 0.0)) for item in order}

                            def key_for(hit: Dict[str, Any]) -> str:
                                p = hit.get("payload") or {}
                                page_url = p.get("page_url_text") or p.get("page_url") or p.get("source_url")
                                if isinstance(page_url, list):
                                    page_url = page_url[0] if page_url else ""
                                title = p.get("title_text") or p.get("title")
                                if isinstance(title, list):
                                    title = title[0] if title else ""
                                return str(page_url or title or "")

                            scored = [(score_map.get(key_for(h), 0.0), h) for h in candidates]
                            if mix_mode:
                                rr_scores = [float(s) for s, _ in scored]
                                nr = norm(rr_scores)
                                no = norm(orig_scores)
                                alpha = max(0.0, min(1.0, float(mix_weight)))
                                mixed = [(alpha * o + (1.0 - alpha) * r, h) for r, (s, h), o in zip(nr, scored, no)]
                                mixed.sort(key=lambda x: x[0], reverse=True)
                                return [h for _, h in mixed] + hits[len(mixed):]
                            scored.sort(key=lambda x: x[0], reverse=True)
                            return [h for _, h in scored] + hits[len(scored):]
            if mode == "jina":
                import requests

                jina_key = os.environ.get("JINA_API_KEY", "").strip()
                jina_model = os.environ.get("JINA_RERANKER_MODEL", "jina-reranker-v2-base-multilingual").strip()
                if jina_key:
                    docs = []
                    for h in candidates:
                        payload = h.get("payload") or {}
                        docs.append(
                            _strip_control_chars(
                                (payload.get("snippet") or payload.get("content") or payload.get("title") or "")[
                                    :2000
                                ]
                            )
                        )
                    headers = {"Content-Type": "application/json", "Authorization": f"Bearer {jina_key}"}
                    req_body = {"model": jina_model, "query": user_query, "documents": docs}
                    response = requests.post(
                        "https://api.jina.ai/v1/rerank", headers=headers, json=req_body, timeout=30
                    )
                    if response.ok:
                        payload = response.json()
                        results = payload.get("results") or []
                        rr_scores = [0.0] * len(candidates)
                        for item in results:
                            idx = int(item.get("index", -1))
                            score_val = float(item.get("relevance_score", 0.0))
                            if 0 <= idx < len(rr_scores):
                                rr_scores[idx] = score_val
                        pairs = list(zip(rr_scores, candidates))
                        if mix_mode:
                            nr = norm(rr_scores)
                            no = norm(orig_scores)
                            alpha = max(0.0, min(1.0, float(mix_weight)))
                            mixed = [(alpha * o + (1.0 - alpha) * r, h) for r, h, o in zip(nr, candidates, no)]
                            mixed.sort(key=lambda x: x[0], reverse=True)
                            return [h for _, h in mixed] + hits[len(mixed):]
                        pairs.sort(key=lambda x: x[0], reverse=True)
                        return [h for _, h in pairs] + hits[len(pairs):]
        except Exception:
            logger.exception("Reranker failed; continuing with original order")
        return hits
