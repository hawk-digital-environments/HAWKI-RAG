"""Graph extraction orchestration for RAG-Anything."""

from __future__ import annotations

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

from core.graph.cache import clear_graph_cache_files
from core.graph.edge_parser import (
    triplets_from_raganything_edges as _triplets_from_raganything_edges_raw,
)
from core.graph.fallback_parser import (
    parse_raganything_llm_cache,
    relation_label_from_text,
)
from core.graph.provider_config import (
    clone_provider_for_graph,
    graph_model_override,
    provider_fingerprint,
)
from core.graph.text import clean_graph_text

logger = logging.getLogger(__name__)


def _perf_log(msg: str, *args: Any) -> None:
    if os.environ.get("GRAPH_PERF_LOG", "").strip().lower() in ("1", "true", "yes"):
        logger.info(msg, *args)


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
    text = str(raw or "").strip()
    if not text:
        return ()
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

    if any(marker in text for marker in ("<|#|>", "<|#", "<|COMPLETE|>", "|#|")):
        return "delimiter_residue"
    if lower.startswith(("entity<", "relation<", "realtion<")):
        return "record_marker_residue"

    if text.endswith(("<", "|", "~", "`")) and alnum < 24:
        return "truncated_token"

    separator_count = sum(text.count(ch) for ch in ("<", ">", "|", "~", "`"))
    if separator_count >= 2 and alnum < 20:
        return "separator_heavy_fragment"

    if "n/a" in lower and alnum < 20:
        return "na_fragment"

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
    out: list[tuple[str, str, str]] = []
    for s, r, o in triplets:
        key = (s.strip(), r.strip(), o.strip())
        if not all(key) or key in seen:
            continue
        seen.add(key)
        out.append((key[0], key[1], key[2]))
    return out


