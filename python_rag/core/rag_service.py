import logging
import os
import re
import json
import asyncio
from pathlib import Path
from typing import Any, Dict, List, Optional
from core.providers.ollama_provider import OllamaProvider
from core.providers.gwdg_provider import GWDGProvider

try:
    from lightrag.operate import extract_entities
    from lightrag.constants import DEFAULT_ENTITY_TYPES, DEFAULT_SUMMARY_LANGUAGE
except Exception:  # pragma: no cover - optional dependency in runtime
    extract_entities = None
    DEFAULT_ENTITY_TYPES = ["Person", "Organization", "Location", "Event", "Concept"]
    DEFAULT_SUMMARY_LANGUAGE = "English"

logger = logging.getLogger(__name__)
GRAPH_DEBUG = os.environ.get("GRAPH_DEBUG", "").strip().lower() in ("1", "true", "yes")
GRAPH_DEBUG_LLM = os.environ.get("GRAPH_DEBUG_LLM", "").strip().lower() in ("1", "true", "yes")
GRAPH_PERF_LOG = os.environ.get("GRAPH_PERF_LOG", "").strip().lower() in ("1", "true", "yes")


def _perf_log(msg: str, *args: Any) -> None:
    if GRAPH_PERF_LOG:
        logger.info(msg, *args)
if not (GRAPH_DEBUG or GRAPH_DEBUG_LLM):
    logger.setLevel(logging.INFO)


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


def _normalize_language_setting(raw: str | None) -> str:
    if not raw:
        return DEFAULT_SUMMARY_LANGUAGE
    raw = raw.strip()
    if not raw:
        return DEFAULT_SUMMARY_LANGUAGE
    # Accept comma/semicolon/slash separated language hints.
    parts = [p.strip() for p in re.split(r"[,/;]+", raw) if p.strip()]
    if not parts:
        return DEFAULT_SUMMARY_LANGUAGE
    # Map common codes to names.
    map_codes = {
        "en": "English",
        "eng": "English",
        "english": "English",
        "de": "German",
        "deu": "German",
        "ger": "German",
        "german": "German",
    }
    normalized: list[str] = []
    for part in parts:
        key = part.lower()
        normalized.append(map_codes.get(key, part))
    if len(normalized) == 1:
        return normalized[0]
    # Build a natural language list (e.g., "English and German").
    if len(normalized) == 2:
        return f"{normalized[0]} and {normalized[1]}"
    return ", ".join(normalized[:-1]) + f", and {normalized[-1]}"


def _parse_env_list(raw: str | None) -> List[str]:
    if not raw:
        return []
    raw = raw.strip()
    if not raw:
        return []
    if raw.startswith("["):
        try:
            data = json.loads(raw)
        except Exception:
            data = None
        if isinstance(data, list):
            return [str(item).strip() for item in data if str(item).strip()]
    return [t.strip() for t in raw.split(",") if t.strip()]


def _trim_system_prompt(system: str) -> str:
    """Reduce oversized system prompts (notably long examples) for faster graph extraction."""
    if not system:
        return system
    try:
        max_examples = int(os.environ.get("GRAPH_PROMPT_MAX_EXAMPLES", "1"))
    except ValueError:
        max_examples = 1
    if max_examples <= 0:
        return system
    # Prefer trimming at example boundaries if present.
    marker = "<|COMPLETE|>"
    if marker in system:
        parts = system.split(marker)
        if len(parts) > max_examples:
            system = marker.join(parts[:max_examples]) + marker
    return system


