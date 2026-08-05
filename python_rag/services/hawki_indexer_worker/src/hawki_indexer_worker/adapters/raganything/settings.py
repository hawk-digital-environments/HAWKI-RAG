"""Settings for RAG-Anything graph extraction behavior."""

from __future__ import annotations

import os
import re
from dataclasses import dataclass


DEFAULT_GRAPH_EMBEDDING_DIMENSIONS = (
    "hawki-ollama-embedding=1024,hawki-openai-embedding=1536,hawki-embedding=1024"
)


def _bool_env(name: str, default: bool = False) -> bool:
    value = str(os.environ.get(name, "")).strip().lower()
    if not value:
        return default
    return value in ("1", "true", "yes", "on")


def _int_env(name: str) -> int | None:
    raw = str(os.environ.get(name, "")).strip()
    if not raw:
        return None
    try:
        return int(raw)
    except ValueError:
        return None


def parse_optional_int(value: str | None) -> int | None:
    if value is None:
        return None
    raw = value.strip()
    if not raw:
        return None
    try:
        return int(raw)
    except ValueError:
        return None


def _resolve_neo4j_uri(uri: str, bolt_url: str, http_url: str) -> str:
    source_uri = (uri or "").strip()
    if source_uri:
        return source_uri
    if bolt_url:
        return bolt_url
    if not http_url:
        return ""

    bolt_like = re.sub(r"^https?://", "bolt://", http_url)
    bolt_like = re.sub(r":7474(?=/|$)", ":7687", bolt_like)
    return re.sub(r":7473(?=/|$)", ":7687", bolt_like)


@dataclass(frozen=True)
class RagAnythingGraphSettings:
    graph_perf_log: bool
    graph_debug: bool
    graph_debug_llm: bool
    graph_reset_cache_per_doc: bool
    graph_embed_junk_strict: bool
    graph_embed_junk_allowlist: str
    graph_embed_junk_denylist: str
    graph_temperature: str
    ollama_chat_timeout: str
    ollama_embed_nan_zero_fallback: bool
    graph_doc_max_chars: int | None
    graph_doc_max_chunks: int | None
    graph_min_chunk_chars: int | None
    graph_min_doc_chars: int | None
    graph_model: str
    vision_model: str
    embed_model: str
    graph_embedding_dimensions: str
    neo4j_uri: str
    neo4j_user: str
    neo4j_username: str
    neo4j_password: str
    neo4j_bolt_url: str
    neo4j_http_url: str
    neo4j_database: str


def load_raganything_graph_settings() -> RagAnythingGraphSettings:
    neo4j_uri = _resolve_neo4j_uri(
        os.environ.get("NEO4J_URI", "").strip(),
        os.environ.get("NEO4J_BOLT_URL", "").strip(),
        os.environ.get("NEO4J_HTTP_URL", "").strip(),
    )

    graph_model = os.environ.get("GRAPH_OLLAMA_RAG_MODEL", "").strip()
    if not graph_model:
        graph_model = os.environ.get("OLLAMA_RAG_MODEL", "").strip()

    vision_model = os.environ.get("GRAPH_OLLAMA_VISION_MODEL", "").strip()
    if not vision_model:
        vision_model = os.environ.get("OLLAMA_VISION_MODEL", "qwen2.5vl:7b").strip()

    custom_embedding_dimensions = os.environ.get(
        "GRAPH_EMBEDDING_DIMENSIONS",
        "",
    ).strip()
    graph_embedding_dimensions = DEFAULT_GRAPH_EMBEDDING_DIMENSIONS
    if custom_embedding_dimensions:
        graph_embedding_dimensions = (
            f"{DEFAULT_GRAPH_EMBEDDING_DIMENSIONS},{custom_embedding_dimensions}"
        )

    return RagAnythingGraphSettings(
        graph_perf_log=_bool_env("GRAPH_PERF_LOG"),
        graph_debug=_bool_env("GRAPH_DEBUG"),
        graph_debug_llm=_bool_env("GRAPH_DEBUG_LLM"),
        graph_reset_cache_per_doc=_bool_env("GRAPH_RESET_CACHE_PER_DOC", True),
        graph_embed_junk_strict=_bool_env("GRAPH_EMBED_JUNK_STRICT", True),
        graph_embed_junk_allowlist=os.environ.get(
            "GRAPH_EMBED_JUNK_ALLOWLIST", ""
        ).strip(),
        graph_embed_junk_denylist=os.environ.get(
            "GRAPH_EMBED_JUNK_DENYLIST", ""
        ).strip(),
        graph_temperature=os.environ.get("GRAPH_TEMPERATURE", "").strip(),
        ollama_chat_timeout=os.environ.get("OLLAMA_CHAT_TIMEOUT", "").strip(),
        ollama_embed_nan_zero_fallback=_bool_env(
            "OLLAMA_EMBED_NAN_ZERO_FALLBACK", True
        ),
        graph_doc_max_chars=_int_env("GRAPH_DOC_MAX_CHARS"),
        graph_doc_max_chunks=_int_env("GRAPH_DOC_MAX_CHUNKS"),
        graph_min_chunk_chars=_int_env("GRAPH_MIN_CHUNK_CHARS"),
        graph_min_doc_chars=_int_env("GRAPH_MIN_DOC_CHARS"),
        graph_model=graph_model,
        vision_model=vision_model,
        embed_model=os.environ.get("OLLAMA_EMBED_MODEL", "").strip(),
        graph_embedding_dimensions=graph_embedding_dimensions,
        neo4j_uri=neo4j_uri,
        neo4j_user=os.environ.get("NEO4J_USER", "").strip(),
        neo4j_username=os.environ.get("NEO4J_USERNAME", "").strip(),
        neo4j_password=os.environ.get("NEO4J_PASSWORD", "").strip(),
        neo4j_bolt_url=os.environ.get("NEO4J_BOLT_URL", "").strip(),
        neo4j_http_url=os.environ.get("NEO4J_HTTP_URL", "").strip(),
        neo4j_database=os.environ.get("NEO4J_DATABASE", "").strip(),
    )
