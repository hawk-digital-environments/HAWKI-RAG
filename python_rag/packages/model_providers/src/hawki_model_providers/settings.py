from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _env_bool(name: str, default: bool = False) -> bool:
    value = str(os.environ.get(name, "")).strip().lower()
    if not value:
        return default
    return value in ("1", "true", "yes", "on")


def _env_path(name: str, default: str) -> Path:
    return Path(os.environ.get(name, default)).expanduser()


@dataclass(frozen=True)
class RAGServiceSettings:
    rag_working_dir: Path
    graph_debug: bool
    graph_debug_llm: bool
    graph_perf_log: bool
    graph_provider: str


def load_rag_settings() -> RAGServiceSettings:
    return RAGServiceSettings(
        rag_working_dir=_env_path("RAG_WORKING_DIR", "/app/rag_storage"),
        graph_debug=_env_bool("GRAPH_DEBUG"),
        graph_debug_llm=_env_bool("GRAPH_DEBUG_LLM"),
        graph_perf_log=_env_bool("GRAPH_PERF_LOG"),
        graph_provider=(os.environ.get("GRAPH_PROVIDER", "ollama") or "ollama")
        .strip()
        .lower(),
    )