class RagAnythingGraphService:
    """Owns graph extraction state and RAG-Anything lifecycle."""

    def __init__(self, working_dir: Path, logger_obj: logging.Logger | None = None) -> None:
        self.working_dir = Path(working_dir).expanduser()
        self.working_dir.mkdir(parents=True, exist_ok=True)
        self.logger = logger_obj or logger
        self._rag_graph_lock = threading.RLock()
        self._rag_graph_cache_key: str | None = None
        self.client: Any | None = None
        self._rag_graph_loop: Any | None = None
        self._rag_graph_loop_thread: threading.Thread | None = None
        self._rag_graph_loop_ready = threading.Event()
        self._rag_graph_runtime_meta: Dict[str, Any] = {
            "doc_status_storage": "JsonDocStatusStorage",
            "graph_storage": "NetworkXStorage(default)",
            "graph_client_initialized": False,
        }
        self._rag_graph_kv_junk_scrub_once_done = False

    @staticmethod
    def _graph_model_override(provider: Any) -> str | None:
        return graph_model_override(provider)

    @staticmethod
    def _provider_fingerprint(provider: Any) -> str:
        return provider_fingerprint(provider)

    def graph_cache_fingerprint(self, provider: Any, *, neo4j_database: str | None = None) -> str:
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

    def _run_coro_sync(self, coro: Any) -> Any:
        loop = self._ensure_rag_graph_loop()
        future = asyncio.run_coroutine_threadsafe(coro, loop)
        return future.result()

    def _close_raganything_instance(self, client: Any | None) -> None:
        if client is None:
            return
        try:
            close_fn = getattr(client, "close", None)
            if callable(close_fn):
                result = close_fn()
                if asyncio.iscoroutine(result):
                    self._run_coro_sync(result)
        except Exception as exc:
            logger.debug("RAG-Anything close failed: %s", exc)

    def clear_graph_cache(self) -> Dict[str, Any]:
        with self._rag_graph_lock:
            self._close_raganything_instance(self.client)
            self.client = None
            self._rag_graph_cache_key = None
            self._rag_graph_kv_junk_scrub_once_done = False
            return clear_graph_cache_files(self.working_dir)

    @staticmethod
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

    @staticmethod
    def _save_json_dict(path: Path, data: Dict[str, Any]) -> None:
        try:
            tmp_path = path.with_suffix(path.suffix + ".tmp")
            with tmp_path.open("w", encoding="utf-8") as fh:
                json.dump(data, fh, ensure_ascii=False, indent=2)
                fh.write("\n")
            tmp_path.replace(path)
        except Exception as exc:
            logger.warning("graph:kv-junk-scrub failed to write %s: %s", path.name, exc)

    def scrub_raganything_kv_graph_junk(
        self,
        *,
        rag_doc_id: str | None = None,
        full_scan: bool = False,
    ) -> Dict[str, int]:
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

        full_entities_path = self.working_dir / "kv_store_full_entities.json"
        full_relations_path = self.working_dir / "kv_store_full_relations.json"
        entity_chunks_path = self.working_dir / "kv_store_entity_chunks.json"
        relation_chunks_path = self.working_dir / "kv_store_relation_chunks.json"

        removed_entity_names: set[str] = set()
        removed_relation_pairs: set[tuple[str, str]] = set()

        full_entities = self._load_json_dict(full_entities_path)
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
            self._save_json_dict(full_entities_path, full_entities)

        full_relations = self._load_json_dict(full_relations_path)
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
            self._save_json_dict(full_relations_path, full_relations)

        if not full_scan and not removed_entity_names and not removed_relation_pairs:
            return stats

        entity_chunks = self._load_json_dict(entity_chunks_path)
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
            self._save_json_dict(entity_chunks_path, entity_chunks)

        relation_chunks = self._load_json_dict(relation_chunks_path)
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
            self._save_json_dict(relation_chunks_path, relation_chunks)

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
        storage_name = "ChunkedJsonDocStatusStorage"
        try:
            import lightrag.kg as lightrag_kg  # type: ignore

            implementations = lightrag_kg.STORAGE_IMPLEMENTATIONS["DOC_STATUS_STORAGE"]["implementations"]
            if storage_name not in implementations:
                implementations.append(storage_name)

            lightrag_kg.STORAGE_ENV_REQUIREMENTS.setdefault(storage_name, [])
            lightrag_kg.STORAGES[storage_name] = "core.lightrag_chunked_doc_status_storage"
            return True
        except Exception as exc:
            logger.warning("Failed to register chunked LightRAG doc status storage: %s", exc)
            return False

    @staticmethod
    def _prepare_lightrag_neo4j_env(neo4j_database: str | None = None) -> tuple[bool, dict[str, str]]:
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

        if neo4j_pwd:
            applied["NEO4J_PASSWORD"] = "***"

        ready = bool(
            os.environ.get("NEO4J_URI", "").strip()
            and os.environ.get("NEO4J_USERNAME", "").strip()
            and os.environ.get("NEO4J_PASSWORD", "").strip()
        )
        return ready, applied

    @staticmethod
    def _graph_runtime_summary_limits() -> Dict[str, int | None]:
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

        return {
            "graph_doc_max_chars": _env_int("GRAPH_DOC_MAX_CHARS"),
            "graph_doc_max_chunks": _env_int("GRAPH_DOC_MAX_CHUNKS"),
            "graph_min_chunk_chars": _env_int("GRAPH_MIN_CHUNK_CHARS"),
            "graph_min_doc_chars": _env_int("GRAPH_MIN_DOC_CHARS"),
            "ollama_chat_timeout": _env_int("OLLAMA_CHAT_TIMEOUT"),
        }  # type: ignore[return-value]

    def graph_runtime_summary(self) -> Dict[str, Any]:
        chunk_files = sorted(self.working_dir.glob("kv_store_doc_status_chunk_*.json"))
        with self._rag_graph_lock:
            meta = dict(self._rag_graph_runtime_meta)
            initialized = bool(self.client is not None)
        limits = self._graph_runtime_summary_limits()
        return {
            "working_dir": str(self.working_dir),
            "graph_client_initialized": initialized,
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
            "limits": limits,
            "resilience": {
                "embed_nan_zero_fallback": _env_truthy("OLLAMA_EMBED_NAN_ZERO_FALLBACK", True),
                "graph_embed_junk_filter": True,
                "graph_embed_junk_strict": _env_truthy("GRAPH_EMBED_JUNK_STRICT", True),
                "graph_embed_junk_denylist_configured": bool(str(os.environ.get("GRAPH_EMBED_JUNK_DENYLIST", "")).strip()),
                "graph_embed_junk_allowlist_configured": bool(str(os.environ.get("GRAPH_EMBED_JUNK_ALLOWLIST", "")).strip()),
            },
        }

    @staticmethod
    def _clone_provider_for_graph(provider: Any) -> Any:
        return clone_provider_for_graph(provider)

    @staticmethod
    def graph_content_list_from_input(text: str, chunks: List[str] | None) -> List[Dict[str, Any]]:
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
    def stable_raganything_doc_id(doc_id: str | None, file_path: str | None, content_list: List[Dict[str, Any]]) -> str:
        content_text = "\n".join(str(item.get("text") or "") for item in content_list if isinstance(item, dict))
        digest = hashlib.sha1(content_text.encode("utf-8", errors="ignore")).hexdigest()[:16]
        prefix = str(doc_id or file_path or "graph_doc")
        return f"{prefix}:{digest}"

    def raganything_extraction_doc_id(
        self, doc_id: str | None, file_path: str | None, content_list: List[Dict[str, Any]]
    ) -> str:
        stable_id = self.stable_raganything_doc_id(doc_id, file_path, content_list)
        return f"{stable_id}:extract:{time.time_ns()}"

    @staticmethod
    def raganything_file_ref(doc_id: str | None, file_path: str | None) -> str:
        if not file_path:
            return f"inline://{doc_id or 'graph_text'}"

        raw_path = str(file_path)
        source = Path(raw_path)
        safe_doc_id = re.sub(r"[^A-Za-z0-9_.-]+", "_", str(doc_id or "graph_doc")).strip("._-") or "graph_doc"
        unique_name = f"{safe_doc_id}__{source.name or 'content.md'}"

        if source.parent and str(source.parent) not in ("", "."):
            return str(source.with_name(unique_name))
        return unique_name

    @staticmethod
    def _clear_lightrag_neo4j_temp_graph(neo4j_database: str | None = None) -> None:
        try:
            from neo4j import GraphDatabase  # type: ignore
        except Exception as exc:
            logger.debug("LightRAG Neo4j temp graph cleanup skipped; driver unavailable: %s", exc)
            return

        uri = os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
        user = os.environ.get("NEO4J_USER") or os.environ.get("NEO4J_USERNAME") or "neo4j"
        password = os.environ.get("NEO4J_PASSWORD", "password")
        database = (neo4j_database or os.environ.get("NEO4J_DATABASE") or "").strip() or None

        driver = GraphDatabase.driver(uri, auth=(user, password))
        try:
            session_kwargs = {"database": database} if database else {}
            with driver.session(**session_kwargs) as session:
                session.execute_write(lambda tx: tx.run("MATCH (n:base) DETACH DELETE n").consume())
        except Exception as exc:
            logger.debug("LightRAG Neo4j temp graph cleanup failed: %s", exc)
        finally:
            driver.close()

    @staticmethod
    def relation_label_from_text(raw: str) -> str:
        return relation_label_from_text(raw)

    def triplets_from_edges(self, *, edges: List[Dict[str, Any]], file_ref: str, created_at_floor: int) -> List[tuple[str, str, str]]:
        return _triplets_from_raganything_edges_raw(
            edges=edges, file_ref=file_ref, created_at_floor=created_at_floor, graph_debug=False
        )

    def triplets_from_llm_cache(self) -> List[tuple[str, str, str]]:
        cache_path = self.working_dir / "kv_store_llm_response_cache.json"
        return parse_raganything_llm_cache(cache_path)

    def _init_client(self, provider: Any, *, neo4j_database: str | None = None) -> Optional[Any]:
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

            if os.environ.get("GRAPH_DEBUG_LLM", "").strip().lower() in ("1", "true", "yes"):
                logger.debug("graph:raganything llm system=%s", system)
                logger.debug("graph:raganything llm prompt=%s", prompt)
            response = await asyncio.to_thread(graph_provider.chat, system, messages, temperature=temperature)
            if os.environ.get("GRAPH_DEBUG_LLM", "").strip().lower() in ("1", "true", "yes"):
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
                    if os.environ.get("GRAPH_DEBUG", "").strip().lower() in ("1", "true", "yes") and len(filtered_samples) < 3:
                        sample = text_norm[:80] if text_norm else str(raw or "")[:80]
                        filtered_samples.append(f"{reason}:{sample}")
                    continue

                embed_jobs.append(asyncio.to_thread(graph_provider.embed, text_norm))
                embed_job_indices.append(idx)

            if embed_jobs:
                vectors = await asyncio.gather(*embed_jobs)
                for idx, vec in zip(embed_job_indices, vectors):
                    out_vectors[idx] = vec

            if filtered and os.environ.get("GRAPH_DEBUG", "").strip().lower() in ("1", "true", "yes"):
                logger.debug(
                    "graph:embed_many junk-filtered=%s/%s samples=%s",
                    filtered,
                    len(text_list),
                    filtered_samples,
                )

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
            display_content_stats=os.environ.get("GRAPH_DEBUG", "").strip().lower() in ("1", "true", "yes"),
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
                logger.info("LightRAG Neo4j env prepared: %s", {k: v for k, v in neo4j_env_applied.items()})
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

    def get_or_create_client(self, provider: Any, *, neo4j_database: str | None = None) -> Optional[Any]:
        cache_key = self.graph_cache_fingerprint(provider, neo4j_database=neo4j_database)
        with self._rag_graph_lock:
            if self.client is not None and self._rag_graph_cache_key == cache_key:
                return self.client

            if self.client is not None and self._rag_graph_cache_key != cache_key:
                self._close_raganything_instance(self.client)
                self.client = None
                self._rag_graph_cache_key = None

            async def _build_client() -> Optional[Any]:
                return self._init_client(provider, neo4j_database=neo4j_database)

            client = self._run_coro_sync(_build_client())
            self.client = client
            self._rag_graph_cache_key = cache_key if client is not None else None
            if client is not None and not self._rag_graph_kv_junk_scrub_once_done:
                try:
                    self.scrub_raganything_kv_graph_junk(full_scan=True)
                finally:
                    self._rag_graph_kv_junk_scrub_once_done = True
            return self.client

    def extract_triplets(
        self,
        text: str,
        *,
        provider: Any,
        chunks: List[str] | None,
        doc_id: str | None,
        file_path: str | None,
        neo4j_database: str | None,
    ) -> List[tuple[str, str, str]]:
        working_text = text
        cleaned_text = clean_graph_text(text)
        if not cleaned_text:
            working_text = text

        cleaned_chunks = None
        if chunks is not None:
            cleaned_chunks = []
            for ch in chunks:
                cleaned = clean_graph_text(ch)
                cleaned_chunks.append(cleaned if cleaned.strip() else ch)

        content_list = self.graph_content_list_from_input(
            cleaned_text if cleaned_text.strip() else working_text,
            cleaned_chunks if cleaned_chunks is not None else chunks,
        )
        if not content_list:
            logger.info("graph:extract_triplets skipping empty/tiny content doc_id=%s", doc_id or "-")
            return []

        if _env_truthy("GRAPH_RESET_CACHE_PER_DOC", True):
            cleared = self.clear_graph_cache()
            if cleared.get("failed"):
                logger.warning("RAG-Anything graph cache reset had failures: %s", cleared.get("failed"))
            else:
                logger.info(
                    "RAG-Anything graph cache reset before doc_id=%s removed=%s",
                    doc_id or "-",
                    len(cleared.get("removed") or []),
                )

        client = self.get_or_create_client(provider, neo4j_database=neo4j_database)
        if client is None:
            logger.warning("RAG-Anything graph client is not initialized; returning 0 triplets.")
            return []

        source_file_ref = str(file_path or f"inline://{doc_id or 'graph_text'}")
        file_ref = self.raganything_file_ref(doc_id, file_path)
        rag_doc_id = self.raganything_extraction_doc_id(doc_id, file_ref, content_list)
        logger.info(
            "RAG-Anything graph insert requested doc_id=%s rag_doc_id=%s file=%s source_file=%s blocks=%s chars=%s",
            doc_id or "-",
            rag_doc_id,
            file_ref,
            source_file_ref,
            len(content_list),
            sum(len(str(item.get('text') or '')) for item in content_list),
        )
        created_floor = int(time.time())

        async def _insert_and_export() -> List[tuple[str, str, str]]:
            self._clear_lightrag_neo4j_temp_graph(neo4j_database)
            text_only = bool(content_list) and all(
                isinstance(item, dict) and str(item.get("type") or "").lower() == "text"
                for item in content_list
            )
            if text_only:
                try:
                    if hasattr(client, "_parser_installation_checked"):
                        setattr(client, "_parser_installation_checked", True)
                except Exception:
                    pass
            try:
                if text_only:
                    init_result = await client._ensure_lightrag_initialized()
                    if not init_result or not init_result.get("success"):
                        raise RuntimeError(f"LightRAG initialization failed: {(init_result or {}).get('error', 'unknown error')}")
                    text_content = "\n\n".join(
                        str(item.get("text") or "").strip()
                        for item in content_list
                        if isinstance(item, dict) and str(item.get("text") or "").strip()
                    )
                    await client.lightrag.ainsert(
                        input=text_content,
                        ids=rag_doc_id,
                        file_paths=file_ref,
                    )
                else:
                    await client.insert_content_list(
                        content_list,
                        file_path=file_ref,
                        doc_id=rag_doc_id,
                        display_stats=os.environ.get("GRAPH_DEBUG", "").strip().lower() in ("1", "true", "yes"),
                    )
            except Exception as exc:
                logger.warning("RAG-Anything graph insert failed: %s", exc)
                return []

            self.scrub_raganything_kv_graph_junk(rag_doc_id=rag_doc_id)

            lightrag_obj = getattr(client, "lightrag", None)
            graph_obj = getattr(lightrag_obj, "chunk_entity_relation_graph", None)
            if graph_obj is None:
                logger.warning("RAG-Anything graph storage is unavailable after insert.")
                self._clear_lightrag_neo4j_temp_graph(neo4j_database)
                return []

            try:
                edges = await graph_obj.get_all_edges()
            except Exception as exc:
                logger.warning("RAG-Anything graph edge export failed: %s", exc)
                self._clear_lightrag_neo4j_temp_graph(neo4j_database)
                return []

            if not isinstance(edges, list):
                logger.warning(
                    "RAG-Anything graph edge export returned unexpected type=%s",
                    type(edges).__name__,
                )
                return []

            triplets = self.triplets_from_edges(
                edges=edges,
                file_ref=file_ref,
                created_at_floor=created_floor,
            )
            fallback_triplets = self.triplets_from_llm_cache()
            if fallback_triplets:
                triplets = _dedupe_triplets([*triplets, *fallback_triplets])
            self._clear_lightrag_neo4j_temp_graph(neo4j_database)
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