def _clean_graph_text(text: str) -> str:
    if not text:
        return ""
    strip_mode = os.environ.get("GRAPH_STRIP_PIPES", "true").strip().lower()
    disable_table_heuristic = os.environ.get("GRAPH_DISABLE_TABLE_HEURISTIC", "").strip().lower() in ("1", "true", "yes")
    cleaned: list[str] = []
    for line in str(text).splitlines():
        stripped = line.strip()
        if not stripped:
            continue
        if not disable_table_heuristic:
            if strip_mode in ("1", "true", "yes") and "|" in stripped:
                continue
            # Drop markdown table delimiters and separator lines.
            if re.fullmatch(r"[\|\-\s:]+", stripped):
                continue
            if "|" in stripped:
                pipe_count = stripped.count("|")
                alpha = sum(1 for ch in stripped if ch.isalnum())
                ratio = alpha / max(1, len(stripped))
                # Remove low-signal table rows and headers.
                if pipe_count >= 2 and (alpha < 6 or ratio < 0.35):
                    continue
        cleaned.append(stripped)
    output = "\n".join(cleaned)
    try:
        max_lines = int(os.environ.get("GRAPH_MAX_LINES", "40"))
    except ValueError:
        max_lines = 40
    if max_lines > 0:
        output = "\n".join(output.splitlines()[:max_lines])
    try:
        max_chars = int(os.environ.get("GRAPH_MAX_CHARS", "2000"))
    except ValueError:
        max_chars = 2000
    if max_chars > 0:
        output = output[:max_chars]
    try:
        max_tokens = int(os.environ.get("MAX_EXTRACT_INPUT_TOKENS", "0"))
    except ValueError:
        max_tokens = 0
    if max_tokens > 0:
        tokens = output.split()
        if len(tokens) > max_tokens:
            output = " ".join(tokens[:max_tokens])
    return output


def _normalize_triplets(obj: Any) -> List[tuple[str, str, str]]:
    out: List[tuple[str, str, str]] = []
    if obj is None:
        return out
    if hasattr(obj, "to_dict"):
        try:
            obj = obj.to_dict()
        except Exception:
            pass
    if isinstance(obj, dict):
        for key in ("triplets", "relations", "edges", "kg", "data"):
            val = obj.get(key)
            if isinstance(val, (list, tuple)):
                return _normalize_triplets(val)
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


def _dedupe_triplets(triplets: List[tuple[str, str, str]]) -> List[tuple[str, str, str]]:
    seen = set()
    out: List[tuple[str, str, str]] = []
    for s, r, o in triplets:
        key = (s.strip(), r.strip(), o.strip())
        if not all(key) or key in seen:
            continue
        seen.add(key)
        out.append((key[0], key[1], key[2]))
    return out


