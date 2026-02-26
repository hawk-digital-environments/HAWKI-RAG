import asyncio
from functools import lru_cache
import hashlib
import json
import logging
import os
import re
import threading
import time
from pathlib import Path
from typing import Any, Dict, List, Optional

from core.providers.ollama_provider import OllamaProvider

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


def _normalize_graph_embed_text(text: Any) -> str:
    cleaned = _strip_control_chars(str(text or ""))
    cleaned = cleaned.encode("utf-8", errors="ignore").decode("utf-8", errors="ignore")
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    return cleaned


def _env_truthy(name: str, default: bool = False) -> bool:
    raw = str(os.environ.get(name, "")).strip().lower()
    if not raw:
        return default
    return raw in ("1", "true", "yes", "on")


@lru_cache(maxsize=64)
def _parse_graph_filter_pattern_list(raw: str) -> tuple[tuple[str, str], ...]:
    """
    Parse a semicolon-separated filter list.

    Pattern syntax (case-insensitive by default):
    - `exact:foo`
    - `contains:foo`
    - `re:<regex>`
    - `foo` (same as `exact:foo`)
    """
    text = str(raw or "").strip()
    if not text:
        return ()

    # Prefer semicolon so labels containing commas can still be represented.
    parts = [p.strip() for p in (text.split(";") if ";" in text else text.split(","))]
    parsed: list[tuple[str, str]] = []
    for part in parts:
        if not part:
            continue
        lower = part.lower()
        if lower.startswith("exact:"):
            parsed.append(("exact", part[6:].strip()))
        elif lower.startswith("contains:"):
            parsed.append(("contains", part[9:].strip()))
        elif lower.startswith("re:"):
            parsed.append(("regex", part[3:].strip()))
        else:
            parsed.append(("exact", part))
    return tuple((mode, pattern) for mode, pattern in parsed if pattern)


def _graph_filter_list_match(text: str, lower: str, raw_list: str) -> bool:
    patterns = _parse_graph_filter_pattern_list(raw_list)
    if not patterns:
        return False
    for mode, pattern in patterns:
        if mode == "exact":
            if lower == pattern.lower():
                return True
        elif mode == "contains":
            if pattern.lower() in lower:
                return True
        elif mode == "regex":
            try:
                if re.search(pattern, text, flags=re.IGNORECASE):
                    return True
            except re.error:
                continue
    return False


def _graph_embed_junk_reason(text: str) -> str | None:
    """
    Identify obviously malformed entity/relation strings before they hit Ollama embeddings.

    This is intentionally conservative: we only skip clear parser leftovers / placeholders.
    """
    if not text:
        return "empty"

    lower = text.lower().strip()
    if not lower:
        return "empty"

    allowlist_raw = str(os.environ.get("GRAPH_EMBED_JUNK_ALLOWLIST", "")).strip()
    if allowlist_raw and _graph_filter_list_match(text, lower, allowlist_raw):
        return None

    denylist_raw = str(os.environ.get("GRAPH_EMBED_JUNK_DENYLIST", "")).strip()
    if denylist_raw and _graph_filter_list_match(text, lower, denylist_raw):
        return "env_denylist"

    placeholder_exact = {
        "n/a",
        "na",
        "none",
        "null",
        "unknown",
        "entity",
        "relation",
        "realtion",
        "target_entity",
        "source_entity",
        "complete",
        "complete|",
        "skip",
    }
    if lower in placeholder_exact:
        return "placeholder"

    if re.fullmatch(r"[\W_]+", text):
        return "punctuation_only"

    alnum = sum(1 for ch in text if ch.isalnum())
    alpha = sum(1 for ch in text if ch.isalpha())
    if alnum <= 1:
        return "too_short"
    if len(text) <= 3 and alpha <= 1:
        return "too_short"

    # LightRAG delimiter residue or partially parsed record markers.
    if any(marker in text for marker in ("<|#|>", "<|#", "<|COMPLETE|>", "|#|")):
        return "delimiter_residue"
    if lower.startswith(("entity<", "relation<", "realtion<")):
        return "record_marker_residue"

    # Truncated placeholders / parser debris often end with dangling symbols.
    if text.endswith(("<", "|", "~", "`")) and alnum < 24:
        return "truncated_token"

    # Very low-signal strings with many separators (e.g. malformed parser output fragments).
    separator_count = sum(text.count(ch) for ch in ("<", ">", "|", "~", "`"))
    if separator_count >= 2 and alnum < 20:
        return "separator_heavy_fragment"

    # Short N/A-like fragments leaking from parser errors.
    if "n/a" in lower and alnum < 20:
        return "na_fragment"

    # Optional stricter filtering for common boilerplate labels extracted from website chrome
    # and form templates. Allowlist can override these exact values/patterns.
    if _env_truthy("GRAPH_EMBED_JUNK_STRICT", True):
        strict_exact = {
            "main content",
            "stage",
            "skip to main content",
            "skip to main content button",
            "skip to stage",
            "main content stage",
            "target entity",
            "source entity",
        }
        if lower in strict_exact:
            return "strict_boilerplate_label"
        if lower.startswith("skip to main content"):
            return "strict_boilerplate_label"
        if lower.startswith("skip to stage"):
            return "strict_boilerplate_label"

        strict_regexes = (
            r"\bnachname\s*,\s*vorname\b",
            r"\bname\s*,\s*vorname\b",
            r"\bstr\.\s*,\s*nr\.\s*,\s*plz(?:\s*,\s*ort)?\b",
            r"\bplz\s*,\s*ort\b",
            r"\btelefon(?:nummer)?\s*[:/]\s*fax\b",
        )
        for pattern in strict_regexes:
            if re.search(pattern, lower):
                return "strict_form_placeholder"

    return None


def _junk_embedding_sentinel(text: str, dim: int) -> list[float]:
    """
    Return a deterministic non-zero vector for skipped junk strings.

    Non-zero avoids downstream normalization warnings from zero-norm vectors while still
    keeping these low-quality values isolated from meaningful embeddings.
    """
    safe_dim = max(1, int(dim or 1024))
    vec = [0.0] * safe_dim
    digest = hashlib.sha1(text.encode("utf-8", errors="ignore")).digest()
    slot = int.from_bytes(digest[:4], "big") % safe_dim
    vec[slot] = 1.0
    return vec


def _is_junk_graph_label(value: Any) -> bool:
    text = _normalize_graph_embed_text(value)
    return _graph_embed_junk_reason(text) is not None


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
        # Shared RAG-Anything working directory (env-driven) used for graph/KG storages.
        self.working_dir = Path(os.environ.get("RAG_WORKING_DIR", "/app/rag_storage")).expanduser()
        self.working_dir.mkdir(parents=True, exist_ok=True)
        # Graph extraction uses a provider-backed RAGAnything instance, created lazily.
        # The instance is stateful (it maintains KG/vector storages), so we guard access.
        self._rag_graph_lock = threading.RLock()
        self._rag_graph_cache_key: str | None = None
        self.raganything: Optional[Any] = None
        self._rag_graph_loop: Any | None = None
        self._rag_graph_loop_thread: threading.Thread | None = None
        self._rag_graph_loop_ready = threading.Event()
        self._rag_graph_runtime_meta: Dict[str, Any] = {
            "doc_status_storage": "JsonDocStatusStorage",
            "graph_storage": "NetworkXStorage(default)",
            "graph_client_initialized": False,
        }
        self._rag_graph_kv_junk_scrub_once_done = False

    def _ensure_rag_graph_loop(self) -> Any:
        loop = self._rag_graph_loop
        if loop is not None and loop.is_running():
            return loop

        self._rag_graph_loop_ready.clear()

        def _runner() -> None:
            loop_obj = asyncio.new_event_loop()
            try:
                asyncio.set_event_loop(loop_obj)
                self._rag_graph_loop = loop_obj
                self._rag_graph_loop_ready.set()
                loop_obj.run_forever()
            finally:
                try:
                    pending = [t for t in asyncio.all_tasks(loop_obj) if not t.done()]
                except Exception:
                    pending = []
                for task in pending:
                    task.cancel()
                if pending:
                    try:
                        loop_obj.run_until_complete(asyncio.gather(*pending, return_exceptions=True))
                    except Exception:
                        pass
                try:
                    loop_obj.close()
                finally:
                    self._rag_graph_loop = None
                    asyncio.set_event_loop(None)

        t = threading.Thread(target=_runner, daemon=True, name="raganything-graph-loop")
        self._rag_graph_loop_thread = t
        t.start()
        if not self._rag_graph_loop_ready.wait(timeout=5):
            raise RuntimeError("RAG-Anything graph event loop did not start")
        if self._rag_graph_loop is None:
            raise RuntimeError("RAG-Anything graph event loop unavailable")
        return self._rag_graph_loop

    def _close_raganything_instance(self, client: Any | None) -> None:
        if client is None:
            return
        try:
            close_fn = getattr(client, "close", None)
            if callable(close_fn):
                result = close_fn()
                # Some versions expose sync close, others async close.
                if asyncio.iscoroutine(result):
                    self._run_coro_sync(result)
        except Exception as exc:
            logger.debug("RAG-Anything close failed: %s", exc)

    @staticmethod
    def _graph_model_override(provider: Any) -> str | None:
        if isinstance(provider, OllamaProvider):
            val = os.environ.get("GRAPH_OLLAMA_RAG_MODEL", "").strip()
            return val or None
        return None

    @staticmethod
    def _provider_fingerprint(provider: Any) -> str:
        parts = [
            provider.__class__.__name__,
            str(getattr(provider, "base", "")),
            str(getattr(provider, "rag_model", "")),
            str(getattr(provider, "embed_model", "")),
            str(getattr(provider, "key", ""))[:8],  # enough to detect config changes, avoids logging secrets
        ]
        return "|".join(parts)

    def _graph_raganything_cache_fingerprint(self, provider: Any, *, neo4j_database: str | None = None) -> str:
        db_name = (neo4j_database or os.environ.get("NEO4J_DATABASE", "")).strip()
        return "|".join(
            [
                str(self.working_dir),
                self._provider_fingerprint(provider),
                str(self._graph_model_override(provider) or ""),
                str(db_name),
                str(os.environ.get("GRAPH_TEMPERATURE", "")).strip(),
                str(os.environ.get("OLLAMA_CHAT_TIMEOUT", "")).strip(),
            ]
        )

    def _clone_provider_for_graph(self, provider: Any) -> Any:
        """
        Clone the provider so graph extraction can safely apply graph-specific model overrides
        without mutating the shared request/query provider instance.
        """
        try:
            clone = provider.__class__()  # re-read env-backed config
        except Exception:
            clone = provider
        for attr in ("base", "key", "rag_model", "embed_model"):
            if hasattr(provider, attr):
                try:
                    setattr(clone, attr, getattr(provider, attr))
                except Exception:
                    pass
        graph_model = self._graph_model_override(clone)
        if graph_model and hasattr(clone, "rag_model"):
            setattr(clone, "rag_model", graph_model)
        return clone

    def _run_coro_sync(self, coro: Any) -> Any:
        loop = self._ensure_rag_graph_loop()
        future = asyncio.run_coroutine_threadsafe(coro, loop)
        return future.result()

    def _scrub_raganything_kv_graph_junk(
        self,
        *,
        rag_doc_id: str | None = None,
        full_scan: bool = False,
    ) -> Dict[str, int]:
        """
        Remove boilerplate/junk entities/relations from persisted LightRAG KV JSON stores.

        The official RAG-Anything/LightRAG pipeline may still transiently create these entries,
        but this scrub keeps persisted JSON stores clean for downstream inspection and reuse.
        """
        stats: Dict[str, int] = {
            "full_entities_docs": 0,
            "full_entities_names": 0,
            "full_relations_docs": 0,
            "full_relations_pairs": 0,
            "entity_chunks": 0,
            "relation_chunks": 0,
        }
        if not full_scan and not rag_doc_id:
            return stats

        def _load_json_dict(path: Path) -> Dict[str, Any]:
            try:
                with path.open("r", encoding="utf-8") as fh:
                    data = json.load(fh)
                return data if isinstance(data, dict) else {}
            except FileNotFoundError:
                return {}
            except Exception as exc:
                logger.warning("graph:kv-junk-scrub failed to read %s: %s", path.name, exc)
                return {}

        def _save_json_dict(path: Path, data: Dict[str, Any]) -> None:
            try:
                tmp_path = path.with_suffix(path.suffix + ".tmp")
                with tmp_path.open("w", encoding="utf-8") as fh:
                    json.dump(data, fh, ensure_ascii=False, indent=2)
                    fh.write("\n")
                tmp_path.replace(path)
            except Exception as exc:
                logger.warning("graph:kv-junk-scrub failed to write %s: %s", path.name, exc)

        full_entities_path = self.working_dir / "kv_store_full_entities.json"
        full_relations_path = self.working_dir / "kv_store_full_relations.json"
        entity_chunks_path = self.working_dir / "kv_store_entity_chunks.json"
        relation_chunks_path = self.working_dir / "kv_store_relation_chunks.json"

        removed_entity_names: set[str] = set()
        removed_relation_pairs: set[tuple[str, str]] = set()

        full_entities = _load_json_dict(full_entities_path)
        full_entities_changed = False
        if full_entities:
            target_keys = list(full_entities.keys()) if full_scan else ([rag_doc_id] if rag_doc_id in full_entities else [])
            for key in target_keys:
                rec = full_entities.get(key)
                if not isinstance(rec, dict):
                    continue
                names = rec.get("entity_names")
                if not isinstance(names, list):
                    continue
                kept: list[Any] = []
                removed_here = 0
                for name in names:
                    if _is_junk_graph_label(name):
                        removed_entity_names.add(str(name))
                        removed_here += 1
                    else:
                        kept.append(name)
                if not removed_here:
                    continue
                full_entities_changed = True
                stats["full_entities_docs"] += 1
                stats["full_entities_names"] += removed_here
                if kept:
                    rec["entity_names"] = kept
                    rec["count"] = len(kept)
                else:
                    full_entities.pop(key, None)
        if full_entities_changed:
            _save_json_dict(full_entities_path, full_entities)

        full_relations = _load_json_dict(full_relations_path)
        full_relations_changed = False
        if full_relations:
            target_keys = list(full_relations.keys()) if full_scan else ([rag_doc_id] if rag_doc_id in full_relations else [])
            for key in target_keys:
                rec = full_relations.get(key)
                if not isinstance(rec, dict):
                    continue
                pairs = rec.get("relation_pairs")
                if not isinstance(pairs, list):
                    continue
                kept_pairs: list[Any] = []
                removed_here = 0
                for pair in pairs:
                    if not (isinstance(pair, (list, tuple)) and len(pair) >= 2):
                        kept_pairs.append(pair)
                        continue
                    src = str(pair[0] or "")
                    tgt = str(pair[1] or "")
                    if _is_junk_graph_label(src) or _is_junk_graph_label(tgt):
                        removed_relation_pairs.add((src, tgt))
                        removed_here += 1
                        continue
                    kept_pairs.append(pair)
                if not removed_here:
                    continue
                full_relations_changed = True
                stats["full_relations_docs"] += 1
                stats["full_relations_pairs"] += removed_here
                if kept_pairs:
                    rec["relation_pairs"] = kept_pairs
                    rec["count"] = len(kept_pairs)
                else:
                    full_relations.pop(key, None)
        if full_relations_changed:
            _save_json_dict(full_relations_path, full_relations)

        if not full_scan and not removed_entity_names and not removed_relation_pairs:
            return stats

        entity_chunks = _load_json_dict(entity_chunks_path)
        entity_chunks_changed = False
        if entity_chunks:
            if full_scan:
                keys_to_drop = [k for k in list(entity_chunks.keys()) if _is_junk_graph_label(k)]
            else:
                keys_to_drop = [k for k in removed_entity_names if k in entity_chunks]
            if keys_to_drop:
                entity_chunks_changed = True
                stats["entity_chunks"] += len(keys_to_drop)
                for key in keys_to_drop:
                    entity_chunks.pop(key, None)
        if entity_chunks_changed:
            _save_json_dict(entity_chunks_path, entity_chunks)

        relation_chunks = _load_json_dict(relation_chunks_path)
        relation_chunks_changed = False
        if relation_chunks:
            keys_to_drop: list[str] = []
            if full_scan:
                for key in list(relation_chunks.keys()):
                    if not isinstance(key, str) or "<SEP>" not in key:
                        continue
                    src, tgt = key.split("<SEP>", 1)
                    if _is_junk_graph_label(src) or _is_junk_graph_label(tgt):
                        keys_to_drop.append(key)
            else:
                for src, tgt in removed_relation_pairs:
                    key = f"{src}<SEP>{tgt}"
                    if key in relation_chunks:
                        keys_to_drop.append(key)
                if removed_entity_names:
                    removed_lower = {x.lower() for x in removed_entity_names}
                    for key in list(relation_chunks.keys()):
                        if not isinstance(key, str) or "<SEP>" not in key:
                            continue
                        src, tgt = key.split("<SEP>", 1)
                        if src.lower() in removed_lower or tgt.lower() in removed_lower:
                            if key not in keys_to_drop:
                                keys_to_drop.append(key)
            if keys_to_drop:
                relation_chunks_changed = True
                stats["relation_chunks"] += len(keys_to_drop)
                for key in keys_to_drop:
                    relation_chunks.pop(key, None)
        if relation_chunks_changed:
            _save_json_dict(relation_chunks_path, relation_chunks)

        if sum(stats.values()):
            logger.info(
                "graph:kv-junk-scrub stats=%s rag_doc_id=%s full_scan=%s",
                stats,
                rag_doc_id or "-",
                full_scan,
            )
        return stats

    @staticmethod
    def _register_chunked_doc_status_storage() -> bool:
        """
        Register a custom LightRAG doc-status backend that writes chunked JSON files.

        We intentionally keep Neo4j as graph storage only. LightRAG's doc-status storage is
        KV-like operational metadata and is better served by JSON/Redis/Postgres.
        """
        storage_name = "ChunkedJsonDocStatusStorage"
        try:
            import lightrag.kg as lightrag_kg  # type: ignore

            implementations = lightrag_kg.STORAGE_IMPLEMENTATIONS["DOC_STATUS_STORAGE"]["implementations"]
            if storage_name not in implementations:
                implementations.append(storage_name)

            lightrag_kg.STORAGE_ENV_REQUIREMENTS.setdefault(storage_name, [])
            # Absolute module path so LightRAG can import from this application package.
            lightrag_kg.STORAGES[storage_name] = "core.lightrag_chunked_doc_status_storage"
            return True
        except Exception as exc:
            logger.warning("Failed to register chunked LightRAG doc status storage: %s", exc)
            return False

    @staticmethod
    def _prepare_lightrag_neo4j_env(neo4j_database: str | None = None) -> tuple[bool, dict[str, str]]:
        """
        Bridge project env names to LightRAG Neo4j env names.

        Project envs:
        - NEO4J_HTTP_URL / NEO4J_USER / NEO4J_PASSWORD
        Optional:
        - NEO4J_BOLT_URL

        LightRAG Neo4jStorage expects:
        - NEO4J_URI / NEO4J_USERNAME / NEO4J_PASSWORD
        """
        applied: dict[str, str] = {}
        neo4j_user = os.environ.get("NEO4J_USER", "").strip()
        neo4j_pwd = os.environ.get("NEO4J_PASSWORD", "").strip()
        neo4j_uri = os.environ.get("NEO4J_URI", "").strip()

        if not neo4j_uri:
            neo4j_bolt = os.environ.get("NEO4J_BOLT_URL", "").strip()
            if neo4j_bolt:
                neo4j_uri = neo4j_bolt
            else:
                http_url = os.environ.get("NEO4J_HTTP_URL", "").strip()
                if http_url:
                    # Best-effort derivation from http(s)://host:7474 -> bolt://host:7687
                    neo4j_uri = re.sub(r"^https?://", "bolt://", http_url)
                    neo4j_uri = re.sub(r":7474(?=/|$)", ":7687", neo4j_uri)
                    neo4j_uri = re.sub(r":7473(?=/|$)", ":7687", neo4j_uri)
            if neo4j_uri:
                os.environ["NEO4J_URI"] = neo4j_uri
                applied["NEO4J_URI"] = neo4j_uri

        if neo4j_user and not os.environ.get("NEO4J_USERNAME", "").strip():
            os.environ["NEO4J_USERNAME"] = neo4j_user
            applied["NEO4J_USERNAME"] = neo4j_user

        db_name = (neo4j_database or "").strip()
        if db_name:
            os.environ["NEO4J_DATABASE"] = db_name
            applied["NEO4J_DATABASE"] = db_name

        # NEO4J_PASSWORD name already matches LightRAG; only report if present.
        if neo4j_pwd:
            applied["NEO4J_PASSWORD"] = "***"

        ready = bool(
            os.environ.get("NEO4J_URI", "").strip()
            and os.environ.get("NEO4J_USERNAME", "").strip()
            and os.environ.get("NEO4J_PASSWORD", "").strip()
        )
        return ready, applied

    def graph_runtime_summary(self) -> Dict[str, Any]:
        """
        Return a lightweight runtime summary for the UI monitor.

        This intentionally includes only operational metadata (no secrets).
        """
        chunk_files = sorted(self.working_dir.glob("kv_store_doc_status_chunk_*.json"))

        def _env_int(name: str) -> int | None:
            raw = str(os.environ.get(name, "")).strip()
            if not raw:
                return None
            try:
                return int(raw)
            except ValueError:
                return None

        def _env_bool(name: str, default: bool = False) -> bool:
            raw = str(os.environ.get(name, "")).strip().lower()
            if not raw:
                return default
            return raw in ("1", "true", "yes", "on")

        with self._rag_graph_lock:
            meta = dict(self._rag_graph_runtime_meta)
            initialized = bool(self.raganything is not None)
            cache_key = self._rag_graph_cache_key

        return {
            "working_dir": str(self.working_dir),
            "graph_client_initialized": initialized,
            "graph_client_cache_key": bool(cache_key),
            "doc_status_storage": meta.get("doc_status_storage", "JsonDocStatusStorage"),
            "graph_storage": meta.get("graph_storage", "NetworkXStorage(default)"),
            "neo4j": {
                "uri": str(os.environ.get("NEO4J_URI", "")).strip()
                or str(os.environ.get("NEO4J_BOLT_URL", "")).strip()
                or "",
                "database": str(os.environ.get("NEO4J_DATABASE", "")).strip() or "neo4j (default)",
                "user": str(os.environ.get("NEO4J_USERNAME", "")).strip()
                or str(os.environ.get("NEO4J_USER", "")).strip()
                or "",
            },
            "doc_status_chunks": {
                "pattern": "kv_store_doc_status_chunk_*.json",
                "count": len(chunk_files),
                "files": [p.name for p in chunk_files[:5]],
            },
            "models": {
                "graph_model": str(os.environ.get("GRAPH_OLLAMA_RAG_MODEL", "")).strip()
                or str(os.environ.get("OLLAMA_RAG_MODEL", "")).strip(),
                "embed_model": str(os.environ.get("OLLAMA_EMBED_MODEL", "")).strip(),
            },
            "limits": {
                "graph_doc_max_chars": _env_int("GRAPH_DOC_MAX_CHARS"),
                "graph_doc_max_chunks": _env_int("GRAPH_DOC_MAX_CHUNKS"),
                "graph_min_chunk_chars": _env_int("GRAPH_MIN_CHUNK_CHARS"),
                "graph_min_doc_chars": _env_int("GRAPH_MIN_DOC_CHARS"),
                "ollama_chat_timeout": _env_int("OLLAMA_CHAT_TIMEOUT"),
            },
            "resilience": {
                "embed_nan_zero_fallback": _env_bool("OLLAMA_EMBED_NAN_ZERO_FALLBACK", True),
                "graph_embed_junk_filter": True,
                "graph_embed_junk_strict": _env_bool("GRAPH_EMBED_JUNK_STRICT", True),
                "graph_embed_junk_denylist_configured": bool(
                    str(os.environ.get("GRAPH_EMBED_JUNK_DENYLIST", "")).strip()
                ),
                "graph_embed_junk_allowlist_configured": bool(
                    str(os.environ.get("GRAPH_EMBED_JUNK_ALLOWLIST", "")).strip()
                ),
            },
        }

    def _init_raganything_graph_client(self, provider: Any, *, neo4j_database: str | None = None) -> Optional[Any]:
        """
        Build an official RAG-Anything client using provider-backed LLM and embedding callables.

        This avoids the previous brittle direct LightRAG extraction hook and uses the package's
        documented content insertion / internal KG pipeline (`insert_content_list`).
        """
        try:
            from raganything import RAGAnything  # type: ignore
            from raganything.config import RAGAnythingConfig  # type: ignore
        except Exception as exc:
            logger.info("RAG-Anything import failed: %s", exc)
            return None

        try:
            from lightrag.utils import EmbeddingFunc  # type: ignore
            import numpy as np  # type: ignore
        except Exception as exc:
            logger.info("LightRAG embedding wrapper import failed: %s", exc)
            return None

        graph_provider = self._clone_provider_for_graph(provider)

        embed_model_name = str(getattr(graph_provider, "embed_model", "") or "").lower()
        if "bge-m3" in embed_model_name:
            embed_dim = 1024
        elif "text-embedding-3-large" in embed_model_name:
            embed_dim = 3072
        elif "text-embedding-3-small" in embed_model_name:
            embed_dim = 1536
        else:
            embed_dim = 1024

        async def llm_model_func(
            prompt: str,
            system_prompt: str | None = None,
            history_messages: list | None = None,
            max_tokens: int | None = None,
            **kwargs: Any,
        ) -> str:
            del max_tokens, kwargs
            messages = list(history_messages or [])
            messages.append({"role": "user", "content": prompt})
            system = system_prompt or "You are a helpful assistant."

            graph_temp_env = os.environ.get("GRAPH_TEMPERATURE", "").strip()
            if graph_temp_env:
                try:
                    temperature = float(graph_temp_env)
                except ValueError:
                    temperature = None
            else:
                temperature = 0.0

            if GRAPH_DEBUG_LLM:
                logger.debug("graph:raganything llm system=%s", system)
                logger.debug("graph:raganything llm prompt=%s", prompt)
            response = await asyncio.to_thread(graph_provider.chat, system, messages, temperature=temperature)
            if GRAPH_DEBUG_LLM:
                logger.debug("graph:raganything llm response=%s", response)
            return response

        async def embed_many(texts: Any) -> Any:
            text_list = [texts] if isinstance(texts, str) else list(texts or [])
            if not text_list:
                return np.zeros((0, 0), dtype=float)
            out_vectors: list[Any] = [None] * len(text_list)
            embed_jobs: list[Any] = []
            embed_job_indices: list[int] = []
            filtered = 0
            filtered_samples: list[str] = []

            for idx, raw in enumerate(text_list):
                text_norm = _normalize_graph_embed_text(raw)
                reason = _graph_embed_junk_reason(text_norm)
                if reason is not None:
                    filtered += 1
                    out_vectors[idx] = _junk_embedding_sentinel(text_norm or str(raw or ""), embed_dim)
                    if GRAPH_DEBUG and len(filtered_samples) < 3:
                        sample = text_norm[:80] if text_norm else str(raw or "")[:80]
                        filtered_samples.append(f"{reason}:{sample}")
                    continue

                embed_jobs.append(asyncio.to_thread(graph_provider.embed, text_norm))
                embed_job_indices.append(idx)

            if embed_jobs:
                vectors = await asyncio.gather(*embed_jobs)
                for idx, vec in zip(embed_job_indices, vectors):
                    out_vectors[idx] = vec

            if filtered and GRAPH_DEBUG:
                logger.debug(
                    "graph:embed_many junk-filtered=%s/%s samples=%s",
                    filtered,
                    len(text_list),
                    filtered_samples,
                )

            # Fill any unexpected gaps defensively.
            for idx, value in enumerate(out_vectors):
                if value is None:
                    out_vectors[idx] = _junk_embedding_sentinel(str(text_list[idx] or ""), embed_dim)

            return np.asarray(out_vectors, dtype=float)

        emb_func = EmbeddingFunc(
            embedding_dim=embed_dim,
            func=embed_many,
            max_token_size=8192,
            model_name=(getattr(graph_provider, "embed_model", None) or None),
        )

        config = RAGAnythingConfig(
            working_dir=str(self.working_dir),
            parser_output_dir=str(self.working_dir / "parser_output"),
            parse_method="auto",
            parser="mineru",
            display_content_stats=GRAPH_DEBUG,
            max_concurrent_files=1,
        )

        chunked_doc_status_ok = self._register_chunked_doc_status_storage()
        neo4j_graph_ok, neo4j_env_applied = self._prepare_lightrag_neo4j_env(neo4j_database)
        lightrag_kwargs: Dict[str, Any] = {}
        if chunked_doc_status_ok:
            lightrag_kwargs["doc_status_storage"] = "ChunkedJsonDocStatusStorage"
        if neo4j_graph_ok:
            lightrag_kwargs["graph_storage"] = "Neo4JStorage"
        else:
            logger.warning(
                "LightRAG Neo4JStorage not enabled (missing NEO4J_URI/NEO4J_USERNAME/NEO4J_PASSWORD); using default graph storage"
            )

        try:
            client = RAGAnything(
                llm_model_func=llm_model_func,
                embedding_func=emb_func,
                config=config,
                lightrag_kwargs=lightrag_kwargs,
            )
            logger.info(
                "RAG-Anything graph client initialized (working_dir=%s, provider=%s, rag_model=%s, embed_model=%s, doc_status_storage=%s, graph_storage=%s)",
                self.working_dir,
                graph_provider.__class__.__name__,
                getattr(graph_provider, "rag_model", None),
                getattr(graph_provider, "embed_model", None),
                lightrag_kwargs.get("doc_status_storage", "JsonDocStatusStorage"),
                lightrag_kwargs.get("graph_storage", "NetworkXStorage(default)"),
            )
            self._rag_graph_runtime_meta = {
                "doc_status_storage": lightrag_kwargs.get("doc_status_storage", "JsonDocStatusStorage"),
                "graph_storage": lightrag_kwargs.get("graph_storage", "NetworkXStorage(default)"),
                "graph_client_initialized": True,
            }
            if neo4j_env_applied:
                logger.info(
                    "LightRAG Neo4j env prepared: %s",
                    {k: v for k, v in neo4j_env_applied.items()},
                )
            return client
        except Exception as exc:
            self._rag_graph_runtime_meta = {
                "doc_status_storage": lightrag_kwargs.get("doc_status_storage", "JsonDocStatusStorage"),
                "graph_storage": lightrag_kwargs.get("graph_storage", "NetworkXStorage(default)"),
                "graph_client_initialized": False,
                "init_error": str(exc),
            }
            logger.info("RAG-Anything graph client init failed: %s", exc)
            return None

    def _get_or_create_raganything_graph_client(self, provider: Any, *, neo4j_database: str | None = None) -> Optional[Any]:
        cache_key = self._graph_raganything_cache_fingerprint(provider, neo4j_database=neo4j_database)
        with self._rag_graph_lock:
            if self.raganything is not None and self._rag_graph_cache_key == cache_key:
                return self.raganything

            if self.raganything is not None and self._rag_graph_cache_key != cache_key:
                self._close_raganything_instance(self.raganything)
                self.raganything = None
                self._rag_graph_cache_key = None

            async def _build_client() -> Optional[Any]:
                return self._init_raganything_graph_client(provider, neo4j_database=neo4j_database)

            client = self._run_coro_sync(_build_client())
            self.raganything = client
            self._rag_graph_cache_key = cache_key if client is not None else None
            if client is not None and not self._rag_graph_kv_junk_scrub_once_done:
                try:
                    self._scrub_raganything_kv_graph_junk(full_scan=True)
                finally:
                    self._rag_graph_kv_junk_scrub_once_done = True
            return self.raganything

    def get_provider(self, name: str):
        key = (name or "").strip().lower()
        if key == "ollama":
            return OllamaProvider()
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
        neo4j_database: str | None = None,
    ) -> List[tuple[str, str, str]]:
        fn_start = time.perf_counter()
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
        provider = provider or self.get_provider(os.environ.get("GRAPH_PROVIDER", "ollama"))

        # Keep a lightweight cleanup pass for noisy markdown/table rows before handing text
        # to the official RAG-Anything content ingestion path.
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

        rag_start = time.perf_counter()
        trips = self._extract_triplets_raganything(
            cleaned_text if cleaned_text.strip() else text,
            provider=provider,
            chunks=cleaned_chunks if cleaned_chunks is not None else chunks,
            doc_id=doc_id,
            file_path=file_path,
            neo4j_database=neo4j_database,
        )
        _perf_log(
            "perf:graph core.rag_service.extract_triplets step=raganything_insert_export triplets=%s ms=%.2f",
            len(trips),
            (time.perf_counter() - rag_start) * 1000,
        )
        logger.info("graph:extract_triplets raganything-kg count=%s", len(trips))
        _perf_log(
            "perf:graph core.rag_service.extract_triplets done path=%s triplets=%s ms=%.2f",
            "raganything_official_kg",
            len(trips),
            (time.perf_counter() - fn_start) * 1000,
        )
        return trips

    @staticmethod
    def _graph_content_list_from_input(text: str, chunks: List[str] | None) -> List[Dict[str, Any]]:
        parts = chunks if chunks is not None else [text]
        out: List[Dict[str, Any]] = []

        for idx, part in enumerate(parts):
            if not isinstance(part, str):
                continue
            value = part.strip()
            if not value:
                continue
            out.append({"type": "text", "text": value, "page_idx": idx})
        return out

    @staticmethod
    def _stable_raganything_doc_id(doc_id: str | None, file_path: str | None, content_list: List[Dict[str, Any]]) -> str:
        content_text = "\n".join(str(item.get("text") or "") for item in content_list if isinstance(item, dict))
        digest = hashlib.sha1(content_text.encode("utf-8", errors="ignore")).hexdigest()[:16]
        prefix = str(doc_id or file_path or "graph_doc")
        return f"{prefix}:{digest}"

    @staticmethod
    def _edge_relation_label(edge: Dict[str, Any]) -> str:
        raw = edge.get("keywords") or edge.get("description") or "RELATED_TO"
        if isinstance(raw, (list, tuple)):
            raw = ", ".join(str(x) for x in raw if str(x).strip())
        rel = _strip_control_chars(str(raw)).replace("\n", " ").strip()
        if "," in rel:
            rel = rel.split(",", 1)[0].strip()
        rel = re.sub(r"\s+", " ", rel)
        if len(rel) > 120:
            rel = rel[:120].rstrip()
        return rel or "RELATED_TO"

    def _triplets_from_raganything_edges(
        self,
        *,
        edges: List[Dict[str, Any]],
        file_ref: str,
        created_at_floor: int,
    ) -> List[tuple[str, str, str]]:
        file_edges: List[Dict[str, Any]] = []
        recent_file_edges: List[Dict[str, Any]] = []
        for edge in edges or []:
            if not isinstance(edge, dict):
                continue
            edge_file = str(edge.get("file_path") or "")
            if edge_file != file_ref:
                continue
            file_edges.append(edge)
            created_raw = edge.get("created_at")
            try:
                created_at = int(created_raw)
            except Exception:
                created_at = 0
            if created_at >= max(0, created_at_floor - 1):
                recent_file_edges.append(edge)

        # Prefer edges produced by the current insert call. If the document was deduplicated by
        # RAG-Anything, fall back to all edges currently known for the same file reference.
        selected = recent_file_edges or file_edges
        if GRAPH_DEBUG:
            logger.debug(
                "graph:raganything export edges total=%s file=%s file_edges=%s recent=%s selected=%s",
                len(edges or []),
                file_ref,
                len(file_edges),
                len(recent_file_edges),
                len(selected),
            )

        triplets: List[tuple[str, str, str]] = []
        for edge in selected:
            src = str(edge.get("source") or "").strip()
            tgt = str(edge.get("target") or "").strip()
            if not src or not tgt:
                continue
            if _is_junk_graph_label(src) or _is_junk_graph_label(tgt):
                continue
            triplets.append((src, self._edge_relation_label(edge), tgt))
        return _dedupe_triplets(triplets)

    def _extract_triplets_raganything(
        self,
        text: str,
        *,
        provider: Any,
        chunks: List[str] | None,
        doc_id: str | None,
        file_path: str | None,
        neo4j_database: str | None,
    ) -> List[tuple[str, str, str]]:
        content_list = self._graph_content_list_from_input(text, chunks)
        if not content_list:
            logger.info("graph:extract_triplets skipping empty/tiny content doc_id=%s", doc_id or "-")
            return []

        total_chars = sum(len(str(item.get("text") or "")) for item in content_list)

        client = self._get_or_create_raganything_graph_client(provider, neo4j_database=neo4j_database)
        if client is None:
            logger.warning("RAG-Anything graph client is not initialized; returning 0 triplets.")
            return []

        file_ref = str(file_path or f"inline://{doc_id or 'graph_text'}")
        rag_doc_id = self._stable_raganything_doc_id(doc_id, file_ref, content_list)
        logger.info(
            "RAG-Anything graph insert requested doc_id=%s rag_doc_id=%s file=%s blocks=%s chars=%s",
            doc_id or "-",
            rag_doc_id,
            file_ref,
            len(content_list),
            total_chars,
        )
        created_floor = int(time.time())

        async def _insert_and_export() -> List[tuple[str, str, str]]:
            # RAG-Anything currently enforces parser installation inside
            # `_ensure_lightrag_initialized()` even for direct text-only `content_list` inserts.
            # Our graph ingest path passes only text blocks, so bypass that parser gate to allow
            # LightRAG initialization without MinerU/Docling installed.
            if content_list and all(
                isinstance(item, dict) and str(item.get("type") or "").lower() == "text"
                for item in content_list
            ):
                try:
                    if hasattr(client, "_parser_installation_checked"):
                        setattr(client, "_parser_installation_checked", True)
                except Exception:
                    pass
            try:
                await client.insert_content_list(
                    content_list,
                    file_path=file_ref,
                    doc_id=rag_doc_id,
                    display_stats=GRAPH_DEBUG,
                )
            except Exception as exc:
                logger.warning("RAG-Anything insert_content_list failed: %s", exc)
                return []

            # Remove boilerplate/chrome labels from persisted LightRAG KV stores for this doc
            # before exporting graph edges or subsequent runs read them again.
            self._scrub_raganything_kv_graph_junk(rag_doc_id=rag_doc_id)

            lightrag_obj = getattr(client, "lightrag", None)
            graph_obj = getattr(lightrag_obj, "chunk_entity_relation_graph", None)
            if graph_obj is None:
                logger.warning("RAG-Anything graph storage is unavailable after insert.")
                return []

            try:
                edges = await graph_obj.get_all_edges()
            except Exception as exc:
                logger.warning("RAG-Anything graph edge export failed: %s", exc)
                return []

            if not isinstance(edges, list):
                logger.warning(
                    "RAG-Anything graph edge export returned unexpected type=%s",
                    type(edges).__name__,
                )
                return []
            triplets = self._triplets_from_raganything_edges(
                edges=edges,
                file_ref=file_ref,
                created_at_floor=created_floor,
            )
            logger.info(
                "RAG-Anything graph export doc_id=%s file=%s edges_total=%s triplets=%s",
                doc_id or "-",
                file_ref,
                len(edges),
                len(triplets),
            )
            return triplets

        with self._rag_graph_lock:
            return self._run_coro_sync(_insert_and_export())

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