def extract_triplets_with_lightrag(text: str) -> List[tuple[str, str, str]]:
    """Best-effort LightRAG triplet extraction with a small heuristic fallback."""
    if not text or not text.strip():
        return []
    # Try LightRAG-style imports first (if available).
    for mod_name, fn_name in (
        ("lightrag", "extract_triplets"),
        ("lightrag", "extract_kg"),
        ("lightrag.graph", "extract_triplets"),
        ("lightrag.utils", "extract_triplets"),
    ):
        try:
            mod = __import__(mod_name, fromlist=[fn_name])
            fn = getattr(mod, fn_name, None)
            if callable(fn):
                res = fn(text)
                return _dedupe_triplets(_normalize_triplets(res))
        except Exception as exc:
            logger.debug("LightRAG fallback import failed (%s.%s): %s", mod_name, fn_name, exc)

    # Heuristic fallback: extract simple "X is Y" style relations.
    triplets: List[tuple[str, str, str]] = []
    patterns = [
        r"([A-Z][A-Za-z0-9 -]{1,80})\s+(works at|studies at|is|are|was|ist|arbeitet bei)\s+([^\.,;]{1,80})",
    ]
    for sentence in re.split(r"[\\.!?\\n]+", text):
        s = sentence.strip()
        if not s:
            continue
        for pat in patterns:
            m = re.search(pat, s, flags=re.IGNORECASE)
            if not m:
                continue
            subj, rel, obj = m.group(1).strip(), m.group(2).strip(), m.group(3).strip()
            if subj and rel and obj:
                triplets.append((subj, rel, obj))
        if len(triplets) >= 50:
            break
    return _dedupe_triplets(triplets)


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
        # Use env-driven working directory without external settings module.
        self.working_dir = Path(os.environ.get("RAG_WORKING_DIR", "/app/rag_storage")).expanduser()
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
            logger.info("RAG-Anything client class not found.")
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

    def extract_triplets(
        self,
        text: str,
        engine: str | None,
        *,
        provider: Any | None = None,
        chunks: List[str] | None = None,
        doc_id: str | None = None,
        file_path: str | None = None,
    ) -> List[tuple[str, str, str]]:
        fn_start = time.perf_counter()
        result_count = 0
        path = "unknown"
        _perf_log(
            "perf:graph core.rag_service.extract_triplets start engine=%s doc_id=%s chunks=%s text_chars=%s",
            engine,
            doc_id or "-",
            0 if chunks is None else len(chunks),
            len(text or ""),
        )
        mode = (engine or "raganything").strip().lower()
        if mode != "raganything":
            logger.warning("Graph engine '%s' requested; enforcing raganything.", mode)
        if extract_entities is None:
            logger.warning("graph:extract_triplets missing LightRAG extract_entities; falling back to RAG-Anything")
            fallback_text = text or ""
            if not fallback_text and chunks:
                fallback_text = "\n".join(str(c) for c in chunks if c)
            fb_start = time.perf_counter()
            trips = self._extract_triplets_raganything(fallback_text)
            path = "raganything_missing_lightrag"
            _perf_log(
                "perf:graph core.rag_service.extract_triplets step=raganything_fallback reason=missing_lightrag triplets=%s ms=%.2f",
                len(trips),
                (time.perf_counter() - fb_start) * 1000,
            )
            if not trips:
                logger.warning("graph:extract_triplets RAG-Anything fallback returned 0 triplets (missing LightRAG)")
            result_count = len(trips)
            _perf_log(
                "perf:graph core.rag_service.extract_triplets done path=%s triplets=%s ms=%.2f",
                path,
                result_count,
                (time.perf_counter() - fn_start) * 1000,
            )
            return trips
        provider = provider or self.get_provider(os.environ.get("GRAPH_PROVIDER", "ollama"))
        # Clean table-heavy lines to reduce parser format errors.
        clean_input_start = time.perf_counter()
        cleaned_text = _clean_graph_text(text)
        cleaned_chunks = None
        if chunks is not None:
            cleaned_chunks = []
            for ch in chunks:
                cleaned = _clean_graph_text(ch)
                cleaned_chunks.append(cleaned if cleaned.strip() else ch)
        _perf_log(
            "perf:graph core.rag_service.extract_triplets step=clean_input chunks=%s ms=%.2f",
            0 if chunks is None else len(chunks),
            (time.perf_counter() - clean_input_start) * 1000,
        )
        chunk_map_start = time.perf_counter()
        chunk_map = self._build_chunk_map(
            cleaned_text if cleaned_text.strip() else text,
            cleaned_chunks if cleaned_chunks is not None else chunks,
            doc_id=doc_id,
            file_path=file_path,
        )
        _perf_log(
            "perf:graph core.rag_service.extract_triplets step=build_chunk_map chunk_map=%s ms=%.2f",
            len(chunk_map),
            (time.perf_counter() - chunk_map_start) * 1000,
        )
        lightrag_start = time.perf_counter()
        trips = self._extract_triplets_lightrag(chunk_map, provider)
        lightrag_ms = (time.perf_counter() - lightrag_start) * 1000
        _perf_log(
            "perf:graph core.rag_service.extract_triplets step=lightrag result=%s ms=%.2f",
            "none" if trips is None else len(trips),
            lightrag_ms,
        )
        if trips is None:
            logger.warning("graph:extract_triplets LightRAG failed; falling back to RAG-Anything")
            fallback_text = cleaned_text if cleaned_text.strip() else text
            if (not fallback_text or not fallback_text.strip()) and chunks:
                fallback_text = "\n".join(str(c) for c in chunks if c)
            fb_start = time.perf_counter()
            trips = self._extract_triplets_raganything(fallback_text)
            _perf_log(
                "perf:graph core.rag_service.extract_triplets step=raganything_fallback reason=lightrag_failed triplets=%s ms=%.2f",
                len(trips),
                (time.perf_counter() - fb_start) * 1000,
            )
            path = "raganything_after_lightrag_failure"
            if not trips:
                logger.warning("graph:extract_triplets RAG-Anything fallback returned 0 triplets (LightRAG failed)")
        else:
            path = "lightrag"
        logger.info("graph:extract_triplets raganything-logic count=%s", len(trips))
        result_count = len(trips)
        _perf_log(
            "perf:graph core.rag_service.extract_triplets done path=%s triplets=%s ms=%.2f",
            path,
            result_count,
            (time.perf_counter() - fn_start) * 1000,
        )
        return trips

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
                if isinstance(res, dict):
                    logger.debug("RAG-Anything %s response keys=%s", method, list(res.keys()))
                else:
                    logger.debug("RAG-Anything %s response type=%s", method, type(res).__name__)
                trips = _normalize_triplets(res)
                return trips
        return []

    @staticmethod
    def _build_chunk_map(
        text: str,
        chunks: List[str] | None,
        *,
        doc_id: str | None,
        file_path: str | None,
    ) -> Dict[str, Dict[str, Any]]:
        parts = chunks if chunks is not None else [text]
        doc_key = doc_id or "doc"
        path = file_path or "unknown_source"
        out: Dict[str, Dict[str, Any]] = {}
        for idx, content in enumerate(parts):
            if not content or not str(content).strip():
                continue
            out[f"chunk-{doc_key}-{idx}"] = {
                "tokens": len(str(content)),
                "content": str(content),
                "full_doc_id": doc_key,
                "chunk_order_index": idx,
                "file_path": path,
            }
        return out

    def _extract_triplets_lightrag(
        self,
        chunks: Dict[str, Dict[str, Any]],
        provider: Any,
    ) -> List[tuple[str, str, str]] | None:
        if not chunks:
            return []
        if GRAPH_DEBUG:
            total_chars = sum(len(str(v.get("content") or "")) for v in chunks.values())
            logger.debug("graph:llm chunks=%s total_chars=%s", len(chunks), total_chars)
        if os.environ.get("GRAPH_SUPPRESS_WARNINGS", "").strip().lower() in ("1", "true", "yes"):
            logging.getLogger("lightrag").setLevel(logging.ERROR)

        async def llm_func(user_prompt: str, system_prompt: str | None = None, history_messages: list | None = None, max_tokens: int | None = None):
            messages = list(history_messages or [])
            messages.append({"role": "user", "content": user_prompt})
            system = _trim_system_prompt(system_prompt or "You are a helpful assistant.")
            if GRAPH_DEBUG_LLM:
                logger.debug("graph:llm system_prompt=%s", system)
                logger.debug("graph:llm user_prompt=%s", user_prompt)
            graph_temp_env = os.environ.get("GRAPH_TEMPERATURE", "").strip()
            graph_temp = None
            if graph_temp_env:
                try:
                    graph_temp = float(graph_temp_env)
                except ValueError:
                    graph_temp = None
            else:
                graph_temp = 0.0
            response = provider.chat(system, messages, temperature=graph_temp)
            if GRAPH_DEBUG_LLM:
                logger.debug("graph:llm response=%s", response)
            return response

        entity_types_env = os.environ.get("KG_ENTITY_TYPES", "").strip()
        if not entity_types_env:
            entity_types_env = os.environ.get("ENTITY_TYPES", "").strip()
        entity_types = _parse_env_list(entity_types_env) or list(DEFAULT_ENTITY_TYPES)
        lang_env = os.environ.get("KG_LANGUAGE", "").strip()
        if not lang_env:
            lang_env = os.environ.get("SUMMARY_LANGUAGE", "").strip()
        language = _normalize_language_setting(lang_env or DEFAULT_SUMMARY_LANGUAGE)
        gleaning = int(os.environ.get("KG_MAX_GLEANING", "1"))

        global_config = {
            "llm_model_func": llm_func,
            "entity_extract_max_gleaning": gleaning,
            "addon_params": {"language": language, "entity_types": entity_types},
        }

        try:
            try:
                loop = asyncio.get_running_loop()
            except RuntimeError:
                loop = None
            if loop and loop.is_running():
                new_loop = asyncio.new_event_loop()
                try:
                    results = new_loop.run_until_complete(extract_entities(chunks, global_config))
                finally:
                    new_loop.close()
            else:
                results = asyncio.run(extract_entities(chunks, global_config))
        except Exception as exc:
            logger.warning("graph:extract_triplets LightRAG extraction failed: %s", exc)
            return None
        if GRAPH_DEBUG:
            try:
                res_len = len(results or [])
            except Exception:
                res_len = -1
            logger.debug("graph:llm results_batches=%s", res_len)

        triplets: List[tuple[str, str, str]] = []
        seen = set()
        for maybe_nodes, maybe_edges in results or []:
            for (src, tgt), rels in (maybe_edges or {}).items():
                for rel in rels or []:
                    rel_val = rel.get("keywords") or rel.get("description") or "RELATED_TO"
                    if isinstance(rel_val, str) and "," in rel_val:
                        rel_val = rel_val.split(",")[0].strip()
                    rel_val = str(rel_val).strip() if rel_val else "RELATED_TO"
                    key = (src, rel_val, tgt)
                    if key in seen or not all(key):
                        continue
                    seen.add(key)
                    triplets.append(key)
        return triplets

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
